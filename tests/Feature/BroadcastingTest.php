<?php

declare(strict_types=1);

use Cbox\Sync\Laravel\Api\Contracts\AuthorizesSpaceChannel;
use Cbox\Sync\Laravel\Events\SpaceAdvanced;
use Cbox\Sync\Laravel\Events\SpaceChanged;
use Cbox\Sync\Laravel\SyncServiceProvider;
use Cbox\Sync\Laravel\Tests\Fixtures\Member;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Event;

function bootSync(): void
{
    (new SyncServiceProvider(app()))->boot();
}

/**
 * Off unless asked for. A client that polls is complete on its own, and a
 * package that made every host configure a broadcaster to use sync would be
 * charging them for a problem many of them do not have.
 */
it('broadcasts nothing unless asked', function () {
    Event::fake([SpaceChanged::class]);
    config()->set('sync.broadcast.enabled', false);
    bootSync();

    event(new SpaceAdvanced('team-1', 4));

    Event::assertNotDispatched(SpaceChanged::class);
});

it('broadcasts the watermark when asked', function () {
    Event::fake([SpaceChanged::class]);
    config()->set('sync.broadcast.enabled', true);
    bootSync();

    event(new SpaceAdvanced('team-1', 4));

    Event::assertDispatched(SpaceChanged::class, fn (SpaceChanged $e): bool => $e->space === 'team-1' && $e->watermark === 4);
});

/** Private, named for the space, carrying nothing a subscriber has not earned. */
it('puts it on a private channel named for the tenant', function () {
    $broadcast = new SpaceChanged('team-1', 9);

    expect($broadcast->broadcastOn())->toBeInstanceOf(PrivateChannel::class);
    expect($broadcast->broadcastOn()->name)->toBe('private-sync.team-1');
    expect($broadcast->broadcastWith())->toBe(['watermark' => 9]);
    expect($broadcast->broadcastAs())->toBe('space.advanced');
});

/**
 * The channel name is the tenant boundary, so it is not registered at all until
 * the host has said who may listen. Not registering it means nobody can join;
 * a permissive default would mean everybody could.
 */
it('registers no channel until someone decides who may listen', function () {
    config()->set('sync.broadcast.enabled', true);
    bootSync();

    expect(app(BroadcastFactory::class)->getChannels())->not->toHaveKey('sync.{space}');
});

it('asks the host once a decision is available', function () {
    $asked = new ArrayObject;
    app()->bind(AuthorizesSpaceChannel::class, fn () => new class($asked) implements AuthorizesSpaceChannel
    {
        public function __construct(private ArrayObject $asked) {}

        public function mayListen(Authenticatable $user, string $space): bool
        {
            $this->asked[] = $space;

            return $space === 'team-1';
        }
    });

    config()->set('sync.broadcast.enabled', true);
    bootSync();

    $channels = app(BroadcastFactory::class)->getChannels();
    expect($channels)->toHaveKey('sync.{space}');

    $decide = $channels['sync.{space}'];
    $user = new Member(['id' => 'alice', 'team_id' => 'team-1']);

    expect($decide($user, 'team-1'))->toBeTrue();
    expect($decide($user, 'someone-else'))->toBeFalse();
    expect(iterator_to_array($asked))->toBe(['team-1', 'someone-else']);
});
