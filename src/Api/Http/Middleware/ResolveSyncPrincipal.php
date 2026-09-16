<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Http\Middleware;

use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the authenticated principal on the request, or refuses.
 *
 * Appended by the provider after whatever the host configured, so the endpoint
 * fails closed no matter what that configuration is.
 */
class ResolveSyncPrincipal
{
    public function __construct(private readonly SyncPrincipals $principals) {}

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return new JsonResponse(
                ['error' => 'unauthenticated', 'message' => 'No authenticated principal for this request', 'retriable' => false],
                401, ['Cache-Control' => 'no-store'],
            );
        }
        $request->attributes->set('sync_principal', $principal);

        return $next($request);
    }
}
