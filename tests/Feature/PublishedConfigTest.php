<?php

declare(strict_types=1);

use Cbox\Sync\Laravel\SyncServiceProvider;

/**
 * mergeConfigFrom() is one level deep, so a published config trimmed to the
 * keys a host cared about deleted the rest - api.middleware among them, which
 * left the write endpoint with no throttle and no auth stack.
 */
it('keeps every default a published config left out', function () {
    config()->set('sync', ['api' => ['enabled' => true, 'prefix' => 'mine']]);

    (new SyncServiceProvider($this->app))->register();

    expect(config('sync.api.middleware'))->toBe(['api'])
        ->and(config('sync.api.max_operations'))->toBe(64)
        ->and(config('sync.api.prefix'))->toBe('mine')
        ->and(config('sync.api.enabled'))->toBeTrue()
        ->and(config('sync.retention'))->toBeArray();
});

it('leaves a list the host shortened on purpose alone', function () {
    config()->set('sync', ['api' => ['middleware' => ['auth:sanctum'], 'types' => ['notes' => 'App\\NoteType']]]);

    (new SyncServiceProvider($this->app))->register();

    expect(config('sync.api.middleware'))->toBe(['auth:sanctum'])
        ->and(config('sync.api.types'))->toBe(['notes' => 'App\\NoteType']);
});
