# Changelog

## 0.7.0 - Unreleased

Requires `cboxdk/sync` 0.9.

### Added

- **`on_conflict: "pull"`** on push: a stale edit is answered `pull_required` with nothing stored, and the device resends the same mutation rebased on the refusal's `record_version`.
- **A change is broadcast on a private per-space channel**, driver-agnostic through Laravel's own broadcasting, when the host binds `AuthorizesSpaceChannel`.

### Fixed - the model integration

- **A conflict or a refusal no longer leaves the losing value in your table.** An update is recorded on `updating`, before the row is written, so a 409, a 412 or a 422 stops the save. It also carries only this save's changes; a reused instance used to push stale attributes over newer ones.
- **Every outcome is handled.** A validation failure or rejection raises `SyncRejected` (422) instead of returning quietly.
- **Casts round-trip.** Values travel in the model's serialized form, so an `array` field is a JSON object in the log instead of a string that was encoded again on the way back.
- **Policies see the real row**, with the synced values laid over it, instead of a model with every non-synced attribute null.
- **Refused by name rather than half-done:** moving a record between tenants, restoring a soft-deleted record, a model on a different connection from the sync store, a model that declares nothing to sync. Hidden fields and the tenant column are never synced.
- **`If-Match` is a whole-record precondition (412)**, as HTTP defines it; `base_version` keeps field-level merging.
- Writing back to the table: an unset field becomes NULL, a cancelled save rolls the write back, a row in another tenant is refused, and deletes go through the model so its observers run. The table is written only when the record actually changed.
- Record ids are UUIDs (version 8, derived from the mutation), so a `uuid` key column holds them.

### Fixed - the transport

- **A replay is answered from its receipt before permissions are checked again.** A lost response followed by a permission change used to strand the device.
- **Contention is 503 whatever raised it.** A lock wait on the space row arrives from the store as a bare `PDOException` and used to answer 500.
- The JSON check reads the media type exactly; `text/plain; charset=+json` passed it.
- A published config trimmed to a few keys no longer drops the nested defaults - `api.middleware` among them.
- `SyncPrincipal::$binding` is folded into the cursor context, so a permission change makes devices rebuild, as documented.
- A change is announced only after the outermost transaction commits, and a failing listener is logged rather than thrown out of the host's transaction.
- A webhook is never sent without its connection pinned to the address the SSRF guard validated; `ext-curl` is required for webhook delivery.
- Every identifier on the wire is bounded.

### Fixed - CI

- CI had been red since 2026-09-20 on Laravel 12 static analysis, on test tables left behind on MySQL and PostgreSQL, and on a stale requirements page. All three are fixed; analysis runs at level 10 with the strict rules, and every PHP example in the docs is parsed.

## 0.6.0 - 2026-09-21

### Added

- **A commit raises `SpaceAdvanced`**, so devices stop polling blind. Wire it to a broadcast on a private per-space channel, to the shipped webhook, to a queued job, or to nothing - a device that only polls is still correct, just less prompt, which is the property that lets delivery be at-most-once.

  It carries the space and the watermark and nothing else. The log is per space, but authorization is per principal and per view: a payload with the changes would hand every listener - and every broadcast subscriber - everything written there, including rows and fields a given reader may not see.

- **Webhook delivery**, queued, over the two packages that already solve its hard parts. `cboxdk/laravel-ssrf` validates the URL and pins the connection to the addresses it resolved: a callback URL is tenant-supplied input aimed at your own network, and DNS that answers publicly at check time and privately a moment later is the textbook way past a naive check. `cboxdk/laravel-webhook-signature` signs the POST and owns the secret and its rotation, so no secret appears in this package's config. Setting `sync.webhooks.url` without both installed is refused at boot by name, so nobody can turn on the unprotected version with one env var.

- **`openapi.yaml`** describing all three endpoints - every request and response field, every error code and what a client should do about each. The docs described bootstrap and delta in prose, left three push response fields and two error codes out, and never named the request fields at all; anyone generating a client from them, increasingly an agent rather than a person, would have got it wrong.

  It is checked against reality rather than maintained by hand: the tests assert it against what the endpoints actually answer, in both directions - a documented field the API does not return fails, and a returned field the description does not mention fails too.

