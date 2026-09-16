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
