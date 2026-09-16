# Build status

Unreleased Laravel integration for [cboxdk/sync](https://github.com/cboxdk/sync). No tagged release or package publication yet.

Implemented:

- Durable store on an application database connection, with transaction control delegated to Laravel so sync composes with `DB::transaction()`.
- Container bindings for the store, engine, bootstrap sessions and view service, with host-overridable resolver, validator and ID generator.
- Publishable config and a migration driven by the engine package's own schema.
- Stateless keyset bootstrap tokens by default, signed with the application key.
- `Testing\InteractsWithSync` for host application tests.

Verification on 2026-09-16:

- Pest: 6 Testbench tests covering container wiring, migration, a create-through-conflict-through-bootstrap-through-delta round trip, rollback inside a host transaction, and space independence.
- Pint, PHPStan max with larastan, 137 dependency licenses, locked dependency audit.
- SBOM and generated requirements reproduce without drift.

Limits: no HTTP endpoints, no wire format, no UI, no queue integration. The host owns routing, authentication and space resolution. A space accepts one concurrent writer by design. Retention is configurable but never automatic. Requires `cboxdk/sync` `^0.1`, resolved from Packagist. Composer's caret is narrow below 1.0, so a future sync 0.2 needs this constraint widened.
