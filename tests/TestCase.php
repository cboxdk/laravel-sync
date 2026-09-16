<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests;

use Cbox\Sync\Laravel\SyncServiceProvider;
use Cbox\Sync\Laravel\Testing\InteractsWithSync;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use InteractsWithSync;

    protected function getPackageProviders($app): array
    {
        return [SyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sync-testing');
        $app['config']->set('database.connections.sync-testing', [
            'driver' => env('SYNC_DRIVER', 'sqlite'),
            'database' => env('SYNC_DATABASE', ':memory:'),
            'host' => env('SYNC_HOST', '127.0.0.1'),
            'port' => env('SYNC_PORT'),
            'username' => env('SYNC_USERNAME', 'root'),
            'password' => env('SYNC_PASSWORD', ''),
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
