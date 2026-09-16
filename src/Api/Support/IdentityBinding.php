<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\ValueObjects\Replica;

/**
 * Namespaces the two identifiers a client gets to choose.
 *
 * `Mutation::$replica` selects an acknowledgement stream and `$id` is globally
 * unique, and the engine honours both without knowing who sent them. Unbound,
 * anyone in a tenant who names another device's replica claims its sequence
 * numbers: that device's next push fails terminally for reusing a sequence and
 * its queued mutations are unrecoverable, because their identities are now
 * burned against different content. The same holds for a mutation id, which one
 * caller could otherwise burn before another uses it.
 *
 * Bound on the principal's STABLE id, never its authorization binding: a
 * permission change must not orphan a client's unflushed queue.
 *
 * Hashed rather than concatenated, because a principal id is an arbitrary host
 * string and a crafted one could otherwise collide with another's namespace.
 */
class IdentityBinding
{
    public static function replica(SyncPrincipal $principal, string $clientReplicaId): Replica
    {
        return new Replica(self::hash('replica', $principal->id, $clientReplicaId));
    }

    public static function mutationId(SyncPrincipal $principal, string $clientMutationId): string
    {
        return self::hash('mutation', $principal->id, $clientMutationId);
    }

    private static function hash(string $domain, string $principalId, string $value): string
    {
        return hash('sha256', serialize([$domain, $principalId, $value]));
    }
}
