<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Exceptions;

use Cbox\Sync\Data\MutationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write was made against a version of the record that has since moved.
 *
 * Thrown out of an ordinary save, so an existing controller needs no branch:
 * the write either happened or this was raised, and Laravel renders it as a
 * 409 carrying what the record says now. A client that sent a base version
 * asked for exactly this - a write without one never conflicts.
 */
class SyncConflict extends \RuntimeException
{
    /** @param list<array{field: string, current: mixed, proposed: mixed}> $conflicts */
    public function __construct(
        public readonly array $conflicts,
        public readonly int $currentVersion,
        public readonly bool $preconditionFailed = false,
    ) {
        parent::__construct($preconditionFailed
            ? 'The record has changed since the version this write expected.'
            : 'The record changed while this write was being made.');
    }

    public static function from(MutationResult $result): self
    {
        $conflicts = [];
        foreach ($result->conflicts as $conflict) {
            $conflicts[] = [
                'field' => $conflict->field,
                'current' => $conflict->current->value(),
                'proposed' => $conflict->proposed->value(),
            ];
        }

        return new self(
            $conflicts,
            $result->recordVersion->value,
            $result->preconditionFailure !== null,
        );
    }

    public function render(Request $request): Response
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'version' => $this->currentVersion,
            'conflicts' => $this->conflicts,
        ], $this->preconditionFailed ? 412 : 409);
    }
}
