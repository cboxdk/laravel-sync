<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\NormalizesValues;
use Cbox\Sync\Laravel\Api\Contracts\PersistsRecords;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\Support\PolicyView;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\Contracts\SyncableModel;
use Cbox\Sync\Laravel\Syncable;
use Cbox\Sync\Laravel\SyncRecorder;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\Views\EntityTypeView;
use Cbox\Sync\Views\ViewDefinition;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Serves an Eloquent model over the sync API using the application's own policy.
 *
 * The seven decisions the protocol needs are answered from two places a Laravel
 * application already has: the model, for what a row is and where it lives, and
 * the Gate, for who may touch it. Nothing is declared twice, so nothing can
 * disagree.
 *
 * The policy is called with a model carrying the record as SYNC holds it, not
 * as the table holds it. Those can differ for as long as a write is in flight,
 * and the one the engine is about to merge into is the one the decision has to
 * be made against.
 */
class ModelSyncableType implements NormalizesValues, PersistsRecords, SyncableType
{
    /** @var Model&SyncableModel */
    private Model $prototype;

    /** @param class-string<Model> $model */
    public function __construct(
        private readonly string $model,
        private readonly Gate $gate,
        private readonly AuthFactory $auth,
    ) {
        $instance = new $this->model;
        if (! $instance instanceof SyncableModel && ! method_exists($instance, 'syncEntityType')) {
            throw new \LogicException(sprintf(
                '%s is registered for sync but does not use the %s trait.',
                $this->model,
                Syncable::class,
            ));
        }
        if ($instance->getIncrementing()) {
            throw new \LogicException(sprintf(
                '%s uses an auto-incrementing key, which cannot sync: sync names a new record from the mutation '
                .'that created it, before the row is inserted, so a replay of that mutation lands on the same id. '
                .'Set $incrementing = false and $keyType = "string".',
                $this->model,
            ));
        }

        /** @var Model&SyncableModel $instance */
        $this->prototype = $instance;
    }

    public function entityType(): string
    {
        return $this->prototype->syncEntityType();
    }

    /**
     * The tenant, namespaced by entity type so two models cannot collide on the
     * same tenant id.
     *
     * The scope a client sends is a selector, never the space itself: taking it
     * at face value would let the client pick which tenant it reads. It is
     * checked against what this principal is actually allowed before it is used.
     */
    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        $column = $this->prototype->syncScopeColumn();
        if ($column === null) {
            // No tenancy column: one shared space, and the Gate is the only
            // boundary. Correct for a single-tenant application.
            return $this->entityType();
        }

        $allowed = $this->allowedScopes($principal);
        if ($scope === null || ! in_array($scope, $allowed, true)) {
            throw SyncRequestRejected::forbidden();
        }

