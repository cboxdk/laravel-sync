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

What it does not do is as important. There are no routes, no controllers and no
wire format here: your application decides how a request becomes a mutation, who
is allowed to send it, and which space it belongs to. There is no UI and no queue
integration.

## Sections

- [Getting started](getting-started/_index.md) — install, migrate, and test.
- [Core concepts](core-concepts/_index.md) — how the pieces are bound and what a
  space costs you.
- [Cookbook](cookbook/_index.md) — serving a client from a controller.
- [Extension points](extension-points/_index.md) — replacing the resolver,
  validator or store.
- [Security](security/_index.md) — what this package does not protect.
- [Configuration](configuration/_index.md) — every key.
