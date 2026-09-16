<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Testing;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Contracts\Foundation\Application;

/**
 * Drives the sync engine from a host application's tests without hand-building
 * mutations. Everything resolves from the container, so a host that has swapped
 * the resolver or validator exercises its own wiring.
 */
trait InteractsWithSync
{
    protected function syncStore(): Store
    {
        return $this->syncContainer()->make(Store::class);
    }

    protected function syncEngine(): Engine
    {
        return $this->syncContainer()->make(Engine::class);
    }

    protected function syncViews(): ViewSyncService
    {
        return $this->syncContainer()->make(ViewSyncService::class);
    }

    protected function syncContainer(): Application
    {
        return $this->app ?? throw new \LogicException('The application has not been booted; call this inside a test.');
    }

    /** @param list<FieldOperation> $operations */
    protected function syncCreate(EntityKey $entity, array $operations, string $replica = 'test-replica', int $sequence = 1): MutationResult
    {
        return $this->syncMutate($entity, $operations, MutationKind::Create, 0, $replica, $sequence);
    }

    /** @param list<FieldOperation> $operations */
    protected function syncUpdate(EntityKey $entity, array $operations, ?int $base = null, string $replica = 'test-replica', int $sequence = 1): MutationResult
    {
        $base ??= $this->syncStore()->record($entity)?->version->value ?? 0;

        return $this->syncMutate($entity, $operations, MutationKind::Update, $base, $replica, $sequence);
    }

    protected function syncDelete(EntityKey $entity, ?int $base = null, string $replica = 'test-replica', int $sequence = 1): MutationResult
    {
        $base ??= $this->syncStore()->record($entity)?->version->value ?? 0;

        return $this->syncMutate($entity, [], MutationKind::Delete, $base, $replica, $sequence);
    }

    /** @param list<FieldOperation> $operations */
    protected function syncMutate(EntityKey $entity, array $operations, MutationKind $kind, int $base, string $replica, int $sequence): MutationResult
    {
        return $this->syncEngine()->process(new Mutation(
            $replica.'-'.$entity->id.'-'.$sequence,
            $entity,
            new Replica($replica),
            new MutationSequence($sequence),
            $kind,
            new RecordVersion($base),
            $operations,
        ));
    }
}
