<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides who may listen to a space's change channel.
 *
 * The channel name IS the tenant boundary. A private channel per space is
 * another way into the same data the endpoints guard, and one that bypasses
 * them entirely: a subscriber who should not see a tenant must not be able to
 * learn that it is busy, how often, or how far its log has got.
 *
 * There is deliberately no default. A permissive one would be the worst thing
 * this package could ship, and a refusing one would look broken - so the
 * channel is not registered at all until a host binds this, and nobody can
 * subscribe until someone has decided who may.
 *
 * The signal carries only a watermark, so what leaks without this is metadata
 * rather than records. Metadata is still an answer: poll it and you have an
 * edit-rate and a working-hours profile for a tenant you cannot see.
 */
interface AuthorizesSpaceChannel
{
    public function mayListen(Authenticatable $user, string $space): bool;
}
