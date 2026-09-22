<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Sync\Contracts\CommitObserver;
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
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class SyncServiceProvider extends ServiceProvider
{
    /**
     * Put back every nested default a published config left out.
     *
     * mergeConfigFrom() merges one level deep. A host that publishes the file
     * and trims `api` to the one key it cares about therefore deletes the
     * others - and `api.middleware` going missing leaves the write endpoint,
     * which takes a tenant-wide lock, with no throttle and no auth stack in
     * front of it. Missing keys are filled at every depth; anything the host
     * did set, including a list it shortened on purpose, is left exactly as it
     * is.
     */
    private function fillMissingSettings(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }
        $config = $this->app->make(Repository::class);
        $current = $config->get('sync');
        $defaults = require __DIR__.'/../config/sync.php';
        if (is_array($current) && is_array($defaults)) {
            $config->set('sync', self::withDefaults($current, $defaults));
        }
    }

    /**
     * @param  array<array-key, mixed>  $current
     * @param  array<array-key, mixed>  $defaults
     * @return array<array-key, mixed>
     */
    private static function withDefaults(array $current, array $defaults): array
    {
        if (array_is_list($defaults) && $defaults !== []) {
            return $current;
        }
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $default;
            } elseif (is_array($default) && is_array($current[$key]) && ! array_is_list($default)) {
                $current[$key] = self::withDefaults($current[$key], $default);
            }
        }

        return $current;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync.php', 'sync');
        $this->fillMissingSettings();

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

        // bindIf, so a host that wants a different notifier - or none - binds
        // its own without fighting this one.
        $this->app->bindIf(CommitObserver::class, function (Application $app): CommitObserver {
            $store = $app->make(Store::class);

            return new Api\DispatchesSpaceAdvanced(
                $app->make(Dispatcher::class),
                $app->make(LoggerInterface::class),
                $store instanceof IlluminateStore ? $store->databaseConnection() : null,
            );
        });

        $this->app->singleton(Engine::class, fn (Application $app): Engine => new Engine(
            $app->make(Store::class),
            $app->make(ConflictResolver::class),
            $app->make(IdGenerator::class),
            $app->make(EntityValidator::class),
            $app->make(CommitObserver::class),
        ));

        // One per application, so the echo a device write is being recorded
        // under is seen by the model saves it triggers.
        $this->app->singleton(SyncRecorder::class);
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

    /**
     * A precondition a request met lives as long as the transaction level it
     * was met in. Only once the recorder exists: nothing can have been met
     * before, and resolving it on every commit would open the sync store's
     * connection for applications that never touch it.
     */
    private function trackPreconditions(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(TransactionCommitted::class, static fn (TransactionCommitted $event) => SyncRecorder::settle(self::currentRequest(), $event->connection, keep: true));
        $events->listen(TransactionRolledBack::class, static fn (TransactionRolledBack $event) => SyncRecorder::settle(self::currentRequest(), $event->connection, keep: false));
        $events->listen(TransactionBeginning::class, static fn (TransactionBeginning $event) => SyncRecorder::began(self::currentRequest(), $event->connection));
    }

    /** The request being handled now - the sandbox's own under Octane. */
    private static function currentRequest(): ?Request
    {
        // app() is the current container - the sandbox's under Octane.
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : null;
    }

    public function boot(): void
    {
        $this->app->register(Api\ApiServiceProvider::class);
        $this->trackPreconditions();
        $this->registerWebhookDelivery();
        $this->registerBroadcasting();

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PruneSyncCommand::class]);
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

    /**
     * Broadcasts a change, when asked, on a channel nobody can join until the
     * host has said who may.
     *
     * The channel name is the tenant boundary: a private channel per space is
     * another way into the same data the endpoints guard, and one that bypasses
     * them. So the channel is registered only once AuthorizesSpaceChannel is
     * bound - without it, listening is impossible rather than open.
     */
    private function registerBroadcasting(): void
    {
        $enabled = $this->app->make(Repository::class)->get('sync.broadcast.enabled');
        if (filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return;
        }

        $this->app->make(Dispatcher::class)
            ->listen(Events\SpaceAdvanced::class, Broadcasting\BroadcastSpaceAdvanced::class);

        if (! $this->app->bound(Api\Contracts\AuthorizesSpaceChannel::class)) {
            return;
        }

        $this->app->make(BroadcastFactory::class)->channel(
            'sync.{space}',
            fn (Authenticatable $user, string $space): bool => $this->app
                ->make(Api\Contracts\AuthorizesSpaceChannel::class)
                ->mayListen($user, $space),
        );
    }

    /**
     * Wires webhook delivery only when a URL is configured, and refuses to do it
     * half way.
     *
     * A callback URL is tenant-supplied input aimed at this server's own
     * network, and a receiver that cannot tell our POST from anyone else's has
     * learned only that someone knows its URL. Shipping the delivery without
     * both guards would let a host turn on the unprotected version by setting
     * one env var, so they are required at the point it matters rather than
     * merely suggested.
     */
    private function registerWebhookDelivery(): void
    {
        $url = $this->app->make(Repository::class)->get('sync.webhooks.url');
        if (! is_string($url) || $url === '') {
            return;
        }

        foreach ([
            UrlGuard::class => 'cboxdk/laravel-ssrf',
            \Cbox\WebhookSignature\Contracts\Webhooks::class => 'cboxdk/laravel-webhook-signature',
        ] as $contract => $package) {
            if (! interface_exists($contract)) {
                throw new \RuntimeException(sprintf(
                    'sync.webhooks.url is set but %s is not installed. A callback URL is tenant-supplied input '
                    ."aimed at this server's own network, and an unsigned delivery tells a receiver only that "
                    .'someone knows its URL. Run: composer require %s',
                    $package,
                    $package,
                ));
            }
        }

        // The SSRF guard pins the connection to the addresses it validated
        // through cURL's own resolver. Without cURL, Guzzle falls back to a
        // stream handler that resolves the name again - after the check - which
        // is exactly the DNS-rebinding window the pin exists to close.
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('sync.webhooks.url is set but the curl extension is not loaded. Webhook delivery pins the connection to the address it validated, and only cURL can hold that pin.');
        }

        $this->app->make(Dispatcher::class)
            ->listen(Events\SpaceAdvanced::class, Webhooks\DeliverSpaceAdvanced::class);
    }
}
