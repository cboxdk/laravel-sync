<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Laravel\Api\Enums\SyncAction;
use Cbox\Sync\Views\ViewDefinition;
use Illuminate\Http\Request;

/**
 * A host's declaration that one entity type may be reached over the sync API.
 *
 * This is the whole authorization surface. The engine checks protocol
 * correctness and nothing else: it has no idea who is calling or what they are
 * allowed to touch. Everything that keeps one tenant's data away from another
 * lives behind this interface.
 */
interface SyncableType
{
    /** The `EntityKey` type this declaration covers, and the value clients send as `type`. */
    public function entityType(): string;

    /**
     * The space this request operates in, derived from the authenticated
     * session.
     *
     * NEVER read this from the request body. The space is the consistency and
     * isolation boundary, so a client that can name its own space can read and
     * write every other tenant's data.
     */
    public function space(Request $request): string;

    /**
     * Fields a client may write, deny-by-default.
     *
     * A field absent from this list is refused outright rather than dropped:
     * silently ignoring a write means the client believes it saved something it
     * did not, which is worse than an error.
     *
     * @return list<string>
     */
    public function writableFields(): array;

    /** The filtered view this caller receives on bootstrap and delta. */
    public function view(Request $request): ViewDefinition;

    /** Refuse anything not explicitly allowed. Returning false yields 403. */
    public function authorize(Request $request, SyncAction $action): bool;
}
