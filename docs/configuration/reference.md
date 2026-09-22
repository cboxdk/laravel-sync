---
title: "Reference"
weight: 10
description: "Every key in config/sync.php."
---

# Configuration reference

Publish with `php artisan vendor:publish --tag=sync-config`.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `connection` | `SYNC_CONNECTION` | default connection | Which database holds the sync tables |
| `schema_version` | `SYNC_SCHEMA_VERSION` | `v1` | Bound into every client cursor |
| `epoch` | `SYNC_EPOCH` | `epoch-1` | Rotate to force every client to re-bootstrap |
| `bootstrap.strategy` | `SYNC_BOOTSTRAP_STRATEGY` | `keyset` | `keyset` (stateless) or `frozen` (single process) |
| `bootstrap.page_size` | `SYNC_BOOTSTRAP_PAGE_SIZE` | `100` | Records per bootstrap page |
| `bootstrap.secret` | `SYNC_BOOTSTRAP_SECRET` | application key | Signs stateless tokens |
| `retention.keep_commits` | `SYNC_KEEP_COMMITS` | `10000` | How much history to keep when you prune |
| `api.enabled` | `SYNC_API_ENABLED` | `false` | Serve the push, bootstrap and delta endpoints. With the `frozen` bootstrap strategy this refuses to boot: frozen sessions live in one process |
| `api.prefix` | `SYNC_API_PREFIX` | `sync` | URL prefix of the endpoints |
| `api.middleware` | — | `['api']` | Middleware around the endpoints; put your authentication here |
| `api.types` | — | `[]` | Entity type => `SyncableType` class, or a list of `Syncable` model classes |
| `api.max_body_bytes` | — | `262144` | Largest request body accepted (413 above it) |
| `api.max_operations` | — | `64` | Most field operations in one mutation |
| `api.max_page_size` | — | `500` | Most records a client may ask for in one bootstrap page |
| `api.max_commits` | — | `500` | Most commits in one delta page |
| `broadcast.enabled` | `SYNC_BROADCAST_ENABLED` | `false` | Announce each commit on a private per-space channel - also needs an `AuthorizesSpaceChannel` binding |
| `webhooks.url` | `SYNC_WEBHOOK_URL` | none | POST each commit's space and watermark here, signed; needs `cboxdk/laravel-ssrf`, `cboxdk/laravel-webhook-signature` and `ext-curl` |
| `webhooks.endpoint` | `SYNC_WEBHOOK_ENDPOINT` | `sync` | The signing endpoint's name in `laravel-webhook-signature` |
| `webhooks.timeout` | — | `5` | Seconds per delivery |

## MySQL: run the connection at READ COMMITTED

The package opens its own transactions at READ COMMITTED on MySQL. A model save,
and a sync write whose model and log share a transaction, run inside a
transaction your application began - at your connection's isolation. At MySQL's
default, REPEATABLE READ, writers in different tenants can deadlock on the log's
shared indexes; each deadlock is answered 503 and retried, but it costs latency.
Set it on the connection that holds the sync tables:

```php
// config/database.php - the mysql connection, next to its other keys
return [
    'connections' => [
        'mysql' => [
            'isolation_level' => 'READ COMMITTED',
        ],
    ],
];
```

## Changing schema_version or epoch

Both are bound into every client cursor. Changing either tells clients their
local state for a view can no longer be trusted, and they re-bootstrap. Use
`schema_version` when the meaning of projected data changes; use `epoch` when
history itself is discarded or rebuilt.

## Retention

Nothing is pruned automatically. The commit log is the only thing that grows
without bound, and how much history a tenant still owes its slowest device is a
question only the application can answer.

```bash
php artisan sync:prune team-1 team-2
```

`retention.keep_commits` is the default window; `--keep` overrides it and
`--pretend` reports what would go without dropping anything. Schedule it.

A device whose cursor falls below the new horizon is told to rebuild rather than
served a gap, so prune past what every device has acknowledged - or accept that
the slow ones re-bootstrap.
