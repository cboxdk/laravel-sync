<?php

declare(strict_types=1);

use Cbox\Sync\Laravel\Api\ApiServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Laravel's env() converts true, false and null but leaves "1" a string, so a
 * strict comparison meant the most natural way to turn the API on registered
 * no routes and said nothing about it.
 */
it('accepts every ordinary way of writing yes', function (mixed $value) {
    config()->set('sync.api.enabled', $value);

    $provider = new ApiServiceProvider($this->app);
    $provider->boot();

    expect(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'sync/push'))->toBeTrue();
})->with([true, 1, '1', 'true', 'on', 'yes']);

it('stays off for every ordinary way of writing no', function (mixed $value) {
    config()->set('sync.api.enabled', $value);
    Route::setRoutes(new RouteCollection);

    (new ApiServiceProvider($this->app))->boot();

    expect(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'sync/push'))->toBeFalse();
})->with([false, 0, '0', 'false', 'off', 'no', null, '', 'nonsense']);
