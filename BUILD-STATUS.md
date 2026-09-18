# Build status

Laravel integration for [cboxdk/sync](https://github.com/cboxdk/sync), released as 0.2.0 on 2026-09-18.

Implemented:

- Durable store on an application database connection, with transaction control delegated to Laravel so sync composes with `DB::transaction()`.
- Container bindings for the store, engine, bootstrap sessions and view service, with host-overridable resolver, validator and ID generator.
- Publishable config and a migration driven by the engine package's own schema.
- Stateless keyset bootstrap tokens by default, signed with the application key.
- `Testing\InteractsWithSync` for host application tests.
- HTTP transport: push, bootstrap and delta endpoints behind a per-type authorization contract, with client-chosen replica and mutation ids namespaced under the authenticated principal.

Verification on 2026-09-16:

- Pest: 26 Testbench tests, including end-to-end HTTP covering create, a preserved conflict, resolve, bootstrap paging, delta, replay, gap recovery, authorization refusals, and that no provenance reaches the wire. Green against SQLite, MySQL 8.4 and PostgreSQL 17.
- Pint, PHPStan max with larastan, 137 dependency licenses, locked dependency audit.
- SBOM and generated requirements reproduce without drift.

Limits: no client, no UI, no queue integration. The host owns authentication and space resolution. Conflict candidate values are not delivered over the transport; reading them needs a separately authorized projection. A space accepts one concurrent writer by design. Retention is configurable but never automatic. Requires `cboxdk/sync` `^0.1`, resolved from Packagist. Composer's caret is narrow below 1.0, so a future sync 0.2 needs this constraint widened.
