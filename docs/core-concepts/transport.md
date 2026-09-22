---
title: "HTTP transport"
weight: 20
description: "The wire format, and the two identifiers it has to bind."
---

# HTTP transport

Three endpoints, all `POST`, all JSON, all off by default. Enable them with
`SYNC_API_ENABLED=true` and put your own guard in `sync.api.middleware` — the
package authenticates nothing on its own.

```
POST {prefix}/push       apply one mutation
POST {prefix}/bootstrap  page through the initial dataset
POST {prefix}/delta      everything since a cursor
```

Every request names a `type` and an optional `scope`. **No request names a
space.** The space comes from `SyncableType::space()`, which maps the scope
through the caller's own memberships — a client that could name its own space
would be choosing its own isolation boundary.

## Push

```json
{
  "type": "tasks",
  "scope": "team-1",
  "mutation_id": "01J…",
  "id": "task-42",
  "replica": "device-7",
  "sequence": 7,
  "kind": "update",
  "base_version": 3,
  "atomic": true,
  "expected_version": 3,
  "depends_on": "01J…",
  "operations": [
    {"field": "title", "op": "set", "value": {"any": "json"}},
    {"field": "due_at", "op": "unset"}
  ]
}
```

`kind` is `create`, `update`, `delete` or `resolve`. A resolve carries exactly
one operation plus a `resolution` of `group_id`, `group_revision` and
`candidate_ids` — all three come straight from the push response that reported
the conflict.

**`op` is explicit and `value: null` never means "unset".** A field set to null
and a field that has no value are different states that serialize identically,
so the distinction lives in the operation. Sending `from` is refused: it reaches
nothing in the engine except the mutation's identity, so accepting it would let a
client turn its own legitimate retry into a terminal error.

Every processed outcome is **200**, including `conflict`, `rejected`,
`precondition_failed`, `validation_failed`, `mutation_gap` and `pull_required`.
Those are answers, not failures.

### Letting the device decide: `on_conflict`

By default the server's resolver settles a conflict, and the default resolver
keeps both values in a conflict group for someone to choose between. That is the
safe answer for a server that cannot ask anyone, and it needs a resolve UI.

A device that *can* ask - or that knows its own merge rule - sends
`"on_conflict": "pull"`. A field the resolver would have preserved is then
refused instead: status `pull_required`, nothing stored, no receipt, no
acknowledgement, no commit. `conflicts` names each field someone else changed
(with their value, if the caller may read it) and `record_version` is the
version that carries it.

The device decides what its edit should now be and sends **the same
`mutation_id` and `sequence`** again with `base_version` set to that
`record_version`. Reusing the identity is safe precisely because the refusal
stored nothing, and required because a new one could let an earlier attempt
that did land be applied twice. Basing on `record_version` rather than on
whatever the device pulled since matters: a newer pull can include changes to
fields the refusal did not mention, and the resent write would overwrite them
unseen. If there are any, the server just refuses again and names them.

Pull never overrides the host. A field the resolver settles as client-wins or
server-wins is settled exactly as before, and a `reject_on_conflict` still
rejects. The whole mutation is refused, never half of it: applying the fresh
fields of an edit the device is about to rethink would leave a state nobody
chose.

```json
{
  "status": "conflict",
  "record_version": 4,
  "commit_sequence": 118,
  "acknowledged_sequence": 7,
  "reason": null,
  "accepted_versions": {"title": 4},
  "decisions": {"title": "preserve_conflict"},
  "conflicts": [{"field": "title", "field_version": 3, "effective_base": 3,
                 "current": {"present": true, "value": "server text"}}],
  "conflict_groups": [{"id": "01J…", "field": "title", "revision": 2,
                       "candidate_ids": ["…", "…"], "open": true}]
}
```

A `mutation_gap` carries only `status`, `acknowledged_sequence` and `reason`:
nothing was committed, so there is no version or sequence to report. Resume from
`acknowledged_sequence + 1`.

**What push never returns:** the receipt, the merged record, another device's
candidate *values*, or your own proposal echoed back. Candidate values are
another user's data; reading them is a separately authorized projection this
transport does not ship.

## Bootstrap and delta

Bootstrap with `page_size`, then follow `next_token` until it is null. The last
page carries `complete: true` and a `cursor`; hand that cursor to `delta`, and
keep handing back the cursor each response returns.

```json
{"position": 128, "context": "9f2a…"}
```

Every bootstrap and delta response carries the full `context` — space, view id,
filter version and signature, schema version, epoch — because a client needs it
to key its own local state, and being told which view it is already reading
discloses nothing it does not have.

Requests are the other direction and carry only the **fingerprint**:
`{"position": 128, "context": "9f2a…"}`. The server rebuilds the whole context
from your session and compares. Accepting a context from a client would let the
client choose its own space; the fingerprint is compared and never used to look
anything up, so it carries no authority — but it still has to make the trip, or
a client whose view definition changed would silently keep applying deltas onto
stale local state.

A delta change is `upsert`, `deleted` or `removed_from_scope`. Only an upsert
carries a record. `commits` can be empty while the cursor still advances, when
every change in a commit projected away — follow the cursor, not the array.

## The two identifiers the transport binds

`replica` and `mutation_id` are chosen by the client, and the engine honours both
without knowing who sent them. The transport namespaces each one under the
authenticated principal before the engine sees it.

Without that, anyone in a tenant who names another device's replica claims its
sequence numbers: that device's next push fails terminally for reusing a
sequence, and its queued mutations are unrecoverable because their identities are
now burned against different content. The same applies to a mutation id, which
one caller could otherwise burn before another uses it.

The binding uses the principal's **stable** id, never its authorization state, so
a permission change does not orphan a client's unflushed queue.

## Errors

```json
{"error": "reset_required", "message": "…", "retriable": false, "reason": "history_pruned"}
```

| HTTP | `error` | What the client does |
|---|---|---|
| 401 | `unauthenticated` | authenticate |
| 403 | `forbidden`, `field_not_writable` | stop; this will not succeed |
| 404 | `unknown_type` | stop |
| 409 | `reset_required` | reset that view and bootstrap again; `reason` says why |
| 409 | `protocol_violation` | terminal; needs a new mutation identity |
| 409 | `invalid_cursor` | bootstrap again |
| 413 / 415 | `body_too_large`, `unsupported_media_type` | fix the request |
| 422 | `invalid_request` | fix the request |
| 503 | `retry` | retry **the same mutation id**, after `Retry-After` |

The 503 is safe precisely because a repeated mutation id returns the stored
result. A client that mints a fresh id on retry loses that guarantee.
