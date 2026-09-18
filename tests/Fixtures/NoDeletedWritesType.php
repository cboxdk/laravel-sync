<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\ViewDefinition;

/** A perfectly ordinary policy: nothing may be written to a deleted record. */
class NoDeletedWritesType implements SyncableType
{
    public function entityType(): string
    {
        return 'files';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        return 'team-1';
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('all-files', '1', 'state', 'live', 'files');
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
        return $record === null || ! $record->deleted;
    }
}
