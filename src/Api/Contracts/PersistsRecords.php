<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Data\EntityRecord;

/**
 * A syncable type that keeps the application's own table in step.
 *
 * Sync owns ordering and conflict resolution; the host's table is where the
 * rest of the application reads. Without this they are two stores of the same
 * thing that drift, which is worse than either alone - so the write happens in
 * the same transaction as the mutation, and a failure takes both back.
 *
 * Optional: a type that does not implement it simply has no table to keep.
 */
interface PersistsRecords
{
    /** Write the canonical record - the value the engine settled on - to the host's own store. */
    public function persist(EntityRecord $record): void;

    /** The record is a tombstone. Remove it from the host's own store. */
    public function forget(EntityRecord $record): void;
}
