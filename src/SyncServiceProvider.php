<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\IdGenerator;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Engine;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\Support\UuidV7Generator;
use Cbox\Sync\Validation\AcceptAll;
use Cbox\Sync\Views\BootstrapSessions;
use Cbox\Sync\Views\FrozenBootstrapSessions;
use Cbox\Sync\Views\KeysetBootstrapSessions;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync.php', 'sync');

        $this->app->singleton(Store::class, function (Application $app): Store {
            $name = $this->setting($app, 'sync.connection');
            $connection = $app->make(DatabaseManager::class)->connection(is_string($name) && $name !== '' ? $name : null);

            return new IlluminateStore($connection);
        });

        // Defaults a host is expected to replace. Preserving competing proposals
        // and accepting every entity state are the only pair that cannot
        // silently discard a user's work before an application has stated its
        // own rules; a last-write-wins default would lose edits in exactly the
        // situation sync exists to handle.
        $this->app->bindIf(ConflictResolver::class, fn (): ConflictResolver => new PreserveConflict);
        $this->app->bindIf(EntityValidator::class, fn (): EntityValidator => new AcceptAll);
        $this->app->bindIf(IdGenerator::class, fn (): IdGenerator => new UuidV7Generator);

        $this->app->singleton(Engine::class, fn (Application $app): Engine => new Engine(
            $app->make(Store::class),
            $app->make(ConflictResolver::class),
            $app->make(IdGenerator::class),
            $app->make(EntityValidator::class),
        ));

        $this->app->singleton(BootstrapSessions::class, function (Application $app): BootstrapSessions {
            $store = $app->make(Store::class);
            if ($this->setting($app, 'sync.bootstrap.strategy') === 'frozen') {
                return new FrozenBootstrapSessions($store);
            }
            $secret = $this->setting($app, 'sync.bootstrap.secret') ?? $this->setting($app, 'app.key');
            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('Stateless bootstrap tokens need sync.bootstrap.secret or a configured app key.');
            }

            return new KeysetBootstrapSessions($store, $secret);
        });

        $this->app->singleton(ViewSyncService::class, fn (Application $app): ViewSyncService => new ViewSyncService(
            $app->make(Store::class),
            $this->text($app, 'sync.schema_version', 'v1'),
            $this->text($app, 'sync.epoch', 'epoch-1'),
            $app->make(BootstrapSessions::class),
        ));
    }

    public function boot(): void
    {
        $this->app->register(Api\ApiServiceProvider::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sync.php' => $this->app->configPath('sync.php')], 'sync-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'sync-migrations');
        }
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function setting(Application $app, string $key): mixed
    {
        return $app->make(Repository::class)->get($key);
    }

    private function text(Application $app, string $key, string $fallback): string
    {
        $value = $this->setting($app, $key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
