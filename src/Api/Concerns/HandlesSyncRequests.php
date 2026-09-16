<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Concerns;

use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\ResetRequired;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mount the sync endpoints in a host's own controller, so routing, naming and
 * middleware stay where the host wants them. The package's own controllers use
 * this same trait.
 */
trait HandlesSyncRequests
{
    /** Supplied by the host from its own promoted constructor property. */
    abstract protected function syncEndpoints(): SyncEndpoints;

    protected function syncPush(Request $request): JsonResponse
    {
        return $this->syncRun(fn (SyncPrincipal $principal): array => $this->syncEndpoints()->push($request, $principal), $request);
    }

    protected function syncBootstrap(Request $request): JsonResponse
    {
        return $this->syncRun(fn (SyncPrincipal $principal): array => $this->syncEndpoints()->bootstrap($request, $principal), $request);
    }

    protected function syncDelta(Request $request): JsonResponse
    {
        return $this->syncRun(fn (SyncPrincipal $principal): array => $this->syncEndpoints()->delta($request, $principal), $request);
    }

    /** @param \Closure(SyncPrincipal): array<string, mixed> $handler */
    private function syncRun(\Closure $handler, Request $request): JsonResponse
    {
        $principal = $request->attributes->get('sync_principal');
        if (! $principal instanceof SyncPrincipal) {
            return $this->syncError(401, 'unauthenticated', 'No authenticated principal for this request');
        }

        try {
            return $this->syncResponse($handler($principal));
        } catch (SyncRequestRejected $rejected) {
            return $this->syncError($rejected->status, $rejected->errorCode, $rejected->getMessage());
        } catch (ResetRequired $reset) {
            return $this->syncError(409, 'reset_required', $reset->getMessage(), ['reason' => $reset->reason->value]);
        } catch (HistoryUnavailable $pruned) {
            // Caught BEFORE ProtocolException, which it extends. The other
            // order swallows it into a terminal protocol error, the client
            // never learns to re-bootstrap, and it retries a dead cursor
            // forever.
            return $this->syncError(409, 'reset_required', $pruned->getMessage(), ['reason' => 'history_pruned']);
        } catch (ProtocolException $protocol) {
            return $this->syncError(409, 'protocol_violation', $protocol->getMessage());
        } catch (InvalidRequest $invalid) {
            return $this->syncError(422, 'invalid_request', $invalid->getMessage());
        } catch (TransientFailure $transient) {
            return $this->syncRetriable($transient->getMessage());
        } catch (QueryException $query) {
            // A space lock that timed out or deadlocked is contention, not a
            // bug, and it is the most common production failure here. Without
            // this it surfaces as a 500 and looks permanent to the client.
            if (! in_array($query->getCode(), ['40001', '40P01', '1213', '1205', 'HY000'], true)) {
                throw $query;
            }

            return $this->syncRetriable('The space is busy; retry the same mutation');
        }
    }

    /** @param array<string, mixed> $body */
    private function syncResponse(array $body, int $status = 200): JsonResponse
    {
        // Bootstrap pages are tenant data; a shared proxy must not keep them.
        $response = new JsonResponse($body, $status, ['Cache-Control' => 'no-store']);

        // PRESERVE_ZERO_FRACTION is not cosmetic. A field value is canonical
        // JSON text and equality is exact, so 1.0 encoded as 1 comes back as an
        // integer, and a client that writes what it read produces a different
        // canonical value - a version bump, and a conflict against anyone who
        // still holds the float.
        $response->setEncodingOptions(JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    private function syncRetriable(string $message): JsonResponse
    {
        // Safe to retry precisely because the engine returns the stored result
        // for a repeated mutation id - so the client must reuse the SAME id.
        return $this->syncResponse(
            ['error' => 'retry', 'message' => $message, 'retriable' => true], 503
        )->withHeaders(['Retry-After' => '1']);
    }

    /** @param array<string, mixed> $extra */
    private function syncError(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return $this->syncResponse(['error' => $code, 'message' => $message, 'retriable' => false] + $extra, $status);
    }
}
