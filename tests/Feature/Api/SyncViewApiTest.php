<?php

declare(strict_types=1);
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

    expect($seen)->toBe(['t1', 't2', 't3']);
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

    read($this, 'delta', ['cursor' => ['position' => $cursor['position'], 'context' => str_repeat('0', 64)]])
        ->assertStatus(409)->assertJsonPath('error', 'invalid_cursor');
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
        'mutation_id' => 'r1', 'id' => 't1', 'replica' => 'device-1',
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
