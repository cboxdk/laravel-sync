<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\IdGenerator;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Enums\OnConflict;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Laravel\Api\Contracts\NormalizesValues;
use Cbox\Sync\Laravel\Api\Contracts\PersistsRecords;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\Exceptions\UnknownSyncableType;
use Cbox\Sync\Laravel\Api\Support\BoundView;
use Cbox\Sync\Laravel\Api\Support\FieldValueCodec;
use Cbox\Sync\Laravel\Api\Support\IdentityBinding;
use Cbox\Sync\Laravel\Api\Support\MutationMapper;
use Cbox\Sync\Laravel\Api\Support\Payload;
use Cbox\Sync\Laravel\Api\Support\ResultMapper;
use Cbox\Sync\Laravel\Api\Support\ViewMapper;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\IlluminateStore;
use Cbox\Sync\Laravel\SyncRecorder;
use Cbox\Sync\Observers\NullCommitObserver;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Identifier;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;

class SyncService implements SyncEndpoints
{
    public function __construct(
        private readonly Store $store,
        private readonly ConflictResolver $resolver,
        private readonly IdGenerator $ids,
        private readonly EntityValidator $validator,
        private readonly ViewSyncService $views,
        private readonly SyncableTypes $types,
        private readonly Repository $config,
        private readonly CommitObserver $observer = new NullCommitObserver,
    ) {}

    public function push(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $type = $this->type($body);

        // A mutation this server has already processed is answered from its
        // receipt, before anything about the caller's CURRENT permissions is
        // consulted - the scope, the writable fields, the policy.
        //
        // Otherwise a retry after a lost response can be refused by a rule that
        // changed in between: a field no longer writable, a policy that reads
        // the record and denies because the row is now deleted. The write
        // already happened; denying the ANSWER does not undo it, it only
        // strands the client, which cannot advance its acknowledgement and has
        // every later write refused for reusing a sequence. One lost response
        // would wedge the device for good. The id is bound to the principal, so
        // only the caller that made the write can reach its receipt.
        $clientMutationId = Payload::string($body, 'mutation_id');
        Identifier::check($clientMutationId, 'mutation_id');
        $receipt = $this->store->receipt(IdentityBinding::mutationId($principal, $clientMutationId));
        if ($receipt !== null) {
            return $this->fromReceipt($receipt, $body, $principal, $type);
        }

        $space = $type->space($principal, Payload::optionalString($body, 'scope'));
        $mutation = MutationMapper::fromWire(
            $body, $principal, $type->entityType(), $space,
            $type->writableFields($principal),
            $this->setting('api.max_operations', 64),
        );

        if ($type instanceof NormalizesValues) {
            $mutation = $type->normalize($mutation);
        }

        // Before the engine, always. A mutation id that reaches it is
        // acknowledged forever, so a refusal afterwards would leave the client
        // unable to retry that id ever again.
        //
        // Except for a position the stream has already used: the engine
        // answers that without applying anything - a replay whose receipt was
        // pruned, a writer that fell behind - and only its answer tells the
        // device where to go on. Refused here by a rule that changed since, the
        // device never learned it, and its next write reused the position.
        $used = $mutation->sequence->value <= $this->store->acknowledged($space, $mutation->replica);
        if (! $used && ! $type->mayWrite($principal, $this->store->record($mutation->entity), $mutation->kind)) {
            throw SyncRequestRejected::forbidden();
        }
        $this->assertOneConnection($type);

        // The engine is built per request so its validator can re-check this
        // caller's authorization inside the transaction, against the same
        // locked record the write merges into.
        $engine = new Engine(
            $this->store,
            $this->resolver,
            $this->ids,
            new AuthorizesInsideTransaction($this->validator, $type, $principal),
            $this->observer,
        );
        // The engine and the host's own table commit together or not at all.
        // IlluminateStore turns the engine's transaction into a savepoint under
        // this one, so a failure writing the row takes the mutation back with
        // it - the alternative is a table that disagrees with the log, which is
        // worse than either being wrong alone.
        $onConflict = self::onConflict($body);
        $apply = function () use ($engine, $mutation, $principal, $type, $onConflict): MutationResult {
            $submission = $engine->submit($mutation, new AdapterContext($principal->id, $principal->integrationId), $onConflict);
            $result = $submission->result;
            // Only a write that changed the record reaches the table, and only
            // from the call that wrote it. A noop, a conflict or a refusal left
            // it as it was; a replay racing the first delivery found its
            // receipt inside the lock, and writing the table again ran the
            // application's observers twice for one write.
            if ($type instanceof PersistsRecords && $submission->wrote() && in_array($result->status, [MutationStatus::Applied, MutationStatus::Partial], true)) {
                $settled = $this->store->record($mutation->entity);
                if ($settled !== null) {
                    // What the table makes of it is recorded as this write's
                    // echo, and its versions become part of this write's answer.
                    app(SyncRecorder::class)->echoing($mutation->id, fn () => $settled->deleted ? $type->forget($settled) : $type->persist($settled));
                    $result = $this->store->receipt($mutation->id)->result ?? $result;
                }
            }

            return $result;
        };

        // Captured by reference rather than returned through transaction(),
        // whose return type is mixed on Laravel 12 and generic on 13.
        $result = null;
        $run = function () use ($apply, &$result): void {
            $result = $apply();
        };
        if ($type instanceof PersistsRecords && $this->store instanceof IlluminateStore) {
            $this->store->readCommitted();
            $this->store->databaseConnection()->transaction($run);
        } else {
            $run();
        }
        if ($result === null) {
            throw new \LogicException('The sync transaction completed without a result.');
        }

        return $this->answer($result, $mutation->entity, Payload::string($body, 'id'), $body, $principal, $type);
    }

