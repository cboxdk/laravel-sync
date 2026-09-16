<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One decision, made before anything reads the body.
 *
 * Requiring application/json is not pedantry: it is what stops a cross-origin
 * form POST from reaching these endpoints as a simple request, which matters
 * because nothing here is CSRF-protected when a host mounts it on cookie auth.
 * The size cap is here for the same reason it cannot be later - the parser and
 * then the engine would hold the tenant-wide space write lock for the duration.
 */
class RequireJsonBody
{
    public function __construct(private readonly int $maxBytes = 262144) {}

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        if (! $request->isJson()) {
            return $this->refuse(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }
        $length = $request->headers->get('Content-Length');
        if ((is_string($length) && (int) $length > $this->maxBytes) || strlen($request->getContent()) > $this->maxBytes) {
            return $this->refuse(413, 'body_too_large', 'Request body exceeds the configured limit');
        }

        return $next($request);
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message, 'retriable' => false], $status, ['Cache-Control' => 'no-store']);
    }
}
