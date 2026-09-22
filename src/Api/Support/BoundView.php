<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\CurrentStateView;
use Cbox\Sync\Views\QueryableView;
use Cbox\Sync\Views\ViewDefinition;

/**
 * A view whose signature also names the principal's authorization.
 *
 * SyncPrincipal::$binding is the host's way of saying "this caller's
 * permissions changed". Folding it into the signature changes the cursor
 * context, so every open bootstrap token and every cursor issued before the
 * change is answered with reset_required and the device rebuilds from what it
 * may see now - instead of carrying on with a window cut under the old rules.
 *
 * A principal whose binding is its id - the default - gets the view unchanged,
 * so nothing resets for a host that never uses the binding.
 */
class BoundView implements ViewDefinition
{
    protected function __construct(protected readonly ViewDefinition $view, private readonly string $binding) {}

    public static function to(ViewDefinition $view, SyncPrincipal $principal): ViewDefinition
    {
        if ($principal->binding === $principal->id) {
            return $view;
        }

        // Whatever the view is, it stays: a current-state rule that lost that
        // on the way through judged history by today's row again.
        return match (true) {
            $view instanceof QueryableView && $view instanceof CurrentStateView => new BoundQueryableCurrentStateView($view, $principal->binding),
            $view instanceof QueryableView => new BoundQueryableView($view, $principal->binding),
            $view instanceof CurrentStateView => new BoundCurrentStateView($view, $principal->binding),
            default => new self($view, $principal->binding),
        };
    }

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
        return hash('sha256', $this->view->filterSignature()."\0".$this->binding);
    }

    public function includes(EntityRecord $record): bool
    {
        return $this->view->includes($record);
    }
}
