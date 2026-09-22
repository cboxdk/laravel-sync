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
// config/sync.php
'types' => [Note::class],
```

That is the registration. Everything else is read off the model the way the rest
of Laravel reads it.

| What | Where it comes from | Override |
|---|---|---|
| Entity type | the table name | `protected string $syncType` |
| Synced fields | `$fillable` | `protected array $syncFields` |
| Never writable by a client | the key and the timestamps | `protected array $syncReadOnly` |
| The tenant | the first of `team_id`, `tenant_id`, `organization_id`, `workspace_id`, `account_id` the table has | `protected string $syncScope` |

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

The policy is handed a model carrying the record **as sync holds it**, not as the
table holds it. Those differ for as long as a write is in flight, and the one the
engine is about to merge into is the one the decision has to be made against.

## The key is a string the server chooses

Sync names a new record from the mutation that created it, before the row is
inserted, so a replay of the same mutation lands on the same id instead of
creating a second row. An auto-incrementing key cannot work: the database only
names a row on insert. Registering such a model is refused by name rather than
failing later as a constraint violation.

```php
public $incrementing = false;

protected $keyType = 'string';
```

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

or `base_version` in the body. A write that lost the race raises `SyncConflict`,
which Laravel renders as **409** carrying what the record says now:

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

## Every save reaches the devices

Saving or deleting a model that uses the trait records a mutation, wherever it
happens — an admin screen, a console command, a job. A write that skips the log
is invisible to every offline device for ever, because they are following a
sequence it never appeared in.

Sync's own write back to your table does not count as a new edit; that is what
`Note::withoutSyncing()` marks, and you can use it yourself for an import that
should not be replayed to devices.

**Atomicity, stated rather than implied:** the event fires inside
`Model::save()`, which Laravel does not wrap in a transaction. Wrap your own
write in `DB::transaction()` if the row and the log must move together. The API
path already does this — a client's write and its record commit or roll back as
one.