    /**
     * A replay: the stored answer, told to the caller under TODAY's disclosure
     * rules.
     *
     * The receipt is only honoured for the same stream and position it was
     * made at. Anything else reusing the id is a client bug the engine would
     * refuse as a protocol violation, and so does this.
     *
     * @return array<string, mixed>
     */
    private function fromReceipt(Receipt $receipt, \stdClass $body, SyncPrincipal $principal, SyncableType $type): array
    {
        $stored = $receipt->mutation;
        if ($stored->entity->type !== $type->entityType()
            || $stored->replica->id !== IdentityBinding::replica($principal, Payload::string($body, 'replica'))->id
            || $stored->sequence->value !== Payload::int($body, 'sequence')) {
            throw new ProtocolException('Mutation identity reused with different content');
        }

        return $this->answer($receipt->result, $stored->entity, Payload::string($body, 'id'), $body, $principal, $type);
    }

    /** @return array<string, mixed> */
    private function answer(MutationResult $result, EntityKey $entity, string $handle, \stdClass $body, SyncPrincipal $principal, SyncableType $type): array
    {
        // Whether the caller may see this row at all, judged on the canonical
        // record after the write. A field whitelist bounds columns; only the
        // view bounds rows.
        $scope = Payload::optionalString($body, 'scope');
        $canonical = $this->store->record($entity);
        $rowIsReadable = $canonical !== null
            && ! $canonical->deleted
            && $type->mayRead($principal, $scope)
            && $type->view($principal, $scope)->includes($canonical);

        $groups = [];
        foreach ($result->conflictGroupIds as $id) {
            $group = $this->store->group($id);
            if ($group !== null) {
                $groups[$id] = $group;
            }
        }

        // The device sent a handle it made up; this is the name the record
        // has. Echoing the handle back is what lets the device find the row it
        // created and rewrite anything still queued against it.
        $identity = ['id' => $entity->id];
        if ($handle !== $entity->id) {
            $identity['temp_id'] = $handle;
        }

        return $identity + ResultMapper::toWire($result, $type->readableFields($principal), $groups, $rowIsReadable);
    }

