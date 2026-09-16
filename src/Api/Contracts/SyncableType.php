<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\ViewDefinition;

/**
 * A host's declaration that one entity type may be reached over the sync API.
 *
 * This is the entire authorization surface. The engine checks protocol
 * correctness and nothing else; it has no idea who is calling. Everything that
 * keeps one tenant's data away from another lives behind this interface.
 */
interface SyncableType
{
    /** The entity type written into every key for this registration. */
    public function entityType(): string;

    /**
     * The sync space this principal operates in.
     *
     * $scope is an opaque SELECTOR, not a space: a principal who belongs to
     * three teams has to say which one. Map it through the principal's own
     * memberships and refuse anything else. Returning $scope unchanged turns
     * the whole transport into a cross-tenant read, because the space is the
     * isolation boundary and the client would then be choosing it.
     */
    public function space(SyncPrincipal $principal, ?string $scope): string;

    /**
     * The filtered view this principal reads.
     *
     * A cursor is only invalidated when its context fingerprint changes, and
     * the fingerprint comes from the view. A view whose signature ignores the
     * principal's actual authorized scope therefore keeps serving deltas to
     * someone whose access was revoked, until their cursor happens to fall
     * below the retention horizon.
     */
    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition;

    /**
     * Fields this principal may see. The view is a ROW filter; this is the
     * COLUMN filter, and nothing else applies one.
     *
     * @return list<string>
     */
    public function readableFields(SyncPrincipal $principal): array;

    /**
     * Fields this principal may write, deny-by-default.
     *
     * A field outside this list is refused, never dropped: silently ignoring a
     * write leaves the client believing it saved something it did not.
     *
     * @return list<string>
     */
    public function writableFields(SyncPrincipal $principal): array;

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool;

    /**
     * Called with the CURRENT stored record, or null for a create, and always
     * before the engine sees the mutation.
     *
     * The ordering matters: a mutation id that reaches the engine is
     * acknowledged forever, so a refusal that happened afterwards would leave
     * the client unable to ever retry that id.
     */
    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool;
}
