---
title: "Quickstart"
weight: 2
description: "Install, migrate, and apply your first mutation."
---

# Quickstart

```sh
composer require cboxdk/laravel-sync
php artisan migrate
```

The tables land on your default connection unless `sync.connection` says
otherwise.

## Apply a mutation

```php
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\{EntityKey, MutationSequence, RecordVersion, Replica};

$result = app(Engine::class)->process(new Mutation(
    id: $request->string('mutation_id')->toString(),
    entity: new EntityKey($tenantId, 'notes', $noteId),
    replica: new Replica($deviceId),
    sequence: new MutationSequence($request->integer('sequence')),
    kind: MutationKind::Update,
    baseVersion: new RecordVersion($request->integer('base_version')),
    operations: [FieldOperation::set('title', $request->string('title')->toString())],
));
```

The mutation ID is the client's, and it is what makes a retry safe: replaying the
same ID returns the stored result instead of applying anything twice. The
sequence is per replica per space and must have no holes — a gap is reported back
rather than applied out of order.

`$result->status` tells you what happened: applied, partly applied, a no-op, a
conflict whose competing proposals were preserved, a rejection, a failed
precondition or validation, a gap to fill first, `pull_required` (only when you
asked to decide conflicts yourself), or `receipt_pruned` - a replay too old for
its answer to be known.

## Serve a client view

```php
use Cbox\Sync\Views\{FieldEqualsView, ViewSyncService};

$views = app(ViewSyncService::class);
$view = FieldEqualsView::matching('my-notes', '1', 'owner', $userId, 'notes');

$context = $views->context($tenantId, $view);
$token = $views->openBootstrap($context, $view, 100);
$page = $views->bootstrap($context, $view, $token);
```

Bootstrap tokens are stateless by default, so the next page can be served by a
different worker. Once `$page->cursor` is set the bootstrap is complete, and the
client switches to `$views->delta($cursor, $view)`.

Next: [testing](getting-started/testing.md) and
[serving a client](cookbook/serving-clients.md).
