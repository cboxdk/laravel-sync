---
title: "Extension points"
weight: 40
description: "Replace the storage, resolver or validator."
---

# Extension points

Every capability is an interface resolved from the container, so replacing one is
a binding. See [container bindings](../core-concepts/bindings.md) for the list and
`cboxdk/sync`'s own extension documentation for the contracts themselves.

## A different store

`IlluminateStore` extends the framework-independent PDO adapter and only replaces
transaction control. If you need different storage entirely, bind
`Cbox\Sync\Contracts\Store` to your own implementation; the engine depends on
nothing else.

## A different bootstrap strategy

`sync.bootstrap.strategy` picks between stateless keyset tokens and frozen
in-process sessions. Bind `Cbox\Sync\Views\BootstrapSessions` yourself for
anything else.

## The API's own contracts

Everything the shipped endpoints ask about a type is one of these, all in
`Cbox\Sync\Laravel\Api\Contracts`:

| Contract | What it answers | Default |
|---|---|---|
| `SyncableType` | the seven questions: entity type, space, view, readable and writable fields, may-read, may-write | `ModelSyncableType` for a registered model |
| `NormalizesValues` | what a device's values become before the engine sees them - casts, mutators, the app's timezone | the model's own casts |
| `PersistsRecords` | writing the settled record into your table, and removing it | the model |
| `SyncableTypes` | which type serves an entity type on the wire | the registry built from `sync.api.types` |
| `SyncPrincipals` | who is calling: a stable id, and a `binding` that changes when their permissions do | `GuardPrincipals`, the authenticated user's id as both |
| `SyncEndpoints` | the three endpoints themselves | `SyncService` |
| `AuthorizesSpaceChannel` | who may listen to a space's broadcast channel | none - broadcasting stays off until you bind it |

`BaseSyncableType` is a starting point for a hand-written type: it answers four
of the seven questions from two properties and leaves the three only you can
answer - `space()`, `mayRead()` and `mayWrite()` - abstract, so the compiler asks
for them rather than shipping a default that would pick your tenant boundary or
your authorization for you.

A type that also implements `NormalizesValues` and `PersistsRecords` is asked to
do both inside the write's transaction, so the log and your table commit
together. `AuthorizesInsideTransaction` wraps your validator and re-checks
`mayWrite` against the record the engine is about to merge into, under the space
lock - which is why a permission that changes mid-request cannot let a write
through.

Give `SyncPrincipals` a binding of your own when permissions can be revoked: the
binding is folded into every cursor, so changing it makes devices rebuild their
window under the new rules.
