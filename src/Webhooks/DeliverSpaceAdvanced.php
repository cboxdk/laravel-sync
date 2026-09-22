<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Webhooks;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\WebhookSignature\Contracts\Webhooks;
use Cbox\WebhookSignature\ValueObjects\Headers;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;

/**
 * Tells a server-to-server consumer that a space advanced.
 *
 * Queued, because a delivery must never be on the write's critical path: the
 * commit already happened, and a slow or dead receiver is not the writer's
 * problem. A failure here costs promptness, not correctness - the reader's
 * cursor is what makes it correct.
 *
 * The payload is the watermark and nothing else. The log is per space and
 * authorization is per principal and per view, so a body carrying the changes
 * would hand a receiver everything written in that space, including the rows
 * and fields its users are not allowed to see. This says there is something
 * new; the receiver then reads through the endpoint that knows who it is.
 *
 * Two things this deliberately does not implement itself:
 *
 * - The URL is checked by `cboxdk/laravel-ssrf` and the connection pinned to
 *   the addresses it validated. A callback URL is tenant-supplied input aimed
 *   at the server's own network, which is the textbook SSRF sink - and DNS that
 *   resolves publicly at check time and privately a moment later is the textbook
 *   way past a naive check.
 * - The signature comes from `cboxdk/laravel-webhook-signature`, which also owns
 *   the secret and its rotation. A receiver that cannot tell our POST from
 *   anyone else's has learned only that someone knows its URL.
 */
class DeliverSpaceAdvanced implements ShouldQueue
{
    public function __construct(
        private readonly Factory $http,
        private readonly UrlGuard $guard,
        private readonly Webhooks $webhooks,
        private readonly Repository $config,
    ) {}

    public function handle(SpaceAdvanced $event): void
    {
        $url = $this->config->get('sync.webhooks.url');
        if (! is_string($url) || $url === '') {
            return;
        }

        $body = json_encode(
            ['space' => $event->space, 'watermark' => $event->watermark],
            JSON_THROW_ON_ERROR,
        );

        try {
            // https only, and no embedded credentials: a callback URL never
            // needs either, and both are how a tenant reaches somewhere it
            // should not.
            $this->guard->assertSafe($url, ['https'], allowCredentials: false);
            $pinned = $this->guard->pinnedOptions($url, ['https'], allowCredentials: false);
            // A pin that is not there is a check that happened before a second
            // DNS lookup. Refused rather than sent unpinned.
            $curl = $pinned['curl'] ?? null;
            if (! is_array($curl) || ! isset($curl[CURLOPT_RESOLVE])) {
                throw BlockedUrl::make('the connection could not be pinned to the validated address');
            }
        } catch (BlockedUrl $blocked) {
            // Refusing loudly rather than trying anyway. A blocked target is a
            // misconfiguration or an attempt, and neither should be retried by
            // the queue until someone has looked at it.
            Log::warning('Sync webhook target refused by the SSRF guard.', [
                'space' => $event->space,
                'reason' => $blocked->getMessage(),
            ]);

            return;
        }

        $signed = $this->webhooks->sign(
            $this->endpoint(),
            new WebhookMessage($body, new Headers(['Content-Type' => 'application/json']), $url),
        );

        // The exact bytes that were signed. Handing the client an array to
        // encode would re-encode it, and a signature over a body the receiver
        // never sees is worse than no signature: it fails only in production.
        $this->http
            ->withHeaders($signed->all())
            ->withOptions($pinned)
            ->timeout($this->timeout())
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }

    private function endpoint(): string
    {
        $name = $this->config->get('sync.webhooks.endpoint');

        return is_string($name) && $name !== '' ? $name : 'sync';
    }

    private function timeout(): int
    {
        $seconds = $this->config->get('sync.webhooks.timeout');

        return is_int($seconds) && $seconds > 0 ? $seconds : 5;
    }
}
