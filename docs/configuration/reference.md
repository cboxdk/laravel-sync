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

## Changing schema_version or epoch

Both are bound into every client cursor. Changing either tells clients their
local state for a view can no longer be trusted, and they re-bootstrap. Use
`schema_version` when the meaning of projected data changes; use `epoch` when
history itself is discarded or rebuilt.

## Retention

Nothing is pruned automatically. `keep_commits` is the window your own scheduled
task should honour when it calls `prune()`. Keep enough history for your slowest
client: a cursor below the horizon is told to re-bootstrap rather than silently
skipping the commits it missed.

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
