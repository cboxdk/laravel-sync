# Build status

Laravel integration for [cboxdk/sync](https://github.com/cboxdk/sync), released as 0.7.0 on 2026-09-22. Requires cboxdk/sync 0.9.

Implemented:

- Durable store on an application database connection, with transaction control delegated to Laravel so sync composes with `DB::transaction()`.
- Container bindings for the store, engine, bootstrap sessions, view service and commit observer, with host-overridable resolver, validator and ID generator.
- Publishable config and migrations driven by the engine package's own schema, including a reconcile migration for an existing installation.
- Stateless keyset bootstrap tokens by default, signed with the application key.
- `Testing\InteractsWithSync` for host application tests.
- HTTP transport: push, bootstrap and delta endpoints behind a per-type authorization contract, with client-chosen replica and mutation ids namespaced under the authenticated principal, and `openapi.yaml` checked against the endpoints' real answers by the suite.
- The `Syncable` trait: an ordinary Eloquent save, delete or increment is recorded in the same transaction, values travel in one form on every driver, and what the table makes of a device's write is echoed back to the devices.
- A commit announces itself as `SpaceAdvanced`: broadcast on a private per-space channel, or delivered as a signed webhook with the SSRF guard pinning the address it validated.

Verification: the suite runs on SQLite, MySQL 8.4 and PostgreSQL, on PHP 8.4 and 8.5 and on Laravel 12 and 13, including the HTTP endpoints through Laravel's kernel and an upgrade from the schema cboxdk/sync 0.8.0 installed. Pint, PHPStan max with larastan and the strict rules, dependency licenses, an audit and a docs-example parser run on every build; see `composer qa`.

Limits: no UI. Authentication is the host's middleware; what a caller may then reach is decided here, deny by default.
