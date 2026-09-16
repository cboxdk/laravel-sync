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

You can also read the statements directly from
`Cbox\Sync\Persistence\Pdo\PdoSchema::statements()` and apply them however your
deployment prefers.

## Databases

SQLite, MySQL 8+ and PostgreSQL. The engine package's CI runs its full suite,
simulator and a multi-process concurrency experiment against all three.
