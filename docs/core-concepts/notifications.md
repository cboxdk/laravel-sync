---
title: "Telling clients about a change"
weight: 15
description: "A commit raises an event. Wire it to a broadcast or a webhook so devices stop polling blind."
---

# Telling clients about a change

**Polling is a complete strategy.** A device that asks every minute is correct,
and for plenty of applications the interval was never the problem. Nothing below
is required.

What a notification buys is latency: the gap between a change happening and a
device knowing about it drops from the poll interval to about nothing. Whether
that is worth a broadcaster is a product question, not a correctness one.

```php
Event::listen(SpaceAdvanced::class, function (SpaceAdvanced $event) {
    // $event->space, $event->watermark
});
```

## It carries the watermark and nothing else

That is not an omission. The log is per space, but authorization is per
principal and per view. A payload carrying the changes would hand every
listener — and every broadcast subscriber on that space — everything written
there, including the rows and fields a given reader is not allowed to see.

The signal says *there is something new, up to here*. The reader then asks
through `/delta`, which knows who it is.

## Only after the commit

The event is dispatched once the **outermost** transaction on the sync
connection commits - not when the engine's own savepoint finishes. A host that
wraps a save in `DB::transaction()` and then rolls back never announces it, and
a device that pulls the moment it hears finds the write there. A listener that
throws is logged and swallowed rather than escaping from your transaction after
it has already committed.

## Keep polling even with push

Delivery is at-most-once and unordered, so a missed signal must never mean
missed data. If you add push, slow the poll down rather than removing it -
minutes instead of seconds. The signal makes sync prompt; the cursor is what
makes it correct, and that does not change.

## Broadcasting to web clients

Off by default. Turn it on and the package broadcasts on a private channel per
space:

```dotenv
SYNC_BROADCAST_ENABLED=true
```

Which broadcaster is not this package's business. Laravel already abstracts
Reverb, Pusher, Ably and the rest behind one config, so the event simply
implements `ShouldBroadcast` and your `config/broadcasting.php` decides the
rest. There is no driver setting here and there should not be one.

**The channel name is the tenant boundary.** `private-sync.{space}` is another
way into the same data the endpoints guard, and one that bypasses them — so the
channel is not registered at all until you say who may listen:

```php
class TeamChannels implements AuthorizesSpaceChannel
{
    public function mayListen(Authenticatable $user, string $space): bool
    {
        return $this->teams->has($user->getAuthIdentifier(), $space);
    }
}
```

```php
$this->app->bind(AuthorizesSpaceChannel::class, TeamChannels::class);
```

Without that binding nobody can subscribe, which is the safe direction to fail
in. There is deliberately no default: a permissive one would be the worst thing
this package could ship.

The payload is `{"watermark": N}` on event `space.advanced`. A subscriber learns
that there is something new and how far it goes; what it may actually read is
decided when it asks.

## Webhooks for server-to-server

Set `SYNC_WEBHOOK_URL` and the package delivers `{space, watermark}` on a queue.
It will not start without both of these installed, and that is deliberate:

```bash
composer require cboxdk/laravel-ssrf cboxdk/laravel-webhook-signature
```

A callback URL is tenant-supplied input aimed at your own network — the textbook
SSRF sink, and DNS that answers publicly at check time and privately a moment
later is the textbook way past a naive check. `cboxdk/laravel-ssrf` validates the
URL and pins the connection to the addresses it resolved. That pin is held by
cURL's own resolver, so the `curl` extension is required: without it the HTTP
client would look the name up again after the check, which is exactly the window
the pin closes. Boot refuses a webhook URL without it, and a delivery whose
connection cannot be pinned is not sent.

`cboxdk/laravel-webhook-signature` signs the POST and owns the secret and its
rotation, so no secret appears in this package's config. A receiver that cannot
tell your delivery from anyone else's has learned only that someone knows its
URL.

Delivery is queued because the commit already happened: a slow or dead receiver
is not the writer's problem. Run it on an asynchronous queue connection - on the
`sync` driver the POST happens inside the request that made the write.

## What a client does with the signal

Exactly what it does on a timer, just sooner: pull `/delta` from its saved
cursor. Nothing about the protocol changes — which is why a device that ignores
notifications entirely is still correct.
