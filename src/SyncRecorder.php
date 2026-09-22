<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Laravel\Contracts\SyncableModel;
use Cbox\Sync\Laravel\Exceptions\SyncConflict;
use Cbox\Sync\Laravel\Exceptions\SyncRejected;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Turns an ordinary model save into a mutation, so the log knows about it.
 *
 * An edit made anywhere in the application - an admin screen, a console
 * command, a job - has to reach the devices, and the only way it can is by
 * being in the log. A write that skips it is invisible to every offline
 * client, for ever: they are following a sequence it never appeared in.
 *
 * This is the trusted path. The application already decided it may make this
 * change, so no policy is consulted; the API path is where a client's write is
 * authorized. And it cannot conflict: the base version is whatever is stored
 * right now, which is the truthful statement that this write knows the current
 * state and is not competing with an older view of it.
 */
class SyncRecorder
{
    public function __construct(
        private readonly Store $store,
        private readonly Engine $engine,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * How many times a write that lost a race for the server's sequence is
     * tried again. Every trusted write shares one replica stream, and the
     * number is read before the engine takes the space lock, so two writers can
     * pick the same one; the loser stored nothing and simply goes again.
     */
    private const ATTEMPTS = 3;

    /**
     * @param  list<string>  $changed  the fields this write set
     *
     * @throws SyncConflict when the caller named a version that has moved
     * @throws SyncRejected when the engine refused the write outright
     */
    public function record(Model $model, bool $deleting = false, array $changed = []): void
    {
        if (! $model instanceof SyncableModel && ! method_exists($model, 'syncEntityType')) {
            return;
        }

        /** @var Model&SyncableModel $model */
        $entity = $this->keyFor($model);
        if ($entity === null) {
            return;
        }

        $fields = array_values(array_intersect($changed, $model->syncFields()));
        if ($fields === [] && ! $deleting) {
            return;
        }
        $operations = [];
        foreach ($model->syncValues($fields) as $field => $value) {
            $operations[] = FieldOperation::set($field, $value);
        }

        $expected = $this->expectedVersion();
        $claimed = $expected ?? $this->claimedBase();
        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $this->attempt($entity, $deleting, $operations, $claimed, $expected);
            } catch (ProtocolException $collided) {
                // Another trusted write took this sequence number between the
                // read and the lock. Nothing was stored for this one.
                if ($attempt >= self::ATTEMPTS) {
                    throw $collided;
                }

                continue;
            }
            if ($result === null) {
                return;
            }

            $status = $result->status;
            $raced = $status === MutationStatus::MutationGap
                // A write with no claimed version means "I know the current
                // state"; if it conflicts anyway, the state moved between the
                // read and the lock, and a fresh read settles it.
                || ($claimed === null && $status === MutationStatus::Conflict);
            if ($raced && $attempt < self::ATTEMPTS) {
                continue;
            }

            match ($status) {
                MutationStatus::Conflict, MutationStatus::PreconditionFailed => throw SyncConflict::from($result),
                MutationStatus::Rejected, MutationStatus::ValidationFailed, MutationStatus::MutationGap => throw SyncRejected::from($result),
                default => null,
            };

            return;
        }
    }

    /** @param list<FieldOperation> $operations */
    private function attempt(EntityKey $entity, bool $deleting, array $operations, ?int $claimed, ?int $expected): ?MutationResult
    {
        // The log decides whether this is a create, not the model: a row that
        // predates sync - or was written around it - has no record yet.
        $current = $this->store->record($entity);
        if ($deleting && ($current === null || $current->deleted)) {
            return null;
        }
        $kind = match (true) {
            $deleting => MutationKind::Delete,
            $current === null => MutationKind::Create,
            default => MutationKind::Update,
        };

        $replica = new Replica('server');

        // A base version the caller supplied is what turns an ordinary write
        // into a checked one. Without it the base is whatever is stored now,
        // which is the truthful statement that this write knows the current
        // state - and a write that knows the current state cannot conflict.
        $stored = $current?->version->value ?? 0;
        $base = $kind === MutationKind::Create ? 0 : ($claimed ?? $stored);

        return $this->engine->process(
            new Mutation(
                'server-'.bin2hex(random_bytes(16)),
                $entity,
                $replica,
                new MutationSequence($this->store->acknowledged($entity->space, $replica) + 1),
                $kind,
                new RecordVersion($base),
                $kind === MutationKind::Delete ? [] : $operations,
                expectedVersion: $kind === MutationKind::Create || $expected === null ? null : new RecordVersion($expected),
            ),
            new AdapterContext($this->actor()),
        );
    }

    /** @param Model&SyncableModel $model */
    private function keyFor(Model $model): ?EntityKey
    {
        $id = $model->getKey();
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $type = $model->syncEntityType();
        $column = $model->syncScopeColumn();
        if ($column === null) {
            return new EntityKey($type, $type, (string) $id);
        }

        $scope = $model->getAttribute($column);
        if (! is_string($scope) && ! is_int($scope)) {
            return null;
        }

        return new EntityKey($type.':'.$scope, $type, (string) $id);
    }

    /**
     * If-Match, as HTTP means it: the write happens only if the record is still
     * at this version, otherwise 412. It is the whole-record precondition a
     * REST client asks for when it sends an ETag back - not a merge.
     */
    private function expectedVersion(): ?int
    {
        $etag = $this->request()?->headers->get('If-Match');
        if (! is_string($etag)) {
            return null;
        }
        $version = trim(str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag, '"');

        return ctype_digit($version) ? (int) $version : null;
    }

    /**
     * The version the caller says it was looking at, sent as base_version.
     *
     * Read off the request rather than asked for as an argument, so an existing
     * controller keeps its shape: a client that knows about versions sends one
     * and gets field-level conflict detection - edits to fields nobody else
     * touched still merge - and one that does not gets the ordinary last-write
     * behaviour it has always had.
     */
    private function claimedBase(): ?int
    {
        $body = $this->request()?->input('base_version');

        return is_int($body) || (is_string($body) && ctype_digit($body)) ? (int) $body : null;
    }

    private function request(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : null;
    }

    /** Who made the change, when there is anyone to name. A job or a command has nobody. */
    private function actor(): ?string
    {
        $identifier = $this->auth->guard()->user()?->getAuthIdentifier();

        return is_string($identifier) || is_int($identifier) ? (string) $identifier : null;
    }
}
