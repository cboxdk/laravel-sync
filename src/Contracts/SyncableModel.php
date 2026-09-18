<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Contracts;

/**
 * What the Syncable trait answers about a model.
 *
 * A host does not have to implement this: using the trait is the registration,
 * and the package checks for the methods rather than the interface. It exists
 * so static analysis can see through a Model to the trait, and so a host that
 * wants the contract stated on the class can say so.
 */
interface SyncableModel
{
    public function syncEntityType(): string;

    /** @return list<string> */
    public function syncFields(): array;

    /** @return list<string> */
    public function syncReadOnly(): array;

    public function syncScopeColumn(): ?string;
}
