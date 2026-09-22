<?php

declare(strict_types=1);
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Testing\TestResponse;

/** The read half: paging a bootstrap, following deltas, and resolving a conflict. */
function seedTask(object $test, string $id, int $sequence, string $status = 'open'): void
{
    push($test, 'alice', mutation('seed-'.$id, $id, $sequence, 'create', 0, [
        setOp('title', 'task '.$id), setOp('status', $status),
    ]))->assertOk();
}

function read(object $test, string $endpoint, array $body): TestResponse
{
    return $test->postJson('/sync/'.$endpoint, ['type' => 'tasks', 'scope' => 'team-1'] + $body, [
        'X-Test-Principal' => 'alice',
    ]);
}

it('pages a bootstrap and hands over a cursor only on the last page', function () {
    foreach (['t1', 't2', 't3'] as $index => $id) {
        seedTask($this, $id, $index + 1);
    }

    $seen = [];
    $pages = 0;
    $body = ['page_size' => 2];
    do {
        $page = read($this, 'bootstrap', $body)->assertOk();
        foreach ($page->json('records') as $record) {
            $seen[] = $record['id'];
        }
        $pages++;
        $body = ['token' => $page->json('next_token')];
    } while ($page->json('next_token') !== null);

    // Each record exactly once, and in the order the store pages them. The
    // names are the server's, so the order is theirs too - what this pins is
    // that paging neither skips nor repeats, which is the property a keyset
    // cursor exists to give.
    $expected = array_map(named(...), ['t1', 't2', 't3']);
    sort($expected);
    $sorted = $seen;
    sort($sorted);

    expect($sorted)->toBe($expected);
    expect($seen)->toBe(array_values(array_unique($seen)));
    expect($pages)->toBe(2);
    expect($page->json('complete'))->toBeTrue();
    expect($page->json('cursor.position'))->toBeInt();
});

it('delivers later changes through delta, including leaving the view', function () {
    seedTask($this, 't1', 1);
    $cursor = read($this, 'bootstrap', [])->assertOk()->json('cursor');

    push($this, 'alice', mutation('u1', 't1', 2, 'update', 1, [setOp('title', 'renamed')]))->assertOk();
    $delta = read($this, 'delta', ['cursor' => $cursor])->assertOk();

    expect($delta->json('commits.0.changes.0.kind'))->toBe('upsert');
    expect($delta->json('commits.0.changes.0.record.fields.title.value'))->toBe('renamed');

    // Leaving the view is a removal with no record attached.
    push($this, 'alice', mutation('u2', 't1', 3, 'update', 2, [setOp('status', 'done')]))->assertOk();
    $next = read($this, 'delta', ['cursor' => $delta->json('cursor')])->assertOk();

    expect($next->json('commits.0.changes.0.kind'))->toBe('removed_from_scope');
    expect($next->json('commits.0.changes.0'))->not->toHaveKey('record');
});

it('never puts provenance on the wire', function () {
    seedTask($this, 't1', 1);
    $cursor = read($this, 'bootstrap', [])->assertOk()->json('cursor');
    push($this, 'alice', mutation('u1', 't1', 2, 'update', 1, [setOp('title', 'renamed')]))->assertOk();

    $body = read($this, 'delta', ['cursor' => $cursor])->assertOk()->getContent();

    // Each field's origin names the actor who wrote it; none of it may escape.
    foreach (['actor', 'integration', 'mutation_id', 'replica', 'provenance'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('refuses a cursor built for a different context', function () {
    seedTask($this, 't1', 1);
    $cursor = read($this, 'bootstrap', [])->assertOk()->json('cursor');

    // A forged or stale fingerprint is answered the same way a rotated epoch is:
    // this view's local state can no longer be trusted, so rebuild it.
    read($this, 'delta', ['cursor' => ['position' => $cursor['position'], 'context' => str_repeat('0', 64)]])
        ->assertStatus(409)
        ->assertJsonPath('error', 'reset_required')
        ->assertJsonPath('reason', 'context_changed');
});

it('resolves a preserved conflict using what the push response returned', function () {
    seedTask($this, 't1', 1);
    push($this, 'alice', mutation('a2', 't1', 2, 'update', 1, [setOp('title', 'from alice')]))->assertOk();

    $conflict = push($this, 'bob', mutation('b1', 't1', 1, 'update', 1, [setOp('title', 'from bob')], 'device-2'))
        ->assertOk()->assertJsonPath('status', 'conflict');

    $group = $conflict->json('conflict_groups.0');
    expect($group['field'])->toBe('title');
    expect($group['candidate_ids'])->toHaveCount(2);
    expect($group['open'])->toBeTrue();

    $resolved = $this->postJson('/sync/push', [
        'type' => 'tasks', 'scope' => 'team-1',
        'mutation_id' => 'r1', 'id' => named('t1'), 'replica' => 'device-1',
        'sequence' => 3, 'kind' => 'resolve', 'base_version' => 2,
        'operations' => [setOp('title', 'agreed')],
        'resolution' => [
            'group_id' => $group['id'],
            'group_revision' => $group['revision'],
            'candidate_ids' => $group['candidate_ids'],
        ],
    ], ['X-Test-Principal' => 'alice']);

    $resolved->assertOk()->assertJsonPath('status', 'applied');
    expect(read($this, 'bootstrap', [])->json('records.0.fields.title.value'))->toBe('agreed');
});

it('reports a rotated epoch as a reset the client can act on', function () {
    seedTask($this, 't1', 1);
    $cursor = read($this, 'bootstrap', [])->assertOk()->json('cursor');

    config(['sync.epoch' => 'epoch-2']);
    app()->forgetInstance(ViewSyncService::class);

    // Not "invalid cursor": a client cannot act on that, and would present the
    // same dead cursor forever.
    read($this, 'delta', ['cursor' => $cursor])
        ->assertStatus(409)
        ->assertJsonPath('error', 'reset_required')
        ->assertJsonPath('reason', 'context_changed');
});

/**
 * SyncPrincipal::$binding promised that a permission change invalidates open
 * bootstraps and cursors. It was read nowhere, so a device carried on with a
 * window cut under the old rules.
 */
it('makes a device rebuild when its principal\'s authorization changes', function () {
    $this->postJson('/sync/push', ['type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'm1', 'id' => 'h', 'replica' => 'd', 'sequence' => 1, 'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'x'], ['field' => 'status', 'op' => 'set', 'value' => 'open']]], ['X-Test-Principal' => 'alice'])->assertOk();

    $page = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1', 'page_size' => 10], ['X-Test-Principal' => 'alice', 'X-Test-Binding' => 'v1'])->assertOk();
    $cursor = $page->json('cursor');

    $this->postJson('/sync/delta', ['type' => 'tasks', 'scope' => 'team-1', 'cursor' => $cursor], ['X-Test-Principal' => 'alice', 'X-Test-Binding' => 'v1'])->assertOk();
    $this->postJson('/sync/delta', ['type' => 'tasks', 'scope' => 'team-1', 'cursor' => $cursor], ['X-Test-Principal' => 'alice', 'X-Test-Binding' => 'v2'])
        ->assertStatus(409)->assertJsonPath('error', 'reset_required');
});
