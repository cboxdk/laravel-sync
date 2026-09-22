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
use Cbox\Sync\Enums\OnConflict;
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
use Illuminate\Routing\Route;

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

        // A version the caller named applies to the model it was talking
        // about - the one the route bound - and to nothing else saved while
        // handling the request.
        $concerned = $this->isRouteModel($model);
        $ifMatch = $concerned ? $this->ifMatch() : null;
        $base = $concerned ? $this->claimedBase() : null;

        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $this->attempt($entity, $deleting, $operations, $ifMatch, $base);
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
            // A write that named no version means "I know the current state".
            // Refused anyway, the state moved between the read and the lock,
            // and a fresh read settles it. Pull rather than preserve, so the
            // race leaves no conflict group behind.
            $raced = $status === MutationStatus::MutationGap
                || ($ifMatch === null && $base === null && $status === MutationStatus::PullRequired);
            if ($raced && $attempt < self::ATTEMPTS) {
                continue;
            }

            match ($status) {
                MutationStatus::Conflict, MutationStatus::PullRequired, MutationStatus::PreconditionFailed => throw SyncConflict::from($result),
                MutationStatus::Rejected, MutationStatus::ValidationFailed, MutationStatus::MutationGap => throw SyncRejected::from($result),
                default => null,
            };

            return;
        }
    }

    /**
     * @param  list<FieldOperation>  $operations
     * @param  list<int>|null  $ifMatch  null when no precondition was asked for
     */
    private function attempt(EntityKey $entity, bool $deleting, array $operations, ?array $ifMatch, ?int $base): ?MutationResult
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
        $stored = $current?->version->value ?? 0;

        // If-Match lists the versions the caller will accept; the write goes
        // ahead only if the record is at one of them. A header that names no
        // version it can be matched against fails rather than being ignored.
        $expected = null;
        if ($ifMatch !== null && $kind !== MutationKind::Create) {
            $expected = in_array($stored, $ifMatch, true) ? $stored : ($ifMatch[0] ?? 0);
        }

        // A base version is what turns an ordinary write into a checked one.
        // Without it the base is whatever is stored now - the truthful
        // statement that this write knows the current state.
        $baseVersion = $kind === MutationKind::Create ? 0 : ($expected ?? $base ?? $stored);

        return $this->engine->process(
            new Mutation(
                'server-'.bin2hex(random_bytes(16)),
                $entity,
                $replica,
                new MutationSequence($this->store->acknowledged($entity->space, $replica) + 1),
                $kind,
                new RecordVersion(min($baseVersion, $stored)),
                $kind === MutationKind::Delete ? [] : $operations,
                expectedVersion: $expected === null ? null : new RecordVersion($expected),
            ),
            new AdapterContext($this->actor()),
            // Never preserve a conflict from here. The table is being written
            // in the same transaction, and a request that lost is answered 409
            // with nothing kept - not with a candidate waiting in a group for a
            // choice nobody will be asked to make.
            OnConflict::Pull,
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
     * If-Match, as HTTP means it: the versions the caller accepts, as a list.
     *
     * Null when there is no header or it is "*" - any current version will
     * do. An empty list when the header names nothing this can compare, which
     * fails the write: ignoring a precondition the caller asked for would do
     * exactly what it asked us not to.
     *
     * @return list<int>|null
     */
    private function ifMatch(): ?array
    {
        $header = $this->request()?->headers->get('If-Match');
        if (! is_string($header) || trim($header) === '' || trim($header) === '*') {
            return null;
        }

        $versions = [];
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            $tag = trim(str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag, '"');
            if (ctype_digit($tag)) {
                $versions[] = (int) $tag;
            }
        }

        return $versions;
    }

    /** Whether this is the model the current route is about. */
    private function isRouteModel(Model $model): bool
    {
        $route = $this->request()?->route();
        if (! $route instanceof Route) {
            return false;
        }
        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model && $parameter::class === $model::class && $parameter->getKey() === $model->getKey()) {
                return true;
            }
        }

        return false;
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
