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
use Cbox\Sync\Engine;
use Cbox\Sync\Laravel\Api\Contracts\PersistsRecords;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\Exceptions\UnknownSyncableType;
use Cbox\Sync\Laravel\Api\Support\FieldValueCodec;
use Cbox\Sync\Laravel\Api\Support\MutationMapper;
use Cbox\Sync\Laravel\Api\Support\Payload;
use Cbox\Sync\Laravel\Api\Support\ResultMapper;
use Cbox\Sync\Laravel\Api\Support\ViewMapper;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\IlluminateStore;
use Cbox\Sync\Observers\NullCommitObserver;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Contracts\Config\Repository;
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
        $space = $type->space($principal, Payload::optionalString($body, 'scope'));

        $mutation = MutationMapper::fromWire(
            $body, $principal, $type->entityType(), $space,
            $type->writableFields($principal),
            $this->setting('api.max_operations', 64),
        );

        // A mutation this server has already processed is answered from its
        // receipt, whatever authorization says now.
        //
        // Otherwise a retry after a lost response can be refused by a policy
        // that reads the record - deleting a row and then being denied because
        // the row is deleted. The write already happened; denying the ANSWER
        // does not undo it, it only strands the client, which abandons the
        // mutation without advancing its acknowledgement and then has every
        // later write rejected for reusing a sequence. One lost response would
        // wedge the device permanently.
        $alreadyProcessed = $this->store->receipt($mutation->id) !== null;

        // Before the engine, always. A mutation id that reaches it is
        // acknowledged forever, so a refusal afterwards would leave the client
        // unable to retry that id ever again.
        if (! $alreadyProcessed && ! $type->mayWrite($principal, $this->store->record($mutation->entity), $mutation->kind)) {
            throw SyncRequestRejected::forbidden();
        }

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
        $apply = function () use ($engine, $mutation, $principal, $type): MutationResult {
            $result = $engine->process($mutation, new AdapterContext($principal->id, $principal->integrationId));
            if ($type instanceof PersistsRecords) {
                $settled = $this->store->record($mutation->entity);
                if ($settled !== null) {
                    $settled->deleted ? $type->forget($settled) : $type->persist($settled);
                }
            }

            return $result;
        };

        $result = $type instanceof PersistsRecords && $this->store instanceof IlluminateStore
            ? $this->store->databaseConnection()->transaction($apply)
            : $apply();

        // Whether the caller may see this row at all, judged on the canonical
        // record after the write. A field whitelist bounds columns; only the
        // view bounds rows.
        $canonical = $this->store->record($mutation->entity);
        $rowIsReadable = $canonical !== null
            && ! $canonical->deleted
            && $type->mayRead($principal, Payload::optionalString($body, 'scope'))
            && $type->view($principal, Payload::optionalString($body, 'scope'))->includes($canonical);

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
        $identity = ['id' => $mutation->entity->id];
        $handle = Payload::string($body, 'id');
        if ($handle !== $mutation->entity->id) {
            $identity['temp_id'] = $handle;
        }

        return $identity + ResultMapper::toWire($result, $type->readableFields($principal), $groups, $rowIsReadable);
    }

    public function bootstrap(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $scope = Payload::optionalString($body, 'scope');
        $type = $this->readableType($body, $principal, $scope);

        $view = $type->view($principal, $scope);
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

        $view = $type->view($principal, $scope);
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
