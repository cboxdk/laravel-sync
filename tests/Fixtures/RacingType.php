<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\ViewDefinition;

/**
 * Stands in for a record whose ownership changes between the gate in front of
 * the engine and the write itself. The first authorization call succeeds and
 * every later one fails, which is what losing that race looks like from
 * inside a single process.
 */
class RacingType implements SyncableType
{
    private int $calls = 0;

    public function entityType(): string
    {
        return 'racing';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        return 'team-1';
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('racing', '1', 'state', 'live', 'racing');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['name', 'state'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['name', 'state'];
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return true;
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        // Creates are never contested; only the update under test is.
        if ($kind === MutationKind::Create) {
            return true;
        }

        return ++$this->calls === 1;
    }
}
