<?php

declare(strict_types=1);

use Cbox\Sync\Persistence\Pdo\PdoSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The schema is owned by the framework-independent adapter, so the table shapes
 * cannot drift between a Laravel host and any other one. This migration applies
 * that schema on whichever connection the package is configured to use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->syncConnection());
        (new PdoSchema($connection->getDriverName()))->install($connection->getPdo());
    }

    public function down(): void
    {
        $connection = DB::connection($this->syncConnection());
        foreach (['sync_commits', 'sync_conflict_groups', 'sync_fields', 'sync_records', 'sync_receipts', 'sync_streams', 'sync_spaces'] as $table) {
            $connection->statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    private function syncConnection(): ?string
    {
        $name = config('sync.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }
};
