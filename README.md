# Cbox Sync for Laravel

Offline-first sync for Laravel applications: one authoritative server, many
replicas, field-level conflicts that are preserved rather than silently
overwritten.

This package is the Laravel integration. The engine and its durable storage live
in [cboxdk/sync](https://github.com/cboxdk/sync), which has no framework
dependency; this one wires it into the container, uses one of the application's
own database connections, and delegates transaction control to Laravel so it
composes with `DB::transaction()`.

```sh
composer require cboxdk/laravel-sync
php artisan migrate
```

```php
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;

$result = app(Engine::class)->process(new Mutation(
    id: $clientMutationId,
    entity: new EntityKey('tenant-1', 'notes', $noteId),
    replica: new Replica($deviceId),
    sequence: new MutationSequence($clientSequence),
    kind: MutationKind::Update,
    baseVersion: new RecordVersion($knownVersion),
    operations: [FieldOperation::set('title', $title)],
));
```

Start with the [quickstart](docs/quickstart.md). See
[installation](docs/getting-started/installation.md),
[testing](docs/getting-started/testing.md) and
[configuration](docs/configuration/reference.md).

Requires PHP `^8.4` and Laravel 12 or 13. [Requirements](docs/requirements.md)
are generated from Composer metadata.

The HTTP endpoints ship with the package but are off by default: your
application owns authentication and decides how a space is resolved from a
request. It ships no UI. A space accepts one concurrent writer by design — it is the
ordering boundary — so pick spaces that match your tenancy.

MIT, copyright Cbox. See [LICENSE](LICENSE) and [BUILD-STATUS.md](BUILD-STATUS.md).
