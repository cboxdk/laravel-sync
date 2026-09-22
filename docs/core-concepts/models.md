---
title: "Syncing a model"
weight: 5
description: "One trait on an Eloquent model, and your existing endpoints gain conflict resolution."
---

# Syncing a model

```php
class Note extends Model
{
    use Syncable;
}
```

```php
// config/sync.php - the keys you change; the rest keep their defaults
return [
    'api' => [
        'enabled' => true,
        'types' => [Note::class],
    ],
];
```

That is the registration. Everything else is read off the model the way the rest
of Laravel reads it.

| What | Where it comes from | Override |
|---|---|---|
| Entity type | the table name | `protected string $syncType` |
| Synced fields | `$fillable`, minus anything in `$hidden` | `protected array $syncFields` |
| Never writable by a client | the key and the timestamps | `protected array $syncReadOnly` |
| The tenant | the first of `team_id`, `tenant_id`, `organization_id`, `workspace_id`, `account_id` the table has | `protected string $syncScope` |

A model with neither `$fillable` nor `$syncFields` is refused rather than
guessed at: every column would include whatever the table holds - tokens, internal
notes, columns added next year - and would put it on every device. A field in
`$hidden` is never synced for the same reason.

Values travel in the model's **serialized** form - what `toArray()` and an API
resource show - and are written back through `fill()`. That is what makes casts
round-trip: an `array` field is a JSON object on the wire and an array again in
your model, a date is an ISO 8601 string, an enum is its value.

Authorization is **not** here. It goes to the Gate, so your existing policy
decides:

```php
class NotePolicy
{
    public function viewAny(User $user): bool { /* … */ }
    public function create(User $user): bool { /* … */ }
    public function update(User $user, Note $note): bool { /* … */ }
    public function delete(User $user, Note $note): bool { /* … */ }
}
```

The policy is handed **your own row**, loaded from the table, with the values sync
holds laid over the synced fields. The row, because a policy reads more than the
synced fields - a lock flag, an owner, the tenant; the synced values on top,
because they are what the engine is about to merge into, and the table can be
behind them while a write is in flight.

### What the policy does not cover

Reads are decided by `viewAny`, per tenant: a caller allowed to see notes in a
team sees every note in that team. A per-row `view` rule is **not** applied to
bootstrap and delta, because a device's window has to be a view the server can
describe and keep consistent as rows change. If some rows in a tenant must stay
hidden from some members, write a `SyncableType` whose `view()` expresses that
rule, and let sync bind it into the cursor.

## The key is a UUID the server chooses

Sync names a new record from the mutation that created it, before the row is
inserted, so a replay of the same mutation lands on the same id instead of
creating a second row. An auto-incrementing key cannot work: the database only
names a row on insert. Registering such a model is refused by name rather than
failing later as a constraint violation.

```php
class Note extends Model
{
    use Syncable;

    public $incrementing = false;

    protected $keyType = 'string';
}
```

The name is a UUID (version 8, derived from the mutation), so a `uuid` column or
any string column of at least 36 characters holds it.

The *name* still comes from the server, not the client: an id a client chooses is
attacker-controlled input in a key position, so a create carries only a handle the
device made up and the response says what the record is actually called.

## Your own endpoints gain conflict resolution

No new routes. An ordinary controller keeps its shape:

```php
Route::patch('/api/notes/{note}', function (Note $note) {
    $note->update($request->validated());

    return new NoteResource($note);
});
```

A client that knows nothing about versions gets exactly what it always got. A
client that sends the version it was looking at gets its write checked against
that version:

```http
PATCH /api/notes/n1
If-Match: "7"

{"title": "Mine"}
```

or `base_version` in the body. The check runs **before** the row is written. A
write that lost the race raises `SyncConflict`, which Laravel renders as **409**
carrying what the record says now - and your table still holds the winning
value, not the one that lost:

```json
{
  "message": "The record changed while this write was being made.",
  "version": 9,
  "conflicts": [
    {"field": "title", "current": "Theirs", "proposed": "Mine"}
  ]
}
```

Two people editing **different fields** of the same row do not conflict at all.
That is the case a record-level `updated_at` comparison gets wrong every time,
and the one that actually happens in a team.

A write the host's validator refuses raises `SyncRejected`, rendered as **422**,
and is likewise never written to the table.

## Every save reaches the devices

Saving or deleting a model that uses the trait records a mutation, wherever it
happens — an admin screen, a console command, a job. A write that skips the log
is invisible to every offline device for ever, because they are following a
sequence it never appeared in.

Sync's own write back to your table does not count as a new edit; that is what
`Note::withoutSyncing()` marks, and you can use it yourself for an import that
should not be replayed to devices.

An update is recorded on `updating`, before the row is written, and carries only
the attributes this save changed. A create is recorded on `created`, after the
insert, because a key your model generates itself is only there by then.

Three things are refused rather than half-done:

- **Moving a record to another tenant** by changing its tenant column. The tenant
  is part of the record's key in the log; moving it would leave the old tenant
  with a live copy. Delete it and create it in the new tenant.
- **Restoring a soft-deleted record.** A delete is permanent in the log - every
  device has already dropped the record. Create a new one instead.
- **A model on a different database connection from the sync store.** The row and
  the log commit together only if one transaction covers both; set
  `sync.connection` to the model's connection.

**Atomicity, stated rather than implied:** Laravel does not wrap `save()` in a
transaction. A conflict or a refusal is caught before the write, so those never
leave the two apart; a database failure part-way through can. Wrap your own
write in `DB::transaction()` if that matters. The API path already does - a
client's write and its record commit or roll back as one, and a device is only
told about it once the outermost transaction has committed.
