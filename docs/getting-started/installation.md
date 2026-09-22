---
title: "Installation"
weight: 10
description: "Connection, migration and publishing choices."
---

# Installation

```sh
composer require cboxdk/laravel-sync
php artisan migrate
```

The service provider is discovered automatically.

## Choosing a connection

By default the sync tables live on your default connection. Point them elsewhere
with `SYNC_CONNECTION`, or publish the config and set `sync.connection`.

```sh
php artisan vendor:publish --tag=sync-config
```

There is a real reason to consider a dedicated connection: every mutation holds a
row lock on its space for the length of the transaction. That does not block your
application's other queries, but it does mean sync traffic and ordinary traffic
share a connection pool.

## Owning the migration

The migration applies the schema defined by `cboxdk/sync`, so table shapes cannot
drift between a Laravel host and any other one. If you would rather manage it
yourself:

```sh
php artisan vendor:publish --tag=sync-migrations
```

Upgrading, run `php artisan migrate` again: each release that changes the schema
ships a migration that brings an existing installation up to date (on MySQL it
can copy tables - use a maintenance window). If you published the migrations,
publish them again. A deployment that runs its own migrations calls the same
installer: `PdoSchema::forConnection($pdo)->install($pdo)`.

## Databases

SQLite 3.24+, MySQL 8.0.17+ and PostgreSQL 9.5+ (CI runs MySQL 8.4 and
PostgreSQL 17). The engine package's CI runs its full suite,
simulator and a multi-process concurrency experiment against all three.
