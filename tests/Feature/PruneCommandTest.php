<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;

/**
 * The log is the only thing in the engine that grows without bound, and nothing
 * prunes it on its own. Without a supported way to answer "how much history does
 * this tenant still owe its slowest device", a busy space fills the disk.
 */
beforeEach(function () {
    foreach (range(1, 6) as $sequence) {
        $this->postJson('/sync/push', [
            'type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'm'.$sequence, 'id' => 't'.$sequence,
            'replica' => 'seed', 'sequence' => $sequence, 'kind' => 'create', 'base_version' => 0,
            'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'x'], ['field' => 'status', 'op' => 'set', 'value' => 'open']],
        ], ['X-Test-Principal' => 'alice'])->assertOk();
    }
});

it('drops history beyond the retention window', function () {
    $store = app(Store::class);
    expect($store->watermark('team-1')->value)->toBe(6);
    expect($store->retainedFrom('team-1')->value)->toBe(1);

    $this->artisan('sync:prune', ['space' => ['team-1'], '--keep' => 2])
        ->assertSuccessful();

    // Six commits, keeping two: everything below sequence 5 is gone.
    expect($store->retainedFrom('team-1')->value)->toBe(5);
});

it('reports without dropping when asked to pretend', function () {
    $this->artisan('sync:prune', ['space' => ['team-1'], '--keep' => 2, '--pretend' => true])
        ->assertSuccessful();

    expect(app(Store::class)->retainedFrom('team-1')->value)->toBe(1);
});

it('does nothing when the window already covers the whole log', function () {
    $this->artisan('sync:prune', ['space' => ['team-1'], '--keep' => 100])
        ->assertSuccessful();

    expect(app(Store::class)->retainedFrom('team-1')->value)->toBe(1);
});

/** The config key was documented and read nowhere; it is the default now. */
it('falls back to the configured retention window', function () {
    config()->set('sync.retention.keep_commits', 3);

    $this->artisan('sync:prune', ['space' => ['team-1']])->assertSuccessful();

    expect(app(Store::class)->retainedFrom('team-1')->value)->toBe(4);
});

it('refuses to keep nothing', function () {
    $this->artisan('sync:prune', ['space' => ['team-1'], '--keep' => 0])
        ->assertFailed();
});
