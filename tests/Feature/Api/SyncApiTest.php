<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Laravel\Api\Support\IdentityBinding;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Illuminate\Testing\TestResponse;

/** Real HTTP through the router, the way a device would call it. */
function push(object $test, string $principal, array $mutation): TestResponse
{
    return $test->postJson('/sync/push', ['type' => 'tasks', 'scope' => 'team-1'] + $mutation, [
        'X-Test-Principal' => $principal,
    ]);
}

/**
 * A create is named by the server, so the second argument is only the handle a
 * device uses to refer to the record until the answer comes back. Later
 * mutations against the same handle are rewritten to the name the server gave
 * it - which is what a real client does with the id in the response.
 */
function mutation(string $id, string $handle, int $sequence, string $kind, int $base, array $operations, string $replica = 'device-1', string $principal = 'alice'): array
{
    if ($kind === 'create') {
        remember($handle, IdentityBinding::entityId(new SyncPrincipal($principal, $principal), $id));
    }

    return [
        'mutation_id' => $id, 'id' => named($handle), 'replica' => $replica,
        'sequence' => $sequence, 'kind' => $kind, 'base_version' => $base,
        'operations' => $operations,
    ];
}

function remember(string $handle, ?string $name = null): string
{
    static $names = [];
    if ($name !== null) {
        $names[$handle] = $name;
    }

    return $names[$handle] ?? $handle;
}

/** The name the server gave the record this handle refers to. */
function named(string $handle): string
{
    return remember($handle);
}

function setOp(string $field, mixed $value): array
{
    return ['field' => $field, 'op' => 'set', 'value' => $value];
}

it('carries a task from create through bootstrap', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'Ship it'), setOp('status', 'open')]))
        ->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('record_version', 1);

    $page = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1', 'page_size' => 10], ['X-Test-Principal' => 'alice']);

    $page->assertOk()
        ->assertJsonPath('complete', true)
        ->assertJsonPath('records.0.id', named('task-1'))
        ->assertJsonPath('records.0.fields.title.value', 'Ship it');

    // A view filters rows; the field whitelist is what bounds columns.
    expect(array_keys($page->json('records.0.fields')))->toBe(['title', 'status', 'meta', 'secret']);
});

it('preserves both proposals and never echoes the client its own value back', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'draft'), setOp('status', 'open')]))->assertOk();
    push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('title', 'from alice')]))->assertOk();

    $conflicted = push($this, 'bob', mutation('m3', 'task-1', 1, 'update', 1, [setOp('title', 'from bob')], 'device-2'));

    $conflicted->assertOk()->assertJsonPath('status', 'conflict');
    expect($conflicted->json('conflict_groups'))->toHaveCount(1);
    expect($conflicted->json('conflicts.0.current.value'))->toBe('from alice');
    expect(json_encode($conflicted->json()))->not->toContain('proposed');
});

it('returns a resume point for a gap instead of an error', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')]))->assertOk();

    $gap = push($this, 'alice', mutation('m9', 'task-9', 3, 'create', 0, [setOp('title', 'c'), setOp('status', 'open')]));

    $gap->assertOk()
        ->assertJsonPath('status', 'mutation_gap')
        ->assertJsonPath('acknowledged_sequence', 1)
        ->assertJsonMissingPath('commit_sequence')
        ->assertJsonMissingPath('record_version');
});

it('replays an identical push without advancing anything', function () {
    $store = app(Store::class);
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')]))->assertOk();
    $watermark = $store->watermark('team-1')->value;

    $first = push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('title', 'b')]));
    $again = push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('title', 'b')]));

    expect($again->json())->toBe($first->json());
    expect($store->watermark('team-1')->value)->toBe($watermark + 1);
});

it('refuses a field the type does not allow, before the engine sees it', function () {
    $store = app(Store::class);
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')]))->assertOk();
    $watermark = $store->watermark('team-1')->value;

    push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('secret', 'mine')]))
        ->assertStatus(403)->assertJsonPath('error', 'field_not_writable');

    // The mutation id must still be usable: a refusal after the engine would
    // burn it forever.
    expect($store->watermark('team-1')->value)->toBe($watermark);
    expect($store->receipt('m2'))->toBeNull();
    push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('title', 'b')]))->assertOk();
});

it('cannot claim another principal\'s replica stream', function () {
    // Both principals send the SAME client replica id and sequence. Unbound,
    // the second would wedge the first's stream permanently.
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open')], 'shared-name'))->assertOk();
    push($this, 'mallory', mutation('m2', 'task-2', 1, 'create', 0, [setOp('title', 'm'), setOp('status', 'open')], 'shared-name'))->assertOk();

    push($this, 'alice', mutation('m3', 'task-3', 2, 'create', 0, [setOp('title', 'b'), setOp('status', 'open')], 'shared-name'))
        ->assertOk()->assertJsonPath('status', 'applied');
});

it('keeps an empty object an object and distinguishes null from unset', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'a'), setOp('status', 'open'), setOp('meta', new stdClass)]))->assertOk();

    // Asserted on the raw bytes: $page->json() decodes associatively, which is
    // the very conversion that would hide the bug being tested for.
    $page = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($page->getContent())->toContain('"meta":{"present":true,"value":{}}');

    push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('meta', null)]))->assertOk()->assertJsonPath('status', 'applied');
    push($this, 'alice', mutation('m3', 'task-1', 3, 'update', 2, [['field' => 'meta', 'op' => 'unset']]))->assertOk()->assertJsonPath('status', 'applied');

    $after = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($after->getContent())->toContain('"meta":{"present":false}');
});

it('refuses unauthenticated, unknown and unreadable requests', function () {
    $this->postJson('/sync/push', ['type' => 'tasks'])->assertStatus(401)->assertJsonPath('error', 'unauthenticated');

    $this->postJson('/sync/bootstrap', ['type' => 'ghosts'], ['X-Test-Principal' => 'alice'])
        ->assertStatus(404)->assertJsonPath('error', 'unknown_type');

    $this->postJson('/sync/bootstrap', ['type' => 'tasks'], ['X-Test-Principal' => 'outsider'])
        ->assertStatus(403)->assertJsonPath('error', 'forbidden');
});

it('refuses a request that is not json', function () {
    $this->call('POST', '/sync/push', [], [], [], ['HTTP_X-Test-Principal' => 'alice', 'CONTENT_TYPE' => 'text/plain'], 'type=tasks')
        ->assertStatus(415)->assertJsonPath('error', 'unsupported_media_type');
});
