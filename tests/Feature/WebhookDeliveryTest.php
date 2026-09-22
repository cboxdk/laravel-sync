<?php

declare(strict_types=1);

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\SsrfServiceProvider;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\Sync\Laravel\Webhooks\DeliverSpaceAdvanced;
use Cbox\WebhookSignature\WebhookSignatureServiceProvider;
use Illuminate\Support\Facades\Http;

function deliverer(): DeliverSpaceAdvanced
{
    return app(DeliverSpaceAdvanced::class);
}

beforeEach(function () {
    $this->app->register(SsrfServiceProvider::class);
    $this->app->register(WebhookSignatureServiceProvider::class);
    config()->set('webhook-signature.endpoints.sync', [
        'scheme' => 'standard-webhooks',
        'secrets' => ['current' => 'whsec_'.base64_encode(str_repeat('k', 24))],
    ]);
    config()->set('sync.webhooks.endpoint', 'sync');
});

/**
 * A callback URL is tenant-supplied input aimed at this server's own network.
 * Nothing is sent to one the guard refuses - not even an attempt.
 */
it('refuses to deliver to a target the guard blocks', function (string $url) {
    Http::fake();
    config()->set('sync.webhooks.url', $url);

    deliverer()->handle(new SpaceAdvanced('team-1', 7));

    Http::assertNothingSent();
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'private range' => 'https://10.0.0.5/hook',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'plain http' => 'http://example.com/hook',
]);

it('sends the watermark, signed, and nothing else', function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('sync.webhooks.url', 'https://example.com/sync-hook');

    deliverer()->handle(new SpaceAdvanced('team-1', 7));

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        // The payload names the space and how far it got. Carrying the changes
        // would hand the receiver rows its users may not be allowed to see.
        expect($body)->toBe(['space' => 'team-1', 'watermark' => 7]);

        // Signed, so a receiver can tell this POST from anyone else's.
        expect($request->hasHeader('webhook-signature'))->toBeTrue();

        return true;
    });
});

it('does nothing when no webhook is configured', function () {
    Http::fake();
    config()->set('sync.webhooks.url', null);

    deliverer()->handle(new SpaceAdvanced('team-1', 7));

    Http::assertNothingSent();
});

/**
 * A guard that validates but cannot pin leaves a second DNS lookup after the
 * check - the rebinding window. Nothing goes out unpinned.
 */
it('refuses to send when the connection cannot be pinned to the validated address', function () {
    Http::fake();
    config()->set('sync.webhooks.url', 'https://example.com/sync-hook');
    $this->app->instance(UrlGuard::class, new class implements UrlGuard
    {
        public function assertSafe(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): void {}

        public function isSafe(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): bool
        {
            return true;
        }

        public function assertSafeRedirect(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): void {}

        public function pinnedOptions(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): array
        {
            return ['allow_redirects' => false];
        }
    });

    deliverer()->handle(new SpaceAdvanced('team-1', 7));

    Http::assertNothingSent();
});
