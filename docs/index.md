---
title: "Cbox Sync for Laravel"
weight: 1
description: "Wire the offline-first sync engine into a Laravel application."
---

# Cbox Sync for Laravel

The sync engine itself is framework-independent and lives in `cboxdk/sync`. This
package gives it a home in a Laravel application: container bindings, one of your
own database connections, a migration, and transaction control that composes with
`DB::transaction()`.

It also serves the protocol: three JSON endpoints, off by default, and a model
trait that turns an existing Eloquent model into a syncable type using the policy
your application already has. See [Syncing a model](core-concepts/models.md) and
[Transport](core-concepts/transport.md).

What it does not do is as important. There is no UI, no queue integration, and no
authentication: the host says who is calling, and the package decides only what
that caller may then reach.

## Sections

- [Getting started](getting-started/_index.md) — install, migrate, and test.
- [Core concepts](core-concepts/_index.md) — how the pieces are bound and what a
  space costs you.
- [Cookbook](cookbook/_index.md) — serving a client from a controller.
- [Extension points](extension-points/_index.md) — replacing the resolver,
  validator or store.
- [Security](security/_index.md) — what this package does not protect.
- [Configuration](configuration/_index.md) — every key.