        return $this->entityType().':'.$scope;
    }

    /**
     * Every live row of the type in the tenant - narrowed by the policy's
     * `view` rule when it has one, so a row the policy hides is not synced.
     */
    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        $view = EntityTypeView::of($this->entityType());
        $policy = $this->gate->getPolicyFor($this->model);
        if (! is_object($policy) || ! method_exists($policy, 'view')) {
            return $view;
        }

        $gate = $this->gate->forUser($this->user($principal));

        return new PolicyView(
            $view,
            // The real row, with sync's values over it - a rule that reads a
            // column devices never see must still hide what it hides on REST.
            // One that cannot even evaluate hides the row rather than failing
            // the whole page for everyone in the tenant.
            function (EntityRecord $record) use ($gate): bool {
                // A row that is gone has nothing left to hide but its id - and
                // judging it as a model of nulls hid the delete itself, so the
                // owner's other devices kept a row that no longer exists.
                if (! $this->rowExists($record)) {
                    return true;
                }
                try {
                    return $gate->allows('view', $this->hydrate($record));
                } catch (\Throwable) {
                    return false;
                }
            },
            // Per principal: the rule's answers differ by who is asking, and a
            // window cut for one user must never be resumed by another.
            $policy::class."\0".$principal->id,
        );
    }

    /** @return list<string> */
    public function readableFields(SyncPrincipal $principal): array
    {
        return $this->prototype->syncFields();
    }

    /** @return list<string> */
    public function writableFields(SyncPrincipal $principal): array
    {
        return array_values(array_diff($this->prototype->syncFields(), $this->prototype->syncReadOnly()));
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return $this->gate->forUser($this->user($principal))->allows('viewAny', $this->model);
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        $gate = $this->gate->forUser($this->user($principal));

        if ($record === null || $kind === MutationKind::Create) {
            return $gate->allows('create', $this->model);
        }

        return $gate->allows($kind === MutationKind::Delete ? 'delete' : 'update', $this->hydrate($record));
    }

    /**
     * Write what the engine settled on into the application's own table.
     *
     * Only the synced fields are touched. Anything else on the row belongs to
     * the application - a computed column, a relation's foreign key, a counter
     * - and overwriting it with nothing is how a sync layer eats data it was
     * never given.
     *
     * A field with no value becomes NULL: a column has no "absent", and
     * skipping it left the old value in the table while the log said there was
     * none.
     */
    public function persist(EntityRecord $record): void
    {
        $attributes = [];
        foreach ($this->prototype->syncFields() as $field) {
            // A field the log has never held is left as the table has it; a
            // field the log holds with no value was unset, and becomes NULL.
            if (array_key_exists($field, $record->fields)) {
                $value = $record->value($field);
                $attributes[$field] = $value->exists ? $value->value() : null;
            }
        }

        // The tenant is not a synced field - a client must never be able to set
        // it - but a new row cannot exist without it, and it is already encoded
        // in the space the engine wrote under.
        $column = $this->prototype->syncScopeColumn();
        $tenant = $column === null ? null : $this->scopeOf($record->entity->space);
        if ($column !== null) {
            $attributes[$column] = $tenant;
        }

        /** @var class-string<Model&SyncableModel> $model */
        $model = $this->model;
        $model::withoutSyncingKey($record->entity->id, function () use ($model, $record, $attributes, $column, $tenant): void {
            // Without global scopes: a row the application's scope hides is
            // still the row, and missing it here inserted a duplicate key.
            $row = $model::query()->withoutGlobalScopes()->whereKey($record->entity->id)->first() ?? new $model;
            $owner = $column === null ? null : $row->getAttribute($column);
            if ($row->exists && $column !== null && (! (is_string($owner) || is_int($owner)) || (string) $owner !== $tenant)) {
                // A row with this key in ANOTHER tenant. Writing it would hand
                // it to this one.
                throw new \LogicException(sprintf('Record %s belongs to a different tenant than the one this write was authorized for.', $record->entity->id));
            }

            // Raw, not fill(): the key and the tenant are deliberately not
            // fillable, and writableFields already decided what a client may
            // set before the engine saw it. And in the stored form, past casts
            // and mutators: the log holds what the column holds, so writing it
            // back is exact.
            $row->syncFill($attributes + [$row->getKeyName() => $record->entity->id]);
            if ($row->save() === false) {
                // An observer vetoed it. Answering "applied" would leave the
                // log and the table disagreeing; failing rolls both back.
                throw new \RuntimeException(sprintf('Saving %s %s was cancelled by the application.', $model, $record->entity->id));
            }
        });

        // What the application's own observers made of it - a trimmed title,
        // a computed field - is what the table now holds, and the devices have
        // to get that too, or the two never converge. Read back and recorded
        // as the server's own write, like any other save.
        $row = ($this->model)::query()->withoutGlobalScopes()->whereKey($record->entity->id)->first();
        if ($row !== null) {
            // The registered class, typed, carrying the row as stored.
            $stored = $this->prototype->newInstance();
            $stored->setRawAttributes($row->getAttributes(), true);
            $stored->exists = true;
            // Every synced field, not only those this write named: a column
            // the database defaulted on a device's create, or one an observer
            // set, is as much the table's as the fields the device sent. A
            // field the log has never held and the table holds nothing in
            // agrees already.
            $drift = [];
            foreach ($stored->syncValues($this->prototype->syncFields()) as $field => $value) {
                if ($value === null && ! array_key_exists($field, $record->fields)) {
                    continue;
                }
                if (! FieldValue::of($value)->equals($record->value($field))) {
                    $drift[] = $field;
                }
            }
            if ($drift !== []) {
                app(SyncRecorder::class)->record($stored, changed: $drift);
            }
        }
    }

    /**
     * Put a device's values into this model's own form before the engine sees
     * them, so the log holds for a field exactly what a save on the server of
     * the same values would log - typed, in the app's timezone, through the
     * model's mutators.
     */
    public function normalize(Mutation $mutation): Mutation
    {
        $values = [];
        foreach ($mutation->operations as $operation) {
            if ($operation->value->exists) {
                $values[$operation->field] = $operation->value->value();
            }
        }
        if ($values === []) {
            return $mutation;
        }
        try {
            $normalized = $this->prototype->syncNormalize($values);
        } catch (\InvalidArgumentException $invalid) {
            throw new SyncRequestRejected(sprintf('Field "%s" has a value this type cannot hold', $invalid->getMessage()), 'invalid_field_value');
        }

        $operations = [];
        foreach ($mutation->operations as $operation) {
            $operations[] = $operation->value->exists && array_key_exists($operation->field, $normalized)
                ? FieldOperation::set($operation->field, $normalized[$operation->field])
                : $operation;
        }

        return $mutation->rebased($mutation->baseVersion, $operations);
    }

    /** The tenant back out of the space this type built in space(). */
    private function scopeOf(string $space): string
    {
        $prefix = $this->entityType().':';

        return str_starts_with($space, $prefix) ? substr($space, strlen($prefix)) : $space;
    }

    /**
     * Through the model instance, not a query, so the application's own
     * deleting/deleted observers run exactly as they do for any other delete.
     */
    public function forget(EntityRecord $record): void
    {
        /** @var class-string<Model&SyncableModel> $model */
        $model = $this->model;
        $column = $this->prototype->syncScopeColumn();
        $model::withoutSyncingKey($record->entity->id, function () use ($model, $record, $column): void {
            $query = $model::query()->withoutGlobalScopes()->whereKey($record->entity->id);
            if ($column !== null) {
                $query->where($column, $this->scopeOf($record->entity->space));
            }
            $query->first()?->delete();
        });
    }

    /** The connection the application's table lives on. */
    public function connectionName(): ?string
    {
        return $this->prototype->getConnectionName();
    }

    private function rowExists(EntityRecord $record): bool
    {
        $query = ($this->model)::query()->withoutGlobalScopes()->whereKey($record->entity->id);
        $deletedAt = method_exists($this->prototype, 'getDeletedAtColumn') ? $this->prototype->getDeletedAtColumn() : null;
        if (is_string($deletedAt)) {
            // A soft-deleted row is gone too, as far as a device is concerned.
            $query->whereNull($deletedAt);
        }

        return $query->exists();
    }

    /**
     * The model a policy decides against: the application's own row, carrying
     * the values sync holds.
     *
     * The row, because a policy reads more than the synced fields - a locked
     * flag, an owner, the tenant - and a model built from synced fields alone
     * had those as null, which let through writes the real row forbids. The
     * synced values on top, because they are what the engine is about to merge
     * into, and the table can be behind them while a write is in flight.
     */
    private function hydrate(EntityRecord $record): Model
    {
        $column = $this->prototype->syncScopeColumn();
        $query = ($this->model)::query()->withoutGlobalScopes()->whereKey($record->entity->id);
        if ($column !== null) {
            $query->where($column, $this->scopeOf($record->entity->space));
        }
        $row = $query->first();
        // A fresh instance of the registered model carrying the row, so the
        // policy gets the application's own class with its casts and methods.
        $model = $this->prototype->newInstance();
        if ($row !== null) {
            $model->setRawAttributes($row->getAttributes(), true);
        }

        $attributes = [$model->getKeyName() => $record->entity->id];
        if ($column !== null) {
            $attributes[$column] = $this->scopeOf($record->entity->space);
        }
        foreach ($this->prototype->syncFields() as $field) {
            if (array_key_exists($field, $record->fields)) {
                $value = $record->value($field);
                $attributes[$field] = $value->exists ? $value->value() : null;
            }
        }
        $model->syncFill($attributes);
        $model->exists = true;

        return $model;
    }

    /**
     * Which tenants this principal may name.
     *
     * A model says so itself when it can - membership is the host's to know.
     * Otherwise the convention is the one most applications already have: the
     * user carries the same tenancy column the row does, and may name that one.
     * Neither available means refuse, because the alternative is letting the
     * client choose its own tenant.
     *
     * @return list<string>
     */
    private function allowedScopes(SyncPrincipal $principal): array
    {
        $user = $this->user($principal);

        if (method_exists($this->prototype, 'syncScopes')) {
            $scopes = $this->prototype->syncScopes($user);
            $allowed = [];
            foreach (is_array($scopes) ? $scopes : [] as $scope) {
                if (is_string($scope) || is_int($scope)) {
                    $allowed[] = (string) $scope;
                }
            }

            return $allowed;
        }

        $column = $this->prototype->syncScopeColumn();
        $own = $user instanceof Model && $column !== null ? $user->getAttribute($column) : null;
        if ($own !== null && (is_string($own) || is_int($own))) {
            return [(string) $own];
        }

        $named = $column ?? '';

        throw new \LogicException(sprintf(
            '%s is multi-tenant on "%s" but nothing says which tenants a principal may use. '
            .'Add syncScopes(Authenticatable $user): array to the model, or give the user model the same "%s" attribute.',
            $this->model,
            $named,
            $named,
        ));
    }

    /**
     * The authenticated user behind the principal.
     *
     * The principal is the protocol's identity and the Gate wants the
     * application's, so the two are matched rather than assumed: a guard that
     * has since changed hands must not authorize the request that started
     * under the previous one.
     */
    private function user(SyncPrincipal $principal): Authenticatable
    {
        $user = $this->auth->guard()->user();
        $identifier = $user?->getAuthIdentifier();
        if ($user === null || (! is_string($identifier) && ! is_int($identifier)) || (string) $identifier !== $principal->id) {
            throw SyncRequestRejected::forbidden();
        }

        return $user;
    }
}
