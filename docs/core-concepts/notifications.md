---
title: "Telling clients about a change"
weight: 15
description: "A commit raises an event. Wire it to a broadcast or a webhook so devices stop polling blind."
---

# Telling clients about a change

A device can always find out by asking. Polling alone makes the interval a
straight trade between how stale the data may be and how much load every idle
device puts on the server, so the package announces a change instead.

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

## Polling does not go away

Delivery is at-most-once and unordered, so a missed signal must never mean
missed data. Keep a slow poll as the backstop: the signal makes sync prompt, the
cursor is what makes it correct. Minutes instead of seconds is the point.

## Broadcasting to web clients

The usual answer for a browser. Put it on a **private** channel per space and
authorize it the way you authorize everything else — the channel name is the
space, so channel authorization is tenant authorization.

```php
class SpaceChanged implements ShouldBroadcast
{
    public function __construct(private SpaceAdvanced $event) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('sync.'.$this->event->space);
    }

    public function broadcastWith(): array
    {
        return ['watermark' => $this->event->watermark];
    }
}
```

## Webhooks for server-to-server

Set `SYNC_WEBHOOK_URL` and the package delivers `{space, watermark}` on a queue.
It will not start without both of these installed, and that is deliberate:

```bash
composer require cboxdk/laravel-ssrf cboxdk/laravel-webhook-signature
```

A callback URL is tenant-supplied input aimed at your own network — the textbook
SSRF sink, and DNS that answers publicly at check time and privately a moment
later is the textbook way past a naive check. `cboxdk/laravel-ssrf` validates the
URL and pins the connection to the addresses it resolved.

`cboxdk/laravel-webhook-signature` signs the POST and owns the secret and its
rotation, so no secret appears in this package's config. A receiver that cannot
tell your delivery from anyone else's has learned only that someone knows its
URL.

Delivery is queued because the commit already happened: a slow or dead receiver
is not the writer's problem.

## What a client does with the signal

Exactly what it does on a timer, just sooner: pull `/delta` from its saved
cursor. Nothing about the protocol changes — which is why a device that ignores
notifications entirely is still correct.
