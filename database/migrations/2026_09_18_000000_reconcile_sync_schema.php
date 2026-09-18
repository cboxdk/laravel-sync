<?php

declare(strict_types=1);

use Cbox\Sync\Persistence\Pdo\PdoSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring an existing installation up to the adapter's current schema.
 *
 * The create migration ran once and will not run again, and CREATE TABLE IF NOT
 * EXISTS does nothing to a table that is already there. So a host that
 * installed an earlier release keeps a schema the adapter can no longer write
 * to - loudly, on the first write after the upgrade.
 *
 * PdoSchema::install() reconciles the difference: it adds columns and indexes
 * the tables are missing and leaves everything else alone. It is idempotent, so
 * running it on an already-current schema does nothing.
 *
 * A future schema addition needs another migration exactly like this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->syncConnection());
        (new PdoSchema($connection->getDriverName()))->install($connection->getPdo());
    }

    /**
     * Deliberately empty. The only thing this added is columns holding data the
     * adapter now writes; dropping them to roll back would discard it, and the
     * create migration's down() already drops the tables outright.
     */
    public function down(): void {}

    private function syncConnection(): ?string
    {
        $name = config('sync.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }
};
