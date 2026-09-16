<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Illuminate\Http\Request;

interface SyncPrincipals
{
    /** Null means unauthenticated, which the transport answers with 401. */
    public function resolve(Request $request): ?SyncPrincipal;
}
