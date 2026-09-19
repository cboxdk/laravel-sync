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
use Illuminate\Database\DetectsConcurrencyErrors;
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
    use DetectsConcurrencyErrors;

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
            //
            // Laravel's own detector rather than a SQLSTATE list: a lock wait
            // timeout is HY000 on MySQL, and so is a missing column default and
            // a missing table. Treating that code as contention told the client
            // to retry a schema mistake for ever, against production, with
            // nothing surfacing anywhere.
            if (! $this->causedByConcurrencyError($query)) {
                throw $query;
            }

            return $this->syncRetriable('The space is busy; retry the same mutation');
        }
    }

    /** @param array<string, mixed> $body */
    private function syncResponse(array $body, int $status = 200): JsonResponse
    {
        // Bootstrap pages are tenant data; a shared proxy must not keep them.
        // Encoded here, and handed over already encoded.
        //
        // PRESERVE_ZERO_FRACTION is not cosmetic: a field value is canonical
        // JSON text and equality is exact, so a float 1.0 written as 1 comes
        // back an integer, and a client that writes back what it read produces
        // a different canonical value - a version bump out of nowhere and a
        // conflict against every device still holding the float.
        //
        // setEncodingOptions() cannot do this. It decodes the ALREADY encoded
        // payload and re-encodes it, by which point the fraction is gone.
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, $status, ['Cache-Control' => 'no-store'], json: true);
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
