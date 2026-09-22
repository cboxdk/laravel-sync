---
title: "Hand this to an agent"
weight: 40
description: "A copy-pasteable brief for Claude, Codex, Lovable or anything else building a client."
---

# Hand this to an agent

Paste the block below into whatever is writing your client, together with
`openapi.yaml` from the package root. The spec has the shapes; this has the
rules that are invisible in a schema and expensive to get wrong.

Everything in it is enforced by the server, so an agent that ignores a line
finds out at runtime rather than in review.

````text
You are integrating with Cbox Sync. Read openapi.yaml for the exact shapes.
Three endpoints: POST /push, POST /bootstrap, POST /delta.

THE MODEL
- A "space" is the tenant. The server assigns every write in it a gapless
  sequence number. Clocks are never used for ordering; do not send timestamps
  and do not compare them.
- A "view" is the filtered window you read. You follow it with a cursor.
- Conflicts are detected PER FIELD. Two people editing different fields of the
  same record do not conflict.

WRITING
- One mutation per POST /push. Send them in your own order, one at a time.
- Every mutation needs a `mutation_id` you generate (a UUID is fine) and that is
  globally unique. Re-sending the same id is SAFE and returns the same answer.
  That is how you recover from a lost response: retry the identical request.
  Never reuse an id with different content - that is a permanent error.
- `replica` identifies a stream of writes. `sequence` is your own counter for
  that replica: gapless, ascending, starting at 1.
- Use ONE replica value per device PER type AND scope you write to (for example
  `device-7/tasks/team-1`), each with its own counter. The server numbers per
  replica per space, and several scopes can map to one space; sharing a counter
  across them makes writes collide.
- On `mutation_gap`, set your counter to EXACTLY `acknowledged_sequence` -
  lower or higher than you had - and resend. Lower: the server was restored from
  a backup. Higher (reason `sequence_behind`): your device was. Nothing you
  still hold is lost.
- `receipt_pruned`: this write may already have landed and its answer is gone.
  Do NOT resend it. Treat its own `sequence` as acknowledged (not
  `acknowledged_sequence`), tell the user its outcome is unknown, go on.
- 401 means the session expired. Keep the queue; sign in again and resend.
- Assign `sequence` when you SEND, not when you queue. A mutation that never
  reaches the server must not consume a number, or the server waits for it
  forever and every later write comes back as a gap.
- `base_version` is the record version your edit was made against. It is what
  conflict detection compares. Use the `version` from the last record you saw.
  Use 0 for a create.

CREATING A RECORD
- You do NOT choose the id. Send `id` as a temporary handle you made up.
- The response returns the real `id` plus your handle as `temp_id`.
- Rewrite anything you still have queued that refers to the handle, and anything
  you stored locally under it. A field VALUE holding the handle - a child row
  carrying its parent's id - is yours to fix; the server cannot know which of
  your fields are references.

THE ANSWERS TO A PUSH (read `status`, and the HTTP code)
1. 200 with status applied | partial | noop -> it landed. Move on. EXCEPT: if
   `decisions` names a field as `server_wins`, the server kept its own value
   for that field. Tell the user that edit did not stick.
2. 200 with status conflict | rejected | validation_failed | precondition_failed
   -> it was processed but did NOT land as you asked. SURFACE THIS TO THE USER.
   "Sent" is not "saved". Nothing else in the system will mention it.
3. 200 with status mutation_gap -> the server has not seen everything before
   this. Renumber from `acknowledged_sequence` + 1 and resend. If you get the
   same answer twice in a row, stop and back off; do not loop.
4. 200 with status pull_required -> only if you sent `on_conflict: "pull"`.
   Nothing was stored. See DECIDING CONFLICTS ON THE DEVICE.
5. 503, or `retriable: true` -> resend the IDENTICAL request, same mutation_id,
   after a delay. Do not renumber, do not drop it.
   Any other non-2xx -> do not resend as-is; fix the request or surface it.

DECIDING CONFLICTS ON THE DEVICE (optional)
- By default a conflicting edit is kept next to the other one in a conflict
  group, for a person to choose. That needs a resolve UI.
- Send `"on_conflict": "pull"` instead and the server refuses a stale edit with
  status `pull_required`, storing nothing. `conflicts` lists each field someone
  else changed, with their value in `current`, and `record_version` is what
  they changed it to.
- Decide per field: keep yours, take theirs, or merge. Then resend THE SAME
  `mutation_id` and THE SAME `sequence` with the decided operations and
  `base_version` = the refusal's `record_version`. Not the version from a newer
  pull: that could overwrite fields nobody looked at.
- Give up after about three refusals in a row and resend without `on_conflict`;
  the server then keeps both values, which loses nothing.

READING
- First time: POST /bootstrap with no `token`. Repeat with the `next_token` you
  get back until it is null. Save `cursor` - it appears only on the LAST page,
  and only save it once every record on that page is durable locally.
- After that: POST /delta with your saved cursor. Loop while `has_more` is true,
  carrying the returned `cursor` each time. Save it as you go.
- Send the cursor back EXACTLY as received. Do not construct one.

VALUES - the two distinctions that break naive clients
- `{"present": false}` means the field has NO value. `{"present": true,
  "value": null}` means it is set to null. These are different. Preserve both.
- Writing: `{"op":"set","value":null}` stores a null. `{"op":"unset"}` removes
  the field. Different operations.
- An empty object {} and an empty array [] are DIFFERENT values. If your language
  collapses them (PHP's json_decode with assoc, some JS paths), you will corrupt
  data silently. Decode to a type that keeps them apart.
- Send at most one operation per field per mutation.

DELTA CHANGES
- kind "upsert": the record is in your view now, with the version given.
- kind "removed_from_scope": the record LEFT YOUR VIEW. That is NOT the same as
  deleted - it may still exist and someone else may still see it. Remove it from
  this view's local set; do not assume it is gone. You may be told this about an
  id you never had; then there is nothing to remove.
- kind "deleted": the record is gone for good. Drop it everywhere, and never
  bring it back on an older upsert.

WHEN THE SERVER SAYS reset_required (409)
- Your local state for that view is no longer valid. Read `reason` if you want
  to log it. Then: discard what you have for that view, forget the cursor, and
  bootstrap again from scratch. Do not retry the same cursor.

STAYING CURRENT
- Polling is a complete strategy. A timer that pushes then pulls is correct on
  its own, and is all you need unless latency matters. Start here.
- If the host offers a change notification, subscribe to it as well: it tells
  you a space advanced and how far, so you can sync immediately instead of
  waiting for the timer.
- KEEP THE TIMER even then, just slower. Notification delivery is at-most-once:
  a missed signal must never mean missed data. The signal makes you prompt; the
  cursor is what makes you correct.

WHAT NOT TO DO
- Do not merge field values yourself. The server decides; you display the answer.
- Do not invent record ids. Do not reuse a mutation_id with new content.
- Do not treat a 200 as success without reading `status`.
- Do not parse `message`. Branch on `error` and `status` only.
````

## Why this is a page and not a README paragraph

The rules above are the ones a schema cannot express, and every one of them
corresponds to a failure that is silent rather than loud: a corrupted empty
object, a write reported as sent and never saved, a queue that wedges because a
number was taken by a mutation the server never saw.

They are kept here, next to `openapi.yaml`, because the description and the
brief drift apart the moment they live in different places. The spec is checked
against the endpoints' real answers by the test suite; this page is checked by
the same thing the rest of the docs are - it says only what the package does.
