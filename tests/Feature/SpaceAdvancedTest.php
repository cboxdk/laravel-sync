<?php

declare(strict_types=1);

use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Illuminate\Support\Facades\Event;

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
