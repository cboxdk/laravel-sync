<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Enums;

/** What a request is asking to do with a syncable type, so one authorize() call can answer for all of it. */
enum SyncAction: string
{
    case Push = 'push';
    case Read = 'read';
}
