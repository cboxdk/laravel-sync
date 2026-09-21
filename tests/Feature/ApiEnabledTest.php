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

/**
 * sync.bootstrap.page_size was documented, had an env var, and was read
 * nowhere - so an operator who set it to speed up a large bootstrap saw
 * nothing change and had no error to find.
 */
beforeEach(function () {
    foreach ([['t1', 'a'], ['t2', 'b']] as [$id, $title]) {
        $this->postJson('/sync/push', ['type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'seed-'.$id, 'id' => $id, 'replica' => 'seed', 'sequence' => (int) substr($id, 1), 'kind' => 'create', 'base_version' => 0, 'operations' => [['field' => 'title', 'op' => 'set', 'value' => $title], ['field' => 'status', 'op' => 'set', 'value' => 'open']]], ['X-Test-Principal' => 'alice'])->assertOk();
    }
});

it('uses the configured bootstrap page size when a client asks for none', function () {
    config()->set('sync.bootstrap.page_size', 1);

    $page = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);

    $page->assertOk();
    expect($page->json('records'))->toHaveCount(1);
    expect($page->json('complete'))->toBeFalse();
});

it('still lets a client ask for a bigger page than the default', function () {
    config()->set('sync.bootstrap.page_size', 1);

    $page = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1', 'page_size' => 10], ['X-Test-Principal' => 'alice']);

    expect($page->json('records'))->toHaveCount(2);
    expect($page->json('complete'))->toBeTrue();
});
