<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;

/**
 * A type that puts a device's values into its own form before the engine sees
 * them, so the log holds the same value for a field whichever path wrote it.
 */
interface NormalizesValues
{
    /** @throws SyncRequestRejected when a value cannot be taken */
    public function normalize(Mutation $mutation): Mutation;
}
