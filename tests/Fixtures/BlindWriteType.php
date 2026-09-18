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
 * A policy that allows writing rows it does not allow reading: the view shows
 * only open tickets, but any ticket may be written to. Legitimate, and exactly
 * where a conflict response could disclose a row the caller cannot see.
 */
class BlindWriteType implements SyncableType
{
    public function entityType(): string
    {
        return 'tickets';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        return 'team-1';
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('open-tickets', '1', 'state', 'open', 'tickets');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['subject', 'state'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['subject', 'state'];
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return true;
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        return true;
    }
}
