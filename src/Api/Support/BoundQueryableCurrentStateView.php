<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Views\CurrentStateView;
use Cbox\Sync\Views\QueryableView;

/** The same, for a current-state view the store can also narrow by. */
class BoundQueryableCurrentStateView extends BoundQueryableView implements CurrentStateView
{
    public function __construct(QueryableView&CurrentStateView $view, string $binding)
    {
        parent::__construct($view, $binding);
    }

    public function spans(EntityRecord $record): bool
    {
        $view = $this->view;

        return $view instanceof CurrentStateView ? $view->spans($record) : throw new \LogicException('Bound view lost its window');
    }
}
