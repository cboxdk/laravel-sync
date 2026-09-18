<?php

declare(strict_types=1);
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Illuminate\Testing\TestResponse;

/**
 * A lost response is the normal case on a mobile network, not an edge case.
 * Retrying must reach the same answer even when the policy would now refuse
 * the write, because the write already happened.
 */
function fileMutation(string $id, int $sequence, string $kind, int $base, array $operations = []): array
{
    return [
        'mutation_id' => 'm'.$sequence, 'id' => $id, 'replica' => 'device-1',
        'sequence' => $sequence, 'kind' => $kind, 'base_version' => $base,
        'operations' => $operations,
    ];
}

function pushFile(object $test, array $mutation): TestResponse
{
    return $test->postJson('/sync/push', ['type' => 'files', 'scope' => 'team-1'] + $mutation, [
        'X-Test-Principal' => 'alice',
    ]);
}

it('answers a retried delete from its receipt even though the policy now refuses deleted records', function () {
    pushFile($this, fileMutation('f1', 1, 'create', 0, [setOp('name', 'notes.txt'), setOp('state', 'live')]))->assertOk();

    $delete = pushFile($this, fileMutation('f1', 2, 'delete', 1));
    $delete->assertOk()->assertJsonPath('status', 'applied');

    // The client never saw that response. It retries the identical mutation.
    // The policy now sees a tombstone and would refuse - but the write is
    // already committed, and refusing the answer only strands the client.
    $retry = pushFile($this, fileMutation('f1', 2, 'delete', 1));
    $retry->assertOk();
    expect($retry->json())->toBe($delete->json());

    // And the stream is not poisoned: the next write still goes through.
    pushFile($this, fileMutation('f2', 3, 'create', 0, [setOp('name', 'other.txt'), setOp('state', 'live')]))
        ->assertOk()->assertJsonPath('status', 'applied');
});

it('still refuses a first-time write the policy rejects', function () {
    pushFile($this, fileMutation('f1', 1, 'create', 0, [setOp('name', 'notes.txt'), setOp('state', 'live')]))->assertOk();
    pushFile($this, fileMutation('f1', 2, 'delete', 1))->assertOk();

    // A genuinely new mutation against the tombstone is refused as it should be.
    pushFile($this, fileMutation('f1', 3, 'update', 2, [setOp('name', 'resurrected')]))
        ->assertStatus(403)->assertJsonPath('error', 'forbidden');
});

it('re-checks authorization inside the transaction, not only in front of it', function () {
    $push = fn (array $mutation) => $this->postJson('/sync/push', ['type' => 'racing', 'scope' => 'team-1'] + $mutation, [
        'X-Test-Principal' => 'alice',
    ]);

    $push(fileMutation('r1', 1, 'create', 0, [setOp('name', 'contested'), setOp('state', 'live')]))->assertOk();

    // The gate in front of the engine passes; by the time the write holds the
    // space lock, the record is no longer the caller's to change. The engine's
    // own validator is what observes the locked state, so this must not apply.
    $late = $push(fileMutation('r1', 2, 'update', 1, [setOp('name', 'sneaked in')]));

    $late->assertOk()->assertJsonPath('status', 'validation_failed');
    expect($late->json('validation.0.code'))->toBe('not_authorized');

    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'racing', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice']);
    expect($bootstrap->json('records.0.fields.name.value'))->toBe('contested');
});
