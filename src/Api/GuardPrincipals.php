<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Illuminate\Http\Request;

/**
 * The default: whoever the host's own auth middleware already authenticated.
 *
 * The binding defaults to the same identifier, which means a permission change
 * does NOT invalidate an open bootstrap. There is no generic way to observe
 * that, so a host whose permissions can be revoked mid-session should bind this
 * to its own permission version and rebind it to this contract.
 */
class GuardPrincipals implements SyncPrincipals
{
    public function resolve(Request $request): ?SyncPrincipal
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $id = $user->getAuthIdentifier();
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return new SyncPrincipal((string) $id, (string) $id);
    }
}
