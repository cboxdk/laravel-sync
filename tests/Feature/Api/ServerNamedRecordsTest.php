<?php

declare(strict_types=1);

/**
 * An id a client chooses is attacker-controlled input in a key position: it can
 * squat an identifier another tenant is about to use, and the engine's own
 * entity_exists answer turns a guessed one into an oracle for what already
 * exists. So a create carries only a handle, and the server names the record.
 */
it('names the record itself and ignores the handle the device sent', function () {
    // Posted raw rather than through the helper, which already rewrites a
    // handle into the name the server will give it.
    $response = $this->postJson('/sync/push', [
        'type' => 'tasks', 'scope' => 'team-1',
        'mutation_id' => 'm1', 'id' => 'i-choose-this', 'replica' => 'device-1',
        'sequence' => 1, 'kind' => 'create', 'base_version' => 0,
        'operations' => [setOp('title', 'Ship it'), setOp('status', 'open')],
    ], ['X-Test-Principal' => 'alice'])->assertOk();

    expect($response->json('id'))->not->toBe('i-choose-this');
    expect($response->json('temp_id'))->toBe('i-choose-this');

    // And the name is what the record is actually stored under.
    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($bootstrap->json('records.0.id'))->toBe($response->json('id'));
});

/**
 * A lost response is the normal case on a mobile network. A random name would
 * turn one offline create into two rows on the retry.
 */
it('gives a retried create the same name', function () {
    $first = push($this, 'alice', mutation('m1', 'handle', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')]))->assertOk();
    $retry = push($this, 'alice', mutation('m1', 'handle', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')]))->assertOk();

    expect($retry->json('id'))->toBe($first->json('id'));

    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($bootstrap->json('records'))->toHaveCount(1);
});

/** Two callers sending the same mutation id cannot land on the same record. */
it('gives two principals different names for the same handle', function () {
    $alice = push($this, 'alice', mutation('m1', 'same-handle', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')], 'device-1', 'alice'))->assertOk();
    $bob = push($this, 'bob', mutation('m1', 'same-handle', 1, 'create', 0, [setOp('title', 'b'), setOp('status', 'open')], 'device-2', 'bob'))->assertOk();

    expect($bob->json('id'))->not->toBe($alice->json('id'));

    // Both exist: neither overwrote the other.
    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($bootstrap->json('records'))->toHaveCount(2);
});

/** An update names the record it means, and that name is the server's. */
it('takes the real name on an update', function () {
    $created = push($this, 'alice', mutation('m1', 'handle', 1, 'create', 0, [setOp('title', 'before'), setOp('status', 'open')]))->assertOk();

    push($this, 'alice', mutation('m2', 'handle', 2, 'update', 1, [setOp('title', 'after')]))
        ->assertOk()->assertJsonPath('status', 'applied');

    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($bootstrap->json('records'))->toHaveCount(1);
    expect($bootstrap->json('records.0.id'))->toBe($created->json('id'));
    expect($bootstrap->json('records.0.fields.title.value'))->toBe('after');
});
