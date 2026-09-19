<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Laravel\Contracts\SyncableModel;
use Cbox\Sync\Laravel\Exceptions\SyncConflict;
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
     * @param  array<string, mixed>  $changed  Field name => the value now stored
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

        $operations = [];
        $synced = array_flip($model->syncFields());
        foreach ($changed as $field => $value) {
            if (isset($synced[$field])) {
                $operations[] = FieldOperation::set((string) $field, $value);
            }
        }
        if ($operations === [] && ! $deleting) {
            return;
        }

        // The log decides whether this is a create, not the model. A model
        // instance reports wasRecentlyCreated for its whole life, and a row that
        // predates sync - or was written around it - has no record at all yet.
        $current = $this->store->record($entity);
        $kind = match (true) {
            $deleting => MutationKind::Delete,
            $current === null || $current->deleted => MutationKind::Create,
            default => MutationKind::Update,
        };
        if ($deleting && ($current === null || $current->deleted)) {
            return;
        }

        $replica = new Replica('server');

        // A base version the caller supplied is what turns an ordinary write
        // into a checked one. Without it the base is whatever is stored now,
        // which is the truthful statement that this write knows the current
        // state - and a write that knows the current state cannot conflict.
        $stored = $current?->version->value ?? 0;
        $base = $kind === MutationKind::Create ? 0 : ($this->claimedBase() ?? $stored);

        $result = $this->engine->process(
            new Mutation(
                'server-'.bin2hex(random_bytes(16)),
                $entity,
                $replica,
                new MutationSequence($this->store->acknowledged($entity->space, $replica) + 1),
                $kind,
                new RecordVersion($base),
                $operations,
            ),
            new AdapterContext($this->actor()),
        );

        if ($result->status === MutationStatus::Conflict || $result->status === MutationStatus::PreconditionFailed) {
            throw SyncConflict::from($result);
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
     * The version the caller says it was looking at, if it said.
     *
     * Read off the request rather than asked for as an argument, so an existing
     * controller keeps its shape: a client that knows about versions sends one
     * and gets conflict detection, and one that does not gets the ordinary
     * last-write behaviour it has always had.
     */
    private function claimedBase(): ?int
    {
        $request = $this->request();
        if ($request === null) {
            return null;
        }

        $etag = $request->headers->get('If-Match');
        if (is_string($etag) && ctype_digit(trim($etag, '"'))) {
            return (int) trim($etag, '"');
        }

        $body = $request->input('base_version');

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
