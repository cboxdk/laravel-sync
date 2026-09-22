<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Views\QueryableView;

/** The same, for a view the store can narrow by - so binding it costs no index. */
class BoundQueryableView extends BoundView implements QueryableView
{
    public function __construct(QueryableView $view, string $binding)
    {
        parent::__construct($view, $binding);
    }

    public function criteria(): RecordCriteria
    {
        $view = $this->view;

        return $view instanceof QueryableView ? $view->criteria() : throw new \LogicException('Bound view lost its criteria');
    }
}
