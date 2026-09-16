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

Limits: no HTTP endpoints, no wire format, no UI, no queue integration. The host owns routing, authentication and space resolution. A space accepts one concurrent writer by design. Retention is configurable but never automatic. `cboxdk/sync` is consumed from a local path repository until it is tagged; the constraint is already `^0.1` and only the repository entry has to be removed.
