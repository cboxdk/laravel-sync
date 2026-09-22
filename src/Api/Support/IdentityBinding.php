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

    /**
     * The name a newly created record gets.
     *
     * An id a client chooses is attacker-controlled input in a key position: it
     * can squat an identifier another tenant is about to use, and the engine's
     * own entity_exists answer turns a guessed one into an oracle for what
     * already exists. So the server names the record, and the client only ever
     * sends a handle it made up to refer to it until the answer comes back.
     *
     * Derived rather than random, so a device that lost the response and
     * retries the same mutation gets the same name again. A random id would
     * turn one offline create into two rows, and the receipt cannot rescue it:
     * the receipt is found by mutation id, but the entity key has to be built
     * before the engine is reached.
     *
     * The principal is inside the hash, so no caller can produce a name another
     * caller would produce, and reaching an existing record's name would mean
     * finding a sha256 preimage.
     *
     * Shaped as a UUID (version 8, the RFC 9562 form for custom derivations) so
     * it fits the uuid or char(36) key column a model already has. 122 bits of
     * the hash survive the version and variant bits - far past any collision
     * that matters, and still no preimage to find.
     */
    public static function entityId(SyncPrincipal $principal, string $clientMutationId): string
    {
        $bytes = substr(hash('sha256', serialize(['entity', $principal->id, $clientMutationId]), true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x80);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private static function hash(string $domain, string $principalId, string $value): string
    {
        return hash('sha256', serialize([$domain, $principalId, $value]));
    }
}
