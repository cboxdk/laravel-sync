<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Broadcasting;

use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\Sync\Laravel\Events\SpaceChanged;
use Illuminate\Contracts\Events\Dispatcher;

/** Turns the commit signal into a broadcast, when the host asked for one. */
class BroadcastSpaceAdvanced
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(SpaceAdvanced $event): void
    {
        $this->events->dispatch(new SpaceChanged($event->space, $event->watermark));
    }
}
