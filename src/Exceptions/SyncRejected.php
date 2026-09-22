<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Exceptions;

use Cbox\Sync\Data\MutationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The log refused this write: the host's validator said no, or the record it
 * targets no longer exists.
 *
 * Raised out of an ordinary save for the same reason a conflict is: the log and
 * the table must not disagree. Returning quietly left the table holding a value
 * the log had refused, which every device would then contradict.
 */
class SyncRejected extends \RuntimeException
{
    /** @param list<array{code: string, field: ?string}> $failures */
    public function __construct(
        public readonly string $reason,
        public readonly array $failures = [],
    ) {
        parent::__construct('The write was refused: '.$reason.'.');
    }

    public static function from(MutationResult $result): self
    {
        $failures = [];
        foreach ($result->validation->failures ?? [] as $failure) {
            $failures[] = ['code' => $failure->code, 'field' => $failure->field];
        }

        return new self($result->reason ?? $result->status->value, $failures);
    }

    public function render(Request $request): Response
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
            'errors' => $this->failures,
        ], 422);
    }
}
