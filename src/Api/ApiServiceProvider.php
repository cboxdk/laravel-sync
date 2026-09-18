<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\Http\Controllers\SyncBootstrapController;
use Cbox\Sync\Laravel\Api\Http\Controllers\SyncDeltaController;
use Cbox\Sync\Laravel\Api\Http\Controllers\SyncPushController;
use Cbox\Sync\Laravel\Api\Http\Middleware\RequireJsonBody;
use Cbox\Sync\Laravel\Api\Http\Middleware\ResolveSyncPrincipal;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncableTypes::class, fn (Application $app): SyncableTypes => new TypeRegistry($app, $this->typeMap($app)));

        $this->app->bindIf(SyncPrincipals::class, GuardPrincipals::class);
        $this->app->bindIf(SyncEndpoints::class, SyncService::class);

        $this->app->when(RequireJsonBody::class)
            ->needs('$maxBytes')
            ->give(fn (Application $app): int => $this->bytes($app));
    }

    public function boot(): void
    {
        $this->registerSyncRoutes();
    }

    private function registerSyncRoutes(): void
    {
        $config = $this->app->make(Repository::class);
        if ($config->get('sync.api.enabled') !== true) {
            return;
        }

        // FrozenBootstrapSessions keeps its sessions in process memory, and the
        // container is rebuilt per request under PHP-FPM. Page two of every
        // bootstrap would land on an instance that has never heard of the
        // token and answer with a reset - an endless bootstrap loop that looks
        // like a client bug. Refuse to boot rather than ship it.
        if ($config->get('sync.bootstrap.strategy') === 'frozen') {
            throw new \RuntimeException('sync.api cannot be enabled with the "frozen" bootstrap strategy; use "keyset".');
        }

        $middleware = $config->get('sync.api.middleware');
        $router = $this->app->make(Router::class);

        $router->middleware([
            ...(is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : []),
            RequireJsonBody::class,
            // Always last, never configurable: everything downstream assumes a
            // principal is present.
            ResolveSyncPrincipal::class,
        ])->prefix($this->prefix($config))->group(function (Router $router): void {
            $router->post('/push', SyncPushController::class)->name('sync.push');
            $router->post('/bootstrap', SyncBootstrapController::class)->name('sync.bootstrap');
            $router->post('/delta', SyncDeltaController::class)->name('sync.delta');
        });
    }

    private function prefix(Repository $config): string
    {
        $prefix = $config->get('sync.api.prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'sync';
    }

    /**
     * A malformed entry is dropped here rather than surfacing later as a
     * routing or container exception, but an entity type with no usable
     * registration still throws when it is asked for - never a no-op.
     *
     * @return array<string, class-string>
     */
    private function typeMap(Application $app): array
    {
        $configured = $app->make(Repository::class)->get('sync.api.types');
        if (! is_array($configured)) {
            return [];
        }
        $map = [];
        foreach ($configured as $key => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            // A bare model class knows its own entity type, so listing it is
            // the whole registration: ['types' => [Task::class]].
            $entityType = is_string($key) && $key !== '' ? $key : self::entityTypeOf($class);
            if ($entityType !== null) {
                $map[$entityType] = $class;
            }
        }

        return $map;
    }

    /** @param class-string $class */
    private static function entityTypeOf(string $class): ?string
    {
        if (! is_subclass_of($class, Model::class) || ! method_exists($class, 'syncEntityType')) {
            return null;
        }
        $type = (new $class)->syncEntityType();

        return is_string($type) && $type !== '' ? $type : null;
    }

    private function bytes(Application $app): int
    {
        $value = $app->make(Repository::class)->get('sync.api.max_body_bytes');

        return is_int($value) && $value > 0 ? $value : 262144;
    }
}
