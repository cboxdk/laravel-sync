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
- `replica` identifies this device. `sequence` is your own counter for that
  replica: gapless, ascending, starting at 1.
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

THE FOUR ANSWERS TO A PUSH (read `status`, and the HTTP code)
1. 200 with status applied | partial | noop -> it landed. Move on.
2. 200 with status conflict | rejected | validation_failed | precondition_failed
   -> it was processed but did NOT land as you asked. SURFACE THIS TO THE USER.
   "Sent" is not "saved". Nothing else in the system will mention it.
3. 200 with status mutation_gap -> the server has not seen everything before
   this. Renumber from `acknowledged_sequence` + 1 and resend. If you get the
   same answer twice in a row, stop and back off; do not loop.
4. 503, or `retriable: true` -> resend the IDENTICAL request, same mutation_id,
   after a delay. Do not renumber, do not drop it.
   Any other non-2xx -> do not resend as-is; fix the request or surface it.

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
- kind "remove": the record LEFT YOUR VIEW. That is NOT the same as deleted - it
  may still exist and someone else may still see it. Remove it from this view's
  local set; do not assume it is gone.

WHEN THE SERVER SAYS reset_required (409)
- Your local state for that view is no longer valid. Read `reason` if you want
  to log it. Then: discard what you have for that view, forget the cursor, and
  bootstrap again from scratch. Do not retry the same cursor.

STAYING CURRENT
- Subscribe to the host's change notification if it offers one; it tells you a
  space advanced and how far. When it fires, push then pull.
- KEEP POLLING ANYWAY, on a slow timer. Notification delivery is at-most-once:
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
