<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Enums\OnConflict;
use Cbox\Sync\Laravel\Contracts\SyncableModel;
use Cbox\Sync\Laravel\Exceptions\SyncConflict;
use Cbox\Sync\Laravel\Exceptions\SyncRejected;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
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
        private readonly Engine $engine,
        private readonly AuthFactory $auth,
    ) {}

    /** The device write whose read-back is being recorded, while it is. */
    private ?string $echoing = null;

    /**
     * Record what follows as the table's echo of a device's write: its
     * versions become that write's own, so the device's next edit does not
     * conflict with it.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function echoing(string $mutationId, \Closure $callback): mixed
    {
        $previous = $this->echoing;
        $this->echoing = $mutationId;
        try {
            return $callback();
        } finally {
            $this->echoing = $previous;
        }
    }

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
        // Everything, if the log turns out never to have held this record: a
        // row that existed before the model was synced would otherwise be
        // logged as the one field its first save changed, and devices would
        // get it without the rest.
        $whole = [];
        foreach ($model->syncValues($model->syncFields()) as $field => $value) {
            $whole[] = FieldOperation::set($field, $value);
        }

        // A version the caller named applies to the model it was talking
        // about - the one the route bound - and to nothing else saved while
        // handling the request.
        $concerned = $this->isRouteModel($model);
        // A request's precondition is about the record as the request found
        // it. Once it held for one save, the request's later saves of the same
        // record are its own work, not a race - checking again answered 412
        // with the first save already committed.
        $marker = 'sync.precondition_held.'.$model::class.':'.$entity->id;
        $held = is_array($this->request()?->attributes->get($marker));
        $ifMatch = $concerned && ! $held ? $this->ifMatch() : null;
        $base = $concerned && ! $held ? $this->claimedBase() : null;

        // Create or update, the base and the stream position are all decided
        // inside the space lock. Deciding them here raced every other save to
        // the same tenant, and under MySQL's REPEATABLE READ a host
        // transaction kept every retry reading the same stale position.
        $result = $this->engine->recordTrusted(
            $entity,
            new Replica('server'),
            $operations,
            $deleting,
            new AdapterContext($this->actor()),
            $ifMatch,
            $base,
            // Never preserve a conflict from here. The table is being written
            // in the same transaction, and a request that lost is answered 409
            // with nothing kept - not with a candidate waiting in a group for a
            // choice nobody will be asked to make.
            OnConflict::Pull,
            $deleting ? null : $whole,
            $this->echoing,
        );
        if ($result === null) {
            return;
        }
        if (in_array(ConflictDecision::Server, $result->decisions, true)) {
            // The resolver kept the stored value over this save's - the table
            // is about to hold the value that lost. Answered as the conflict it
            // is, and the save rolls back with it.
            throw SyncConflict::from($result);
        }

        match ($result->status) {
            MutationStatus::Conflict, MutationStatus::PullRequired, MutationStatus::PreconditionFailed => throw SyncConflict::from($result),
            MutationStatus::Rejected, MutationStatus::ValidationFailed, MutationStatus::MutationGap, MutationStatus::ReceiptPruned => throw SyncRejected::from($result),
            default => null,
        };
        if ($concerned && ($ifMatch !== null || $base !== null)) {
            // Only while this save stands: the transaction level it lives at,
            // moved down as levels commit and dropped when one below it rolls
            // back - a deadlock the host retries has to meet the precondition
            // again, against whatever committed in between. Tracked from the
            // connection's own events, which fire at every level on every
            // supported Laravel; a rollback callback registered in a nested
            // transaction that had already committed never ran on Laravel 12.
            $connection = $model->getConnection();
            $this->request()?->attributes->set($marker, [$connection->getName(), $connection->transactionLevel()]);
        }
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

    /**
     * A transaction level on this connection committed or rolled back: what
     * lived in it now lives in its parent, or is gone with it.
     *
     * Static, and on the current request, because the state is the request's:
     * under Octane the recorder may exist only in the request's own container,
     * and a check for it in the application's missed it, so a precondition met
     * in a transaction that rolled back stayed met.
     */
    public static function settle(?Request $request, ConnectionInterface $connection, bool $keep): void
    {
        if ($request === null || ! $connection instanceof Connection) {
            return;
        }
        $level = $connection->transactionLevel();
        foreach ($request->attributes->all() as $key => $held) {
            if (! str_starts_with($key, 'sync.precondition_held.') || ! is_array($held) || ($held[0] ?? null) !== $connection->getName()) {
                continue;
            }
            if (($held[1] ?? 0) > $level) {
                $keep ? $request->attributes->set($key, [$held[0], $level]) : $request->attributes->remove($key);
            }
        }
    }

    /**
     * A transaction began at this level, so whatever last lived at it ended -
     * whether or not anything said so. A COMMIT that fails on a concurrency
     * error is rolled back and retried by Laravel without a rolled-back event,
     * and the retry skipped the precondition its first attempt had met.
     */
    public static function began(?Request $request, ConnectionInterface $connection): void
    {
        if ($request === null || ! $connection instanceof Connection) {
            return;
        }
        $level = $connection->transactionLevel();
        foreach ($request->attributes->all() as $key => $held) {
            if (str_starts_with($key, 'sync.precondition_held.') && is_array($held) && ($held[0] ?? null) === $connection->getName() && ($held[1] ?? 0) >= $level) {
                $request->attributes->remove($key);
            }
        }
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
