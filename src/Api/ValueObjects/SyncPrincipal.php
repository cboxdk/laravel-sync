<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * Who is calling. Two identifiers, deliberately separate, because they are
 * needed for opposite reasons.
 */
readonly class SyncPrincipal
{
    public function __construct(
        /**
         * A STABLE account identifier. It namespaces replica and mutation ids
         * and becomes the trusted actor on every mutation.
         *
         * It must not be a session id, a token id, or anything that rotates at
         * re-login. `Engine::process()` compares the stored actor when a
         * mutation is replayed, so an identifier that changes turns every
         * legitimate retry into a terminal protocol error, with the client's
         * queued writes unrecoverable.
         */
        public string $id,
        /**
         * Changes whenever this principal's AUTHORIZATION changes.
         *
         * It seals bootstrap tokens, so a permission change invalidates every
         * open bootstrap. Deliberately not $id, which must never change: a
         * permission change must not orphan a client's unflushed queue.
         */
        public string $binding,
        public ?string $integrationId = null,
    ) {
        if ($id === '' || $binding === '' || $integrationId === '') {
            throw new InvalidRequest('Principal identities must not be empty when present');
        }
    }
}
