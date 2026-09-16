<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Laravel\Api\Exceptions\UnknownSyncableType;

interface SyncableTypes
{
    /** @throws UnknownSyncableType when the type is not registered */
    public function get(string $entityType): SyncableType;

    public function has(string $entityType): bool;

    /** @return list<string> */
    public function registered(): array;
}
