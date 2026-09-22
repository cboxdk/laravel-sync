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
        parent::__construct($db->getPdo(), $schema);
    }

    /**
     * Always the connection's CURRENT handle.
     *
     * Laravel replaces its PDO on reconnect, and under a long-running worker
     * this store outlives the connection that built it. Capturing the handle
     * once would open the transaction on the framework's new connection while
     * every write went to the dead one, and the rollback would roll back
     * nothing - partial persistence with no error raised anywhere.
     */
    protected function connection(): \PDO
    {
        return $this->db->getPdo();
    }

    public function databaseConnection(): ConnectionInterface
    {
        return $this->db;
    }

    /** Inside the host's own transaction the isolation is the host's; see PdoLedger. */
    private bool $nested = false;

    protected function begin(): void
    {
        $this->nested = $this->db->transactionLevel() > 0;
        if (! $this->nested && $this->schema->driver === PdoSchema::MYSQL) {
            // Read committed, as PdoStore does for its own transactions: no
            // stale snapshot, no gap locks across spaces.
            $this->connection()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->db->beginTransaction();
        if ($this->schema->driver === PdoSchema::SQLITE) {
            // Laravel opens SQLite transactions deferred, which would let two
            // readers race to the same commit sequence. Take the write lock now.
            $this->connection()->exec('UPDATE sync_spaces SET commit_sequence = commit_sequence WHERE 1 = 0');
        }
    }

    protected function needsLockingReads(): bool
    {
        return $this->nested;
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
