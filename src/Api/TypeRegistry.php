<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Exceptions\UnknownSyncableType;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a host's syncable types from a config map, lazily and once each.
 *
 * Deny-by-default: an entity type with no registration throws rather than
 * falling through to some default handler. A type the host never declared is a
 * type nobody decided the authorization rules for, so serving it is the one
 * outcome that must not be possible.
 */
class TypeRegistry implements SyncableTypes
{
    /** @var array<string, SyncableType> */
    private array $resolved = [];

    /** @param array<string, class-string> $map entity type => SyncableType implementation */
    public function __construct(private readonly Container $container, private array $map = []) {}

    /** Lets a host wire one in from a provider, and a test inject a fake. */
    public function register(string $entityType, SyncableType $type): void
    {
        $this->resolved[$entityType] = $type;
    }

    public function has(string $entityType): bool
    {
        return isset($this->resolved[$entityType]) || isset($this->map[$entityType]);
    }

    public function registered(): array
    {
        return array_values(array_unique([...array_keys($this->resolved), ...array_keys($this->map)]));
    }

    public function get(string $entityType): SyncableType
    {
        if (isset($this->resolved[$entityType])) {
            return $this->resolved[$entityType];
        }
        $class = $this->map[$entityType] ?? null;
        if ($class === null) {
            throw UnknownSyncableType::forType($entityType);
        }
        $instance = $this->container->make($class);

        // A model registered directly is served through the adapter that reads
        // it and asks the application's own policy, so a host declares sync on
        // the model and nowhere else.
        if ($instance instanceof Model && method_exists($instance, 'syncEntityType')) {
            /** @var class-string<Model> $class */
            $instance = new ModelSyncableType($class, $this->container->make(Gate::class), $this->container->make(AuthFactory::class));
        }

        if (! $instance instanceof SyncableType) {
            throw UnknownSyncableType::misconfigured($entityType, $class);
        }

        return $this->resolved[$entityType] = $instance;
    }
}
