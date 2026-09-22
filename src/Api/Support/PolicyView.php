<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Views\QueryableView;

/**
 * A model's window, narrowed by its policy's `view` rule, row by row.
 *
 * viewAny decides whether a caller may read the type at all; this decides
 * which rows. It applies to bootstrap pages, to every change a delta carries,
 * and to what a push may disclose about a conflict, so a row the policy hides
 * reaches the device by no path.
 *
 * The rule is asked about the real row, with sync's values over it, so a rule
 * reading a column devices never see still hides what it hides on REST. That
 * costs a read per row judged - remembered for the life of the view, so a row
 * judged before and after a change in one delta is read once. A row that no
 * longer exists is visible: its delete is all there is left to send.
 *
 * The rule's answer is folded into membership when a row changes. A change to
 * the rule itself - a user losing access to a project - is not a change to any
 * row, so it reaches devices through SyncPrincipal::$binding, which makes them
 * rebuild their window under the new rule.
 */
class PolicyView implements QueryableView
{
    /** @param \Closure(EntityRecord): bool $allows */
    public function __construct(
        private readonly QueryableView $view,
        private readonly \Closure $allows,
        private readonly string $rule,
    ) {}

    public function id(): string
    {
        return $this->view->id();
    }

    public function filterVersion(): string
    {
        return $this->view->filterVersion();
    }

    public function filterSignature(): string
    {
        return hash('sha256', $this->view->filterSignature()."\0policy\0".$this->rule);
    }

    public function criteria(): RecordCriteria
    {
        return $this->view->criteria();
    }

    /** @var array<string, bool> the rule's answer per record version */
    private array $answers = [];

    public function includes(EntityRecord $record): bool
    {
        if (! $this->view->includes($record)) {
            return false;
        }
        $key = $record->entity->key()."\0".$record->version->value;

        return $this->answers[$key] ??= ($this->allows)($record);
    }
}
