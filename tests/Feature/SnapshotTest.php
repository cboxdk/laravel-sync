<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Laravel\IlluminateStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Support\Facades\DB;

/**
 * MySQL fixes a transaction's snapshot at its first read. When the engine runs
 * as a savepoint inside the host's transaction, that read can come before the
 * space lock - and the ledger numbered its commit from a snapshot older than
 * another writer's commit, so concurrent writers to one tenant mostly failed
 * on the commits primary key. Reproduced with two connections in one process.
 */
it('numbers its commit from the latest state even inside a transaction that read earlier', function () {
    if (config('database.connections.sync-testing.driver') !== 'mysql') {
        $this->markTestSkipped('The snapshot behaviour is MySQL\'s.');
    }
    config()->set('database.connections.other', config('database.connections.sync-testing'));
    $mine = new Engine(new IlluminateStore(DB::connection('sync-testing')));
    $theirs = new Engine(new IlluminateStore(DB::connection('other')));
    $write = fn (string $id, string $replica) => new Mutation($id, new EntityKey('team-1', 'notes', $id), new Replica($replica), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [FieldOperation::set('title', $id)]);
    $mine->process($write('seed', 'seed'));

    $result = DB::connection('sync-testing')->transaction(function () use ($mine, $theirs, $write) {
        // The host reads something first: the snapshot is fixed here.
        DB::connection('sync-testing')->table('sync_commits')->count();
        // Another writer commits in the same space.
        $theirs->process($write('theirs', 'other-device'));

        return $mine->process($write('mine', 'this-device'));
    });

    expect($result->status)->toBe(MutationStatus::Applied)
        ->and($result->commitSequence?->value)->toBe(3);
});
