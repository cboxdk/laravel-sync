---
title: "Container bindings"
weight: 10
description: "What the provider binds, and which defaults you should replace."
---

# Container bindings

| Abstract | Bound to | Replaceable |
|---|---|---|
| `Contracts\Store` | `IlluminateStore` on the configured connection | yes, rebind it |
| `Engine` | the engine over that store | yes |
| `Views\BootstrapSessions` | keyset or frozen, from config | yes |
| `Views\ViewSyncService` | the view service | yes |
| `Contracts\ConflictResolver` | `PreserveConflict` | **you should** |
| `Contracts\EntityValidator` | `AcceptAll` | **you should** |
| `Contracts\IdGenerator` | `UuidV7Generator` | rarely |

The last three are bound with `bindIf`, so registering your own in any provider
is enough — you do not have to unbind anything.

## Why those defaults

`PreserveConflict` keeps every competing proposal instead of picking a winner,
and `AcceptAll` accepts any post-merge entity state. Neither is what a finished
application wants. They are the defaults because they are the only pair that
cannot silently discard a user's work before the application has said what its
rules are. A last-write-wins default would quietly lose edits in exactly the
situation sync exists to handle.

Replace them as soon as you know your rules:

```php
$this->app->bind(ConflictResolver::class, MyFieldPolicy::class);
$this->app->bind(EntityValidator::class, MyInvariants::class);
```

A validator runs inside the storage transaction, on the complete merged entity,
and can use the same connection — so a cross-entity uniqueness check sees the
staged write.

## One writer per space

Every mutation takes a write lock on its space row before reading anything. That
is what makes the commit log gapless and correctly ordered, and it means one
concurrent writer per space. Choose spaces that match your tenancy: a workspace,
a team, a user. Writers in different spaces never contend.
