<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Support\IdentityBinding;
use Cbox\Sync\Laravel\Api\SyncService;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Persistence\InMemoryStore;
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

it('refuses a request that is not json', function (string $contentType) {
    $this->call('POST', '/sync/push', [], [], [], ['HTTP_X-Test-Principal' => 'alice', 'CONTENT_TYPE' => $contentType], '{"type":"tasks"}')
        ->assertStatus(415)->assertJsonPath('error', 'unsupported_media_type');
})->with([
    'plain text' => 'text/plain',
    // Request::isJson() accepts these: "+json" anywhere in the header. Each is
    // a type a browser sends cross-origin without asking first.
    'json smuggled into a parameter' => 'text/plain; charset=+json',
    'json smuggled into a form' => 'application/x-www-form-urlencoded; x=/json',
    'multipart' => 'multipart/form-data; boundary=+json',
]);

it('accepts json and structured json types', function (string $contentType) {
    $this->call('POST', '/sync/push', [], [], [], ['HTTP_X-Test-Principal' => 'alice', 'CONTENT_TYPE' => $contentType], '{"type":"ghosts"}')
        ->assertStatus(404);
})->with(['application/json', 'application/json; charset=utf-8', 'Application/JSON', 'application/vnd.api+json']);

/**
 * A device that asked to decide conflicts itself: refused, caught up, sent
 * again under the same identity, landed - and no conflict group anywhere.
 */
it('refuses a stale write, then accepts it rebased on what the refusal reported', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'draft'), setOp('status', 'open')]))->assertOk();
    push($this, 'alice', mutation('m2', 'task-1', 2, 'update', 1, [setOp('title', 'from alice')]))->assertOk();

    $stale = mutation('b1', 'task-1', 1, 'update', 1, [setOp('title', 'from bob'), setOp('meta', 'noted')], 'device-2', 'bob') + ['on_conflict' => 'pull'];
    $refused = push($this, 'bob', $stale)->assertOk()->assertJsonPath('status', 'pull_required');

    $store = app(Store::class);
    // Nothing was stored for it, not even the fresh field.
    expect($store->receipt(IdentityBinding::mutationId(new SyncPrincipal('bob', 'bob'), 'b1')))->toBeNull()
        ->and($refused->json('commit_sequence'))->toBeNull()
        ->and($refused->json('acknowledged_sequence'))->toBe(0);
    $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1', 'page_size' => 10], ['X-Test-Principal' => 'bob'])
        ->assertJsonPath('records.0.fields.meta.present', false);

    // Same mutation id and sequence, based on the version the refusal named.
    $rebased = array_merge($stale, ['base_version' => $refused->json('record_version')]);
    push($this, 'bob', $rebased)->assertOk()
        ->assertJsonPath('status', 'applied')
        ->assertJsonPath('conflict_groups', [])
        ->assertJsonPath('acknowledged_sequence', 1);

    $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1', 'page_size' => 10], ['X-Test-Principal' => 'bob'])
        ->assertJsonPath('records.0.fields.title.value', 'from bob')
        ->assertJsonPath('records.0.fields.meta.value', 'noted');
});

it('refuses an on_conflict it does not know', function () {
    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'draft')]) + ['on_conflict' => 'overwrite'])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_request');
});

/** A store whose space lock fails the way a database does. */
function storeFailingWith(string $message): void
{
    $failing = new class($message) extends InMemoryStore
    {
        public function __construct(private string $failure)
        {
            parent::__construct();
        }

        public function transaction(string $space, Closure $callback): mixed
        {
            throw new PDOException($this->failure);
        }
    };
    app()->instance(Store::class, $failing);
    foreach ([SyncEndpoints::class, SyncService::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/**
 * The sync store runs on the raw PDO handle, so a lock wait on the space row
 * is a bare PDOException - which skipped the contention check and answered 500.
 * Two devices writing to one busy space on MySQL got an error that looked
 * permanent.
 */
it('answers a lock wait on the space as busy, so the client retries the same write', function () {
    storeFailingWith('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');

    push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'x')]))
        ->assertStatus(503)
        ->assertJsonPath('error', 'retry')
        ->assertJsonPath('retriable', true);
});

it('does not dress a schema mistake up as contention', function () {
    storeFailingWith("SQLSTATE[HY000]: General error: 1364 Field 'payload' doesn't have a default value");

    $this->withoutExceptionHandling();
    expect(fn () => push($this, 'alice', mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'x')])))
        ->toThrow(PDOException::class, 'default value');
});

/**
 * One 200,000-character id used to produce a continuation token too large to
 * post back, and every device bootstrapping the view was stuck for good.
 */
it('refuses an oversized identifier before it reaches anything', function (string $field) {
    $body = mutation('m1', 'task-1', 1, 'create', 0, [setOp('title', 'x')]);
    $body[$field] = str_repeat('x', 151);

    push($this, 'alice', $body)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
})->with(['id', 'mutation_id', 'replica']);

it('refuses an oversized id on an update too', function () {
    $body = mutation('m2', 'task-1', 1, 'update', 1, [setOp('title', 'x')]);
    $body['id'] = str_repeat('y', 200000);

    push($this, 'alice', $body)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
});
