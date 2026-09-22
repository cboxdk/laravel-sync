<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function pushOne(object $test, string $id, int $sequence): void
{
    $test->postJson('/sync/push', [
        'type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'm'.$sequence, 'id' => $id,
        'replica' => 'device-1', 'sequence' => $sequence, 'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'x'], ['field' => 'status', 'op' => 'set', 'value' => 'open']],
    ], ['X-Test-Principal' => 'alice'])->assertOk();
}

/**
 * A device should not have to ask to find out. The signal makes sync prompt;
 * the cursor is what makes it correct.
 */
it('announces the space and how far it advanced', function () {
    Event::fake([SpaceAdvanced::class]);

    pushOne($this, 't1', 1);

    Event::assertDispatched(SpaceAdvanced::class, function (SpaceAdvanced $event): bool {
        return $event->space === 'team-1' && $event->watermark === 1;
    });
});

/** Carrying the changes would hand every listener rows its users may not see. */
it('carries the watermark and nothing else', function () {
    Event::fake([SpaceAdvanced::class]);

    pushOne($this, 't1', 1);

    Event::assertDispatched(SpaceAdvanced::class, function (SpaceAdvanced $event): bool {
        return array_keys(get_object_vars($event)) === ['space', 'watermark'];
    });
});

/** A replay appends no commit, so there is nothing new to wake anyone for. */
it('says nothing when a push is replayed', function () {
    pushOne($this, 't1', 1);

    Event::fake([SpaceAdvanced::class]);
    pushOne($this, 't1', 1);

    Event::assertNotDispatched(SpaceAdvanced::class);
});

/** A write refused before the engine never happened at all. */
it('says nothing when a write is refused', function () {
    Event::fake([SpaceAdvanced::class]);

    $this->postJson('/sync/push', [
        'type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'm1', 'id' => 't1',
        'replica' => 'device-1', 'sequence' => 1, 'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'nope', 'op' => 'set', 'value' => 'x']],
    ], ['X-Test-Principal' => 'alice'])->assertStatus(403);

    Event::assertNotDispatched(SpaceAdvanced::class);
});

/**
 * The engine will not let a listener's failure reach the caller - the write
 * already happened, and failing the push would only make the client retry into
 * its own receipt. That makes this the only place it can be seen at all, so a
 * silently broken notifier would look exactly like a quiet tenant.
 */
it('leaves a trace when a listener fails', function () {
    Log::spy();
    Event::listen(SpaceAdvanced::class, function (): void {
        throw new RuntimeException('listener is broken');
    });

    pushOne($this, 't1', 1);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'notification failed')
            && $context['space'] === 'team-1'
            && $context['watermark'] === 1
    );
});

/** And the write still stands, because it already had. */
it('keeps the write when a listener fails', function () {
    Event::listen(SpaceAdvanced::class, function (): void {
        throw new RuntimeException('listener is broken');
    });

    pushOne($this, 't1', 1);

    expect(app(Store::class)->watermark('team-1')->value)->toBe(1);
});

/**
 * The engine's own transaction is a savepoint when the host has one open.
 * Announcing at the end of the savepoint told devices about writes the host
 * then rolled back, and a device that pulled at once found nothing and was
 * never told again.
 */
it('waits for the outermost transaction to commit, and says nothing if it rolls back', function () {
    $heard = 0;
    Event::listen(SpaceAdvanced::class, function () use (&$heard): void {
        $heard++;
    });

    try {
        DB::transaction(function () use (&$heard): void {
            pushOne($this, 't1', 1);
            expect($heard)->toBe(0);

            throw new RuntimeException('the host changes its mind');
        });
    } catch (RuntimeException) {
    }
    expect($heard)->toBe(0);

    DB::transaction(function () use (&$heard): void {
        pushOne($this, 't2', 1);
        expect($heard)->toBe(0);
    });
    expect($heard)->toBe(1);
});

/** Deferred to the commit, a throwing listener must still not escape into the host's code. */
it('keeps a failing listener out of the host transaction that committed the write', function () {
    Event::listen(SpaceAdvanced::class, function (): void {
        throw new RuntimeException('listener broke');
    });
    Log::spy();

    DB::transaction(fn () => pushOne($this, 't1', 1));

    expect(app(Store::class)->watermark('team-1')->value)->toBe(1);
    Log::shouldHaveReceived('error')->once();
});
