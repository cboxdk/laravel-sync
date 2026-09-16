<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Exceptions;

/**
 * A request the transport itself refuses, before the engine is involved.
 *
 * The machine code travels with the exception so a controller only has to echo
 * it: clients branch on the code, and a message that drifts must never change
 * what a client does.
 */
class SyncRequestRejected extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function missing(string $field): self
    {
        return new self('Missing or malformed field: '.$field, 'invalid_request');
    }

    public static function malformedBody(): self
    {
        return new self('Request body must be a JSON object', 'invalid_request');
    }

    public static function unwritableField(string $field): self
    {
        // Refused rather than dropped: a silently ignored write leaves the
        // client believing it saved something it did not.
        return new self('Field is not writable for this type: '.$field, 'field_not_writable', 403);
    }

    public static function forbidden(): self
    {
        return new self('Not authorized for this syncable type', 'forbidden', 403);
    }

    public static function unknownType(string $entityType): self
    {
        return new self('No syncable type is registered for "'.$entityType.'"', 'unknown_type', 404);
    }

    public static function badCursor(): self
    {
        return new self('Cursor is unknown, expired or tampered with', 'invalid_cursor', 409);
    }
}
