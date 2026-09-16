# Changelog

## Unreleased

### Fixed

- `IlluminateStore` resolves Laravel's PDO handle per call instead of capturing it at construction. Laravel replaces its PDO on reconnect, and under a long-running worker this store outlives the connection that built it — the transaction would open on the new connection while every write went to the dead one, and the rollback would roll back nothing. Silent partial persistence, with no error raised.
- Requires `cboxdk/sync` `^0.2`, which stops serving a bootstrap page on the token's own authority.


### Initial integration

- `IlluminateStore` puts the durable sync tables on one of the application's own database connections and delegates transaction control to Laravel, so a mutation inside `DB::transaction()` becomes a savepoint rather than a broken commit.
- `SyncServiceProvider` binds `Store`, `Engine`, `BootstrapSessions` and `ViewSyncService`, with `ConflictResolver`, `EntityValidator` and `IdGenerator` bound only if the host has not already. The defaults preserve competing proposals and accept every entity state: the only choices that cannot silently lose a user's work before an application has stated its rules.
- Publishable config and a migration that applies the schema owned by `cboxdk/sync`, so table shapes cannot drift between hosts.
- Stateless keyset bootstrap tokens by default, signed with the application key, so any queue worker or web node can serve any bootstrap page.
- `Testing\InteractsWithSync` for host application tests; everything resolves from the container, so a host that swapped the resolver or validator exercises its own wiring.
