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

Values travel in **one** form, whatever the database driver and whatever wrote
them, and are written back past mutators:

| Cast | On the wire |
|---|---|
| `boolean`, `integer`, `float` | the typed value - `true`, `4`, `1.5` |
| `decimal:2` | the formatted string, `"13.50"` |
| `date` | `"2026-03-10"` |
| `datetime` and friends | the model's storage format, `"2026-03-10 09:30:00"`; a device may send ISO 8601 |
| `array`, `json`, `object`, `collection`, `AsArrayObject`, `AsCollection` | the JSON document itself |
| backed enum | its value |
| anything else | what the column holds |

A device's values are put through the model before they are logged - casts,
mutators, a date's offset honoured and expressed in the app's timezone - so the
log holds the same value for a field whichever path wrote it. A value the model
cannot hold, an unknown enum case say, is refused with `invalid_field_value`
before anything is stored. What the application's own observers make of a write
reaches the devices too: after the row is written it is read back, and any
difference in any synced field - including a column the database defaulted on a
device's create - is recorded as the server's own write, one version later, and
counted as part of the device's write: its answer carries that version, so the
device's next edit does not conflict with it. A
value only the table refuses (NULL in a NOT NULL column, a string too long, a
broken foreign key) rolls the whole write back and is answered 422
`invalid_field_value`; the database's own message goes to your log, not to the
device.

A device's value goes through the model's cast and mutator for that field, on
the row as it stands, so a mutator that reads another column sees it. A mutator
that also sets OTHER columns is not applied to device writes - the row is written
in stored form, and a write may not change fields it did not send. Derive those
in an observer instead: it runs on the write, and what it stores reaches every
device as the server's own write. An observer that vetoes a device's write (a
`saving` or `deleting` listener returning false) refuses it as a final 403
`forbidden`.

An accessor's presentation never reaches the log, a column the database defaulted
is logged as the value it got, and a date keeps its day whatever the app
timezone. Sync's own reads and writes of your table ignore global scopes: a row
your application hides is still the row. An **encrypted** column is never synced: a device has no key to write
it, and sending it decrypted would undo the encryption.

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

### Which rows a device sees

`viewAny` decides whether a caller may read the type at all; the policy's `view`
rule, when it has one, decides which rows. It applies to bootstrap pages, to
every change a delta carries - a row that becomes hidden is removed from the
device - and to what a conflict answer may disclose. The rule is asked about your
real row, with sync's values over it, so a rule that reads a column devices never
see still hides what it hides on REST. That is one indexed read per row it
judges. A rule that throws hides the row rather than failing the page.

The rule can only judge a row as it is now, so a change to a row it does not show
now reaches a device as a removal of the id and nothing else - including a row
deleted since the device last synced, and a row that moved to another owner. A
device may be told an id it never had has left; it is never sent the content of
a row it may not see. The price: every reader in a tenant sees the id, version
and timing of each change to rows hidden from them - never their fields. Record
ids here are UUIDs the server chooses, so an id carries no meaning of its own. A change to the rule itself - a user losing access to a
project - is not a change to any row: bump the principal's `binding` and devices
rebuild their window under the new rule.

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
Route::patch('/api/notes/{note}', function (UpdateNoteRequest $request, Note $note) {
    $note->update($request->validated());

    return new NoteResource($note);
});
```

A client that knows nothing about versions gets exactly what it always got. A
client that sends a version gets one of two checks, and which one is its choice:

```http
PATCH /api/notes/n1
Content-Type: application/json

{"title": "Mine", "base_version": 7}
```

Both apply to the model the route is about - the one route model binding handed
your controller - and to nothing else saved while handling the request.

`base_version` is **field-level**: the write merges unless someone else changed
one of the same fields since version 7. Edits to other fields go through. This
is what a sync-aware client wants.

```http
PATCH /api/notes/n1
If-Match: "7"
```

`If-Match` is what HTTP says it is - a precondition on the **whole** record. If
anything changed since version 7, even a field this write does not touch, the
answer is **412** and nothing is written. It is checked once per request: the
request's later saves of the same record are its own work. A list (`"6", "7"`) accepts any of
them, `*` accepts any version, and a header with nothing comparable in it fails
rather than being ignored. This is what a REST client that sends an ETag back
expects.

Both checks run **before** the row is written. A write that lost raises
`SyncConflict`, which Laravel renders as **409** (or 412) carrying what the
record says now - and your table still holds the winning value, not the one
that lost:

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

`save()`, `delete()`, `increment()` and `decrement()` each run in one
transaction with their recording, so a conflict or refusal while recording rolls
the row back. An update carries only the attributes this save changed; a create
is read back from the table so the log has the defaults the database filled in.
A model that overrides `save()` itself keeps recording but loses the shared
transaction.

### What does not record

Recording hangs off the model's own events, so a write that skips them skips
sync too: `Model::query()->update()`, `DB::table()`, `saveQuietly()` and
`incrementEach()`. Devices never hear about those writes. Use the model, or wrap
bulk work in a loop over models when devices need to see it.

Three things are refused rather than half-done:

- **Moving a record to another tenant** by changing its tenant column. The tenant
  is part of the record's key in the log; moving it would leave the old tenant
  with a live copy. Delete it and create it in the new tenant.
- **Restoring a soft-deleted record.** A delete is permanent in the log - every
  device has already dropped the record. Create a new one instead.
- **A model on a different database connection from the sync store.** The row and
  the log commit together only if one transaction covers both; set
  `sync.connection` to the model's connection.

**Atomicity, stated rather than implied:** the row and its recording commit
together when the model and the sync store share a connection - the default. The
API path refuses a model on another connection outright; an ordinary save on one
still records, but the two are then separate commits. A device is only told
about a write once the outermost transaction has committed.