    /**
     * The row and the log have to commit together, and a transaction spans one
     * connection. A model on a different connection from the sync store would
     * keep its row when the log rolled back, or the other way round - so that
     * configuration is refused rather than quietly made non-atomic.
     */
    private function assertOneConnection(SyncableType $type): void
    {
        if (! $type instanceof ModelSyncableType || ! $this->store instanceof IlluminateStore) {
            return;
        }
        $connection = $this->store->databaseConnection();
        if (! $connection instanceof Connection) {
            return;
        }
        $model = $type->connectionName();
        $default = $this->config->get('database.default');
        $model ??= is_string($default) ? $default : null;
        $store = $connection->getName();
        if ($model !== null && $store !== null && $model !== $store) {
            throw new \LogicException(sprintf(
                'The %s model is on connection "%s" but the sync store is on "%s". They must share one, or a write can land in one and not the other. Set sync.connection to "%s".',
                $type->entityType(), $model, $store, $model,
            ));
        }
    }

    /**
     * How the writer wants a conflict handled. Absent means the server's
     * resolver decides, which is what every client before this field did.
     */
    private static function onConflict(\stdClass $body): OnConflict
    {
        $value = Payload::optionalString($body, 'on_conflict');
        if ($value === null) {
            return OnConflict::Resolve;
        }

        return OnConflict::tryFrom($value)
            ?? throw new SyncRequestRejected('on_conflict must be "resolve" or "pull"', 'invalid_request');
    }

    public function bootstrap(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $scope = Payload::optionalString($body, 'scope');
        $type = $this->readableType($body, $principal, $scope);

        $view = BoundView::to($type->view($principal, $scope), $principal);
        $context = $this->views->context($type->space($principal, $scope), $view);

        $token = Payload::optionalString($body, 'token');
        $page = $this->views->bootstrap($context, $view, $token !== null
            ? new BootstrapToken($token)
            : $this->views->openBootstrap($context, $view, $this->pageSize($body)));

        return ViewMapper::bootstrapToWire($page, $type->readableFields($principal));
    }

    public function delta(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $scope = Payload::optionalString($body, 'scope');
        $type = $this->readableType($body, $principal, $scope);

        $view = BoundView::to($type->view($principal, $scope), $principal);
        $context = $this->views->context($type->space($principal, $scope), $view);
        $cursor = ViewMapper::cursorFromWire($body, $context);

        $budget = min(Payload::optionalInt($body, 'limit') ?? 100, $this->setting('api.max_commits', 500));
        $page = $this->views->delta($cursor, $view, max(1, $budget));

        return ViewMapper::deltaToWire($page, $type->readableFields($principal));
    }

    private function readableType(\stdClass $body, SyncPrincipal $principal, ?string $scope): SyncableType
    {
        $type = $this->type($body);
        if (! $type->mayRead($principal, $scope)) {
            throw SyncRequestRejected::forbidden();
        }

        return $type;
    }

    private function type(\stdClass $body): SyncableType
    {
        $name = Payload::string($body, 'type');
        try {
            return $this->types->get($name);
        } catch (UnknownSyncableType) {
            throw SyncRequestRejected::unknownType($name);
        }
    }

    /**
     * The page a bootstrap serves.
     *
     * A client that asks for a size gets it, bounded by max_page_size. One that
     * asks for nothing gets the host's configured default rather than a literal
     * buried here - which is what sync.bootstrap.page_size is for, and it was
     * read nowhere, so an operator who set it saw nothing change.
     */
    private function pageSize(\stdClass $body): int
    {
        $requested = Payload::optionalInt($body, 'page_size') ?? $this->setting('bootstrap.page_size', 100);

        return max(1, min($requested, $this->setting('api.max_page_size', 500)));
    }

    /** @param string $key A path under `sync.`, so a reader is not confined to one section. */
    private function setting(string $key, int $default): int
    {
        $value = $this->config->get('sync.'.$key);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
