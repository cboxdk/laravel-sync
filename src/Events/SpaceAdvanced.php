<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A space has something new, up to this sequence.
 *
 * Carries the watermark and nothing else, on purpose. The log is per space, but
 * authorization is per principal and per view: putting the changes in the event
 * would hand every listener - and every broadcast subscriber - everything
 * written in that space, including the rows and fields a given reader is not
 * allowed to see. This says there is something new; the reader then asks
 * through the endpoint that knows who it is.
 *
 * Wire it to whatever you already run. A broadcast on a private per-space
 * channel is the usual answer for a web client, a queued job for a webhook, and
 * nothing at all is fine too - a device that only polls is still correct, just
 * less prompt.
 */
class SpaceAdvanced
{
    use Dispatchable;

    public function __construct(public readonly string $space, public readonly int $watermark) {}
}