- **A brief to hand an agent** (`docs/getting-started/for-an-agent.md`), covering the rules a schema cannot express: that an empty object and an empty array are different values, that a sequence is assigned when sending and not when queueing, that a create carries a handle rather than an id, that `remove` means left the view and not deleted, and that a 200 can still mean the write did not land. A test asserts every status, code and endpoint it names against the description.

### Changed

- Requires `cboxdk/sync` `^0.8`.

## 0.5.0 - 2026-09-21

### Added

- **`php artisan sync:prune <space>`**, so retention is reachable at all. `retention.keep_commits` was documented, had an env var, and was read nowhere: there was no command, no scheduler, and no way for a host to drop history - a busy space filled the disk with no supported way to stop it. `--keep` overrides the configured window and `--pretend` reports what would go without dropping anything. Nothing runs on its own, because how much history a tenant still owes its slowest device is a question only the application can answer.

  A space is named rather than discovered. The store deliberately cannot list its spaces - a tenant boundary is the host's to enumerate - and widening the contract to feed a command would be the tail wagging the dog.

### Fixed

- **`sync.bootstrap.page_size` was read nowhere.** An operator who set it to speed up a large bootstrap saw nothing change and had no error to find; the service used a literal buried in a private method. A client that asks for a size still gets it, bounded by `max_page_size`; the configured value is the default for one that asks for nothing.

### Changed

- Requires `cboxdk/sync` `^0.7` for `Store::prune()`.

## 0.4.0 - 2026-09-20

### Security (breaking)

- **The server names a new record, not the client.** An id a client chooses is attacker-controlled input in a key position: it can squat an identifier another tenant is about to use, and the engine's own `entity_exists` answer turns a guessed one into an oracle for what already exists. A create now carries only a handle the device made up for itself, and the response returns both the name the server gave the record and the handle it came in under, so a device can find the row it created and rewrite anything still queued against the handle.

  The name is derived from the principal-namespaced mutation id rather than random. A lost response is the normal case on a mobile network, and a random name would turn one offline create into two rows on the retry - the receipt cannot rescue that, because it is found by mutation id while the entity key has to be built before the engine is reached. Deriving it makes the retry land on the same record for free. The principal is inside the hash, so no caller can produce a name another caller would produce, and reaching an existing record's name would mean finding a sha256 preimage.

  **Breaking:** the `id` a client sends on a `create` is ignored, and the record is stored under the name in the response. An update still names the record it means.

### Changed

- Allows `cboxdk/sync` `^0.6`. Nothing here uses what 0.6 added, but the client requires it and an application installs both.

## 0.3.0 - 2026-09-20

### Added

- **`use Syncable` on a model is the whole registration.** The entity type is the table, the synced fields are the fillable ones, the key and timestamps are never a client's to set, and the tenant is the first tenancy-shaped column the table actually has - each a convention a property overrides. Authorization goes to the Gate, so the host's existing policy decides and nothing is declared twice. Registering was previously a 55-line class implementing seven methods.
- **The settled record is written into the application's own table**, inside the mutation's own transaction, so a failure writing the row takes the mutation back with it. Only synced fields are touched; the rest of the row belongs to the application.
- **Ordinary model saves enter the log.** An edit from an admin screen, a console command or a job was invisible to every offline device, because they follow a sequence it never appeared in. Saving or deleting a model that uses the trait now records a mutation. The log decides whether a write is a create, not the model.
- **Conflict detection on the REST endpoints a host already has.** No new routes: a client that sends the version it was looking at - `base_version` in the body, or an `If-Match` header - gets its write checked against that version, and a write that lost the race raises `SyncConflict`, which Laravel renders as 409 carrying what the record says now. A client that sends nothing gets the ordinary last-write behaviour it always had. Two people editing different fields of the same row do not conflict at all.
- `BaseSyncableType` for a type that is not backed by a model: four methods from two properties, and the three that encode host policy left abstract.

### Fixed

