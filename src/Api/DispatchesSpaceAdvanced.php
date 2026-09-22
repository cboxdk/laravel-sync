<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\Sync\ValueObjects\CommitSequence;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the engine's commit signal into an ordinary Laravel event.
 *
 * The engine is framework-independent and will not reach for a dispatcher, so
 * this is the one place the two meet. It does nothing else: choosing between a
 * broadcast, a webhook, a queued job or nothing at all is the host's decision,
 * and the package has no business making it for them.
 */
class DispatchesSpaceAdvanced implements CommitObserver
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    /**
     * Announced once the OUTERMOST transaction on the sync connection commits.
     *
     * The engine's own transaction is only a savepoint when the host has one
     * open, and finishing it commits nothing. Announcing then told devices
     * about writes the host went on to roll back - and a device that pulled at
     * once found nothing and was never told again. Deferred here rather than
     * by marking the event, so a listener that throws is still caught below
     * instead of escaping from the host's own DB::transaction() after it has
     * already committed.
     */
    public function committed(string $space, CommitSequence $watermark): void
    {
        $announce = fn () => $this->announce($space, $watermark);
        if ($this->connection instanceof Connection) {
            try {
                // Runs at once when no transaction is open.
                $this->connection->afterCommit($announce);

                return;
            } catch (\RuntimeException) {
                // A connection built outside an application has no
                // transaction manager; there is nothing to wait for.
            }
        }
        $announce();
    }

    private function announce(string $space, CommitSequence $watermark): void
    {
        try {
            $this->events->dispatch(new SpaceAdvanced($space, $watermark->value));
        } catch (\Throwable $failure) {
            // The engine will not let this reach the caller - the write already
            // happened and failing the push would only make the client retry
            // into its own receipt. That makes this the only place it can be
            // seen at all, so a listener that throws has to leave a trace here
            // or a silently broken notifier looks exactly like a quiet tenant.
            $this->log->error('A sync change notification failed.', [
                'space' => $space,
                'watermark' => $watermark->value,
                'exception' => $failure,
            ]);
        }
    }
}
