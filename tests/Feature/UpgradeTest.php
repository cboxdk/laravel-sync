<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Support\Facades\DB;

/**
 * A host upgrading from an earlier release has already run the package's
 * migrations, so `php artisan migrate` had nothing new to run - and every write
 * after the upgrade failed on the receipt columns cboxdk/sync 0.9 needs. The
 * reconcile migration brings that schema up to date.
 */
it('brings a schema from before stream positions up to date', function () {
    if (config('database.connections.sync-testing.driver') !== 'sqlite') {
        $this->markTestSkipped('Dropping columns to recreate the old schema is simplest on SQLite.');
    }
    $db = DB::connection('sync-testing');
    $db->statement('DROP INDEX IF EXISTS sync_receipts_stream_position');
    $db->statement('ALTER TABLE sync_receipts DROP COLUMN sequence');
    $db->statement('ALTER TABLE sync_receipts DROP COLUMN replica_id');

    (require dirname(__DIR__, 2).'/database/migrations/2026_09_22_000000_reconcile_sync_schema_for_sync_0_9.php')->up();

    $result = app(Engine::class)->recordTrusted(new EntityKey('team', 'notes', 'n1'), new Replica('server'), [Op::set('title', 'after the upgrade')], false);
    expect($result?->status)->toBe(MutationStatus::Applied);
});