- **`SYNC_API_ENABLED=1` turned nothing on.** Laravel's `env()` converts `true`, `false` and `null` but leaves `"1"` a string, and the check was a strict `=== true` - so the most natural way to enable the API registered no routes, raised nothing and logged nothing. Every ordinary spelling of yes and no is now accepted, and anything unrecognised stays off.
- **A permanent database error was retried for ever.** The retry classification used a hand-written SQLSTATE list: `'1213'` and `'1205'` are driver error numbers that never appear in `getCode()`, so those entries were dead, and a lock wait timeout is `HY000` - but so is a missing column default and a missing table. A schema mistake returned 503 `retriable` and the client retried it against production with no attempt counter. Laravel's own `DetectsConcurrencyErrors` is the decision this reimplemented.
- **An upgrade kept a schema the adapter could no longer write to.** The create migration runs once and `CREATE TABLE IF NOT EXISTS` does nothing to an existing table, so a second migration now reconciles the difference. It is idempotent.
- `src/` imports `Illuminate\Http`, `Illuminate\Routing` and `Symfony\Component\HttpFoundation` and required none of them. It never breaks in an application, because `laravel/framework` supplies them - it breaks for anyone installing the split packages.

### Changed

- Requires `cboxdk/sync` `^0.5` for `EntityTypeView` and the entity-type narrowing a view's delta depends on.

## 0.2.0 - 2026-09-18

### Security

- **A conflict response could disclose a row the caller may not read.** The field whitelist bounds columns; only the view bounds rows. A policy allowing a blind write to a row outside the caller's view handed that row's canonical value back in `conflicts[].current`. Disclosure now requires both permissions.
- **Write authorization is re-checked inside the storage transaction.** The gate in front of the engine reads the record without the space lock held, so anything it decides from record state can be stale by the time the write lands — a record can change owner in between and the write still merges cleanly. The outer gate stays, because it answers a clean 403 without spending a mutation identity; the in-transaction check is the authority.

### Fixed

- **A mutation the server has already processed is answered from its receipt, whatever authorization says now.** Otherwise a retry after a lost response could be refused by a policy that reads the record — deleting a row and then being denied because the row is deleted. The write already happened; refusing the answer only stranded the client, which abandoned the mutation without advancing its acknowledgement and then had every later write rejected for reusing a sequence. One lost response wedged the device permanently.
- A cursor whose context no longer matches is answered with `reset_required` and a reason, not `invalid_cursor`. A rotated epoch is the common case, and a client told "invalid cursor" has nothing to act on and presents the same dead cursor forever.

### Changed

- Requires `cboxdk/sync` `^0.4`.
- `SyncService` is constructed from the store, resolver, id generator and validator rather than a prebuilt engine, so it can decorate the validator per request.

## 0.1.1 - 2026-09-16

### Fixed

- Responses are encoded with `JSON_PRESERVE_ZERO_FRACTION`. A field value is canonical JSON text and equality is exact, so a float like `1.0` encoded as `1` came back as an integer — and a client that wrote back what it read produced a different canonical value, a spurious version bump, and a conflict against anyone still holding the float. Found by an end-to-end test carrying a document through the wire and writing it back verbatim.

## 0.1.0 - 2026-09-16

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
- Requires `cboxdk/sync` `^0.3`. One tested combination rather than a supported range nobody exercises; Composer's caret is narrow below 1.0, so a future sync minor needs this widened deliberately.


### Initial integration

- `IlluminateStore` puts the durable sync tables on one of the application's own database connections and delegates transaction control to Laravel, so a mutation inside `DB::transaction()` becomes a savepoint rather than a broken commit.
- `SyncServiceProvider` binds `Store`, `Engine`, `BootstrapSessions` and `ViewSyncService`, with `ConflictResolver`, `EntityValidator` and `IdGenerator` bound only if the host has not already. The defaults preserve competing proposals and accept every entity state: the only choices that cannot silently lose a user's work before an application has stated its rules.
- Publishable config and a migration that applies the schema owned by `cboxdk/sync`, so table shapes cannot drift between hosts.
- Stateless keyset bootstrap tokens by default, signed with the application key, so any queue worker or web node can serve any bootstrap page.
- `Testing\InteractsWithSync` for host application tests; everything resolves from the container, so a host that swapped the resolver or validator exercises its own wiring.
