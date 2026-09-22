<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Views\CurrentStateView;

/**
 * A bound view whose rule judges records as they are now. Losing that on the
 * way through the binding made a delete, or a row moving to another owner,
 * reach no device of a principal with a binding - the very principals whose
 * permissions are expected to change.
 */
class BoundCurrentStateView extends BoundView implements CurrentStateView
{
    public function __construct(CurrentStateView $view, string $binding)
    {
        parent::__construct($view, $binding);
    }

    public function spans(EntityRecord $record): bool
    {
        $view = $this->view;

        return $view instanceof CurrentStateView ? $view->spans($record) : throw new \LogicException('Bound view lost its window');
    }
}
