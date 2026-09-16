<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Exceptions;

class UnknownSyncableType extends \RuntimeException
{
    public function __construct(string $message, public readonly string $entityType)
    {
        parent::__construct($message);
    }

    public static function forType(string $entityType): self
    {
        return new self('No syncable type is registered for "'.$entityType.'"', $entityType);
    }

    /** A configured class that is not a SyncableType is treated as unregistered, never silently trusted. */
    public static function misconfigured(string $entityType, string $class): self
    {
        return new self('Configured class '.$class.' for "'.$entityType.'" is not a SyncableType', $entityType);
    }
}
