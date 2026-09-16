<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Cbox\Sync\Persistence\Pdo\PdoSchema;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * The durable sync store on one of the application's own database connections.
 *
 * Transaction control is delegated to Laravel rather than issued as raw SQL, so
 * the framework's nesting counter stays correct and a host that wraps sync in
 * its own DB::transaction() gets a savepoint instead of a broken commit. The
 * space write lock, the gapless commit sequence and the ledger all come from
 * the framework-independent adapter unchanged.
 */
class IlluminateStore extends PdoStore
{
    public function __construct(private Connection $db, ?PdoSchema $schema = null)
    {
        $pdo = $db->getPdo();
        parent::__construct($pdo, $schema);
    }

    public function connection(): ConnectionInterface
    {
        return $this->db;
    }

    protected function begin(): void
    {
        $this->db->beginTransaction();
        if ($this->schema->driver === PdoSchema::SQLITE) {
            // Laravel opens SQLite transactions deferred, which would let two
            // readers race to the same commit sequence. Take the write lock now.
            $this->connection->exec('UPDATE sync_spaces SET commit_sequence = commit_sequence WHERE 1 = 0');
        }
    }

    protected function commit(): void
    {
        $this->db->commit();
    }

    protected function rollback(): void
    {
        $this->db->rollBack();
    }
}
