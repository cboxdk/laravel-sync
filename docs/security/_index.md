---
title: "Security"
weight: 50
description: "What this package does not protect, and what you must."
---

# Security

This package has no opinion about authentication or authorization, and that is
the most important thing to know about it.

- **The space is the security boundary, and you assign it.** Derive it from the
  authenticated session, never from the request body. Nothing downstream checks
  whether a caller may write to the space in a mutation.
- **The raw change feed is not filtered.** `Store::pull()` returns receipts
  containing every proposed value, including proposals that were rejected or
  belong to fields a given client should never see. Only the view service
  projects a filtered stream, and membership filtering is not a redaction
  boundary either.
- **Bootstrap tokens are authenticated, not authorized.** A token proves it was
  issued for a view and has not been tampered with. It does not prove the bearer
  may read that view — check that yourself on every request.
- **Client-supplied mutation IDs are trusted for idempotency.** Two clients that
  collide on an ID within one space will see a protocol error, not silent data
  loss, but IDs should still be generated as UUIDs by the client.

The engine's own threat model, including what the conflict and provenance
machinery does and does not promise, is documented in `cboxdk/sync`.
