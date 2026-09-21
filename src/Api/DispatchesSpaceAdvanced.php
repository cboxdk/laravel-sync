<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\Sync\ValueObjects\CommitSequence;
use Illuminate\Contracts\Events\Dispatcher;

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
    public function __construct(private readonly Dispatcher $events) {}

    public function committed(string $space, CommitSequence $watermark): void
    {
        $this->events->dispatch(new SpaceAdvanced($space, $watermark->value));
    }
}
