# Changelog

## Unreleased

### HTTP transport

- Bootstrap and delta responses carry the full cursor `context` and the `token` that produced the page. A client needs both to key its own local state, and being told which view it is already reading discloses nothing it does not have. Requests still carry only the context fingerprint: accepting a context would let the client choose its own space.

- Three endpoints — `push`, `bootstrap`, `delta` — shipped by the package, off by default, with the host's own guard in `sync.api.middleware`. See `docs/core-concepts/transport.md`.
- `Contracts\SyncableType` is a host's per-entity-type declaration: space resolution from the authenticated principal, readable and writable field whitelists, the view, and read/write authorization. Registered through a config map in a registry where an unknown type throws, never no-ops.
- **`replica` and `mutation_id` are namespaced under the authenticated principal.** Both are chosen by the client and honoured by the engine without it knowing who sent them, so unbound, anyone in a tenant who names another device's replica claims its sequence numbers — that device's next push then fails terminally and its queued mutations are unrecoverable. Bound on the principal's stable id, never its authorization state, so a permission change does not orphan an unflushed queue.
- Field values are decoded from the raw body with objects rather than associative arrays. `json_decode('{}', true)` returns `[]`, which would store an empty JSON object as an empty array — consistently, so no protocol error would ever surface it.
- `RecordMapper` takes the field whitelist as a required argument. A view filters rows and has no opinion about fields, and each field's origin carries the provenance of whoever wrote it, so a generic serializer would disclose both values and authorship.
- Push returns nothing a client did not already have: no receipt, no merged record, no other device's candidate values, and no echo of its own proposal. It does return each conflict group's revision and candidate ids, which is what a resolve needs.
- `Concerns\HandlesSyncRequests` mounts the handlers in a host's own controller. `HistoryUnavailable` is caught before `ProtocolException`, which it extends: the other order turns "re-bootstrap" into "terminal error" and the client retries a dead cursor forever.
- Enabling the API with the `frozen` bootstrap strategy refuses to boot. Those sessions live in process memory, so page two of every bootstrap would land on a worker that never heard of the token — an endless loop that looks like a client bug.

### Fixed

- `IlluminateStore` resolves Laravel's PDO handle per call instead of capturing it at construction. Laravel replaces its PDO on reconnect, and under a long-running worker this store outlives the connection that built it — the transaction would open on the new connection while every write went to the dead one, and the rollback would roll back nothing. Silent partial persistence, with no error raised.
- Requires `cboxdk/sync` `^0.2`, which stops serving a bootstrap page on the token's own authority. Widen to `^0.3` once that is published; nothing here needs it yet.


### Initial integration

- `IlluminateStore` puts the durable sync tables on one of the application's own database connections and delegates transaction control to Laravel, so a mutation inside `DB::transaction()` becomes a savepoint rather than a broken commit.
- `SyncServiceProvider` binds `Store`, `Engine`, `BootstrapSessions` and `ViewSyncService`, with `ConflictResolver`, `EntityValidator` and `IdGenerator` bound only if the host has not already. The defaults preserve competing proposals and accept every entity state: the only choices that cannot silently lose a user's work before an application has stated its rules.
- Publishable config and a migration that applies the schema owned by `cboxdk/sync`, so table shapes cannot drift between hosts.
- Stateless keyset bootstrap tokens by default, signed with the application key, so any queue worker or web node can serve any bootstrap page.
- `Testing\InteractsWithSync` for host application tests; everything resolves from the container, so a host that swapped the resolver or validator exercises its own wiring.
