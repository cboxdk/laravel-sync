<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\EntityTypeView;
use Cbox\Sync\Views\ViewDefinition;
use Illuminate\Support\Str;

/**
 * Everything a syncable type needs except the decisions only the host can make.
 *
 * Four of the seven methods are answered from two properties. The three that
 * stay abstract are the ones the contract's own docblocks warn about, and they
 * are abstract on purpose:
 *
 * - space() decides the tenant. A default that returned $scope would turn the
 *   transport into a cross-tenant read, because the client would be choosing
 *   the isolation boundary.
 * - mayRead() and mayWrite() are the authorization surface. A permissive
 *   default would be the single worst thing this package could ship, and a
 *   refusing one would only look broken.
 *
 * So the compiler asks for those three, and nothing else.
 *
 *     class TaskType extends BaseSyncableType
 *     {
 *         protected array $fields = ['title', 'status', 'due_at', 'created_by'];
 *         protected array $readOnly = ['created_by'];
 *
 *         public function space(SyncPrincipal $principal, ?string $scope): string
 *         {
 *             return $this->teams->spaceFor($principal->id, $scope);
 *         }
 *
 *         public function mayRead(SyncPrincipal $principal, ?string $scope): bool
 *         {
 *             return $this->teams->has($principal->id, $scope);
 *         }
 *
 *         public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
 *         {
 *             return $this->teams->canEdit($principal->id, $record);
 *         }
 *     }
 */
abstract class BaseSyncableType implements SyncableType
{
    /**
     * The entity type written into every key for this registration.
     *
     * Left empty it is derived from the class name: TaskType becomes "tasks".
     * Set it whenever the class name is not what the wire should say, and
     * never change it afterwards - it is part of every stored key.
     */
    protected string $type = '';

    /**
     * Every field this type syncs, readable by default.
     *
     * @var list<string>
     */
    protected array $fields = [];

    /**
     * Fields a client may read but never write. Writable is the rest.
     *
     * @var list<string>
     */
    protected array $readOnly = [];

    public function entityType(): string
    {
        if ($this->type !== '') {
            return $this->type;
        }

        $base = class_basename(static::class);
        $stem = Str::endsWith($base, 'Type') ? Str::beforeLast($base, 'Type') : $base;

        return Str::snake(Str::plural($stem === '' ? $base : $stem));
    }

    /**
     * Every live record of this type in the space.
     *
     * The space is already the isolation boundary, so adding no filter is a
     * wider window onto one tenant, never a weaker one. Override this to narrow
     * further - and remember that a view whose signature ignores the
     * principal's authorized scope keeps serving deltas after access is
     * revoked.
     */
    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return EntityTypeView::of($this->entityType());
    }

    /** @return list<string> */
    public function readableFields(SyncPrincipal $principal): array
    {
        return $this->declaredFields();
    }

    /** @return list<string> */
    public function writableFields(SyncPrincipal $principal): array
    {
        return array_values(array_diff($this->declaredFields(), $this->readOnly));
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when the declaration cannot mean what it says
     */
    private function declaredFields(): array
    {
        if ($this->fields === []) {
            throw new \LogicException(sprintf(
                '%s declares no fields. Set $fields to the fields this type syncs; a type with none can neither be read nor written.',
                static::class,
            ));
        }

        // A read-only field that is not synced at all is a typo, and the effect
        // of leaving it alone is that the field stays writable - the opposite
        // of what was asked for.
        $unknown = array_diff($this->readOnly, $this->fields);
        if ($unknown !== []) {
            throw new \LogicException(sprintf(
                '%s lists read-only fields it does not sync: %s. Add them to $fields, or remove them from $readOnly.',
                static::class,
                implode(', ', $unknown),
            ));
        }

        return $this->fields;
    }
}
