<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\Contracts\SyncableModel;
use Cbox\Sync\Laravel\Syncable;
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
class ModelSyncableType implements SyncableType
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

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return EntityTypeView::of($this->entityType());
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
     * A model carrying what sync holds, so the policy decides against the state
     * the engine is about to merge into rather than a row that may be behind it.
     */
    private function hydrate(EntityRecord $record): Model
    {
        $model = new $this->model;
        $attributes = [$model->getKeyName() => $record->entity->id];
        foreach ($this->prototype->syncFields() as $field) {
            $attributes[$field] = $record->value($field)->value();
        }
        $model->forceFill($attributes)->exists = true;

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
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw SyncRequestRejected::forbidden();
        }
        if ((string) $identifier !== $principal->id) {
            throw SyncRequestRejected::forbidden();
        }

        /** @var Authenticatable $user */
        return $user;
    }
}
