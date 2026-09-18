<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;

/**
 * Re-checks write authorization inside the storage transaction.
 *
 * The gate in front of the engine reads the record without the space lock
 * held, so anything it decides from record state can be stale by the time the
 * write lands. A record owned by Alice can be transferred to Bob in between,
 * and Alice's update then merges cleanly because her own field did not change.
 *
 * A validator runs inside the transaction, on the record the engine actually
 * locked, so the two observe the same state. The outer gate stays because it
 * answers a clean 403 without spending a mutation identity; this one is the
 * authority.
 */
class AuthorizesInsideTransaction implements EntityValidator
{
    public function __construct(
        private readonly EntityValidator $inner,
        private readonly SyncableType $type,
        private readonly SyncPrincipal $principal,
    ) {}

    public function validate(ValidationContext $context): ValidationResult
    {
        if (! $this->type->mayWrite($this->principal, $context->previous, $context->mutation->kind)) {
            return new ValidationResult([
                new ValidationFailure('not_authorized', 'Not authorized to write this record'),
            ]);
        }

        return $this->inner->validate($context);
    }
}
