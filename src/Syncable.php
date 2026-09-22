<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel;

use Illuminate\Support\Facades\Schema;

/**
 * Makes an Eloquent model syncable, with nothing else to write.
 *
 *     class Task extends Model
 *     {
 *         use Syncable;
 *     }
 *
 * Everything is read off the model the way the rest of Laravel reads it: the
 * entity type is the table, the synced fields are the fillable ones, the key
 * and the timestamps are never writable by a client, and the tenant is the
 * first tenancy-shaped column the table actually has.
 *
 * Each of those is a convention, not a rule. Declare any of the four properties
 * below on the model and it wins:
 *
 *     protected string $syncType = 'todo_items';
 *     protected array $syncFields = ['title', 'status', 'due_at'];
 *     protected array $syncReadOnly = ['created_by'];
 *     protected string $syncScope = 'workspace_id';
 *
 * Authorization is deliberately absent here. It belongs in the model's policy,
 * which this package calls through the Gate, so a host has one place where who
 * may do what is decided rather than two that can disagree.
 */
trait Syncable
{
    /** @var array<class-string, list<string>> One schema read per model class, not per call. */
    private static array $syncColumnCache = [];

    /** Per using-class, which is what a trait's static property gives us and what we want here. */
    private static bool $syncSuspended = false;

    /**
     * Run something without it counting as a change to sync.
     *
     * Sync writes the canonical value back to this table, and that write must
     * not be read as a new edit - it is the answer to one. Without this the
     * model observer turns every applied mutation into another mutation, for
     * ever.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutSyncing(\Closure $callback): mixed
    {
        $previous = self::$syncSuspended;
        self::$syncSuspended = true;

        try {
            return $callback();
        } finally {
            self::$syncSuspended = $previous;
        }
    }

    /** Whether a write happening right now is sync's own. */
    public static function syncSuspended(): bool
    {
        return self::$syncSuspended;
    }

    /**
     * Every ordinary save and delete becomes a mutation, so the log knows.
     *
     * An edit made anywhere in the application - an admin screen, a console
     * command, a job - has to reach the devices, and the only way it can is by
     * being in the log. A write that skips it is invisible to every offline
     * client for ever, because they are following a sequence it never appeared
     * in.
     *
     * An update is recorded BEFORE the row is written, on `updating`. That is
     * what makes a conflict a clean 409: the log refuses, the exception stops
     * Eloquent, and the table never holds the value that lost. Recording after
     * the write left the rejected value in the table and the old one in the
     * log. It also carries exactly this write's changes - the dirty attributes
     * - where the previous save's leftovers used to ride along.
     *
     * A create is recorded on `created`, after the insert, because a key the
     * model generates itself (HasUuids and friends) is only there by then. A
     * create cannot conflict, so nothing is lost by waiting.
     *
     * These are events rather than overrides of save() and delete() on purpose:
     * overriding delete() would collide with SoftDeletes, which a host is very
     * likely to be using already.
     *
     * ATOMICITY: Laravel does not wrap save() in a transaction. Wrap your own
     * write in DB::transaction() if the row and the log must move together
     * even when the database itself fails part-way. The API path does this for
     * you - a client's write and its record commit or roll back as one.
     */
    public static function bootSyncable(): void
    {
        static::created(static function (self $model): void {
            if (self::$syncSuspended) {
                return;
            }
            app(SyncRecorder::class)->record($model, changed: $model->syncFields());
        });

        static::updating(static function (self $model): void {
            if (self::$syncSuspended) {
                return;
            }
            $column = $model->syncScopeColumn();
            if ($column !== null && $model->isDirty($column)) {
                // The tenant is part of the record's key in the log. Moving it
                // would leave the old tenant holding a live copy with authority
                // over a row that is no longer theirs.
                throw new \LogicException(sprintf(
                    'A synced %s cannot move between tenants by changing "%s". Delete it and create it in the new tenant.',
                    static::class,
                    $column,
                ));
            }
            app(SyncRecorder::class)->record($model, changed: array_keys($model->getDirty()));
        });

        static::deleted(static function (self $model): void {
            if (self::$syncSuspended) {
                return;
            }
            app(SyncRecorder::class)->record($model, deleting: true);
        });

        if (method_exists(static::class, 'restoring')) {
            static::restoring(static function (self $model): void {
                if (self::$syncSuspended) {
                    return;
                }
                // A delete is permanent in the log - devices have already
                // dropped the record and been told its id is spent. Restoring
                // the row here would bring it back for this server and nobody
                // else, silently.
                throw new \LogicException(sprintf(
                    'A synced %s cannot be restored once deleted: the delete has already reached every device. Create a new record instead.',
                    static::class,
                ));
            });
        }
    }

    /**
     * The value a synced field carries on the wire: the model's own serialized
     * form, as an API resource would show it.
     *
     * Not the raw attribute. For a field cast to array the raw attribute is the
     * JSON text in the column, and writing that back through the cast encoded
     * it a second time - a document became a string. The serialized form is
     * what fill() takes back, so the round trip is exact for casts, dates,
     * enums and encrypted columns alike.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function syncValues(array $fields): array
    {
        $serialized = $this->attributesToArray();
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = array_key_exists($field, $serialized) ? $serialized[$field] : $this->getAttribute($field);
        }

        return $values;
    }

    /** The entity type written into every synced key. Never change it once rows exist. */
    public function syncEntityType(): string
    {
        $declared = $this->syncDeclared('syncType');

        return is_string($declared) && $declared !== '' ? $declared : $this->getTable();
    }

    /**
     * Fields that cross the wire, readable by default.
     *
     * @return list<string>
     */
    public function syncFields(): array
    {
        $declared = $this->syncDeclared('syncFields');
        if (is_array($declared) && $declared !== []) {
            return array_values(array_filter($declared, is_string(...)));
        }

        // Fillable is the host's own statement of what a request may set, which
        // is the same question this is asking. Hidden is its statement of what
        // a response must never show, so a hidden fillable field is not synced:
        // syncing it would put it in every device's database.
        $fillable = array_values(array_diff($this->getFillable(), $this->getHidden()));
        if ($fillable !== []) {
            return $fillable;
        }

        // Nothing said. Every column would include whatever the table holds -
        // tokens, internal notes, columns added next year - so refuse rather
        // than guess.
        throw new \LogicException(sprintf(
            '%s declares nothing to sync. Set $fillable, or $syncFields to the fields devices may see and change.',
            static::class,
        ));
    }

    /**
     * Fields a client may read but never write.
     *
     * The key and the timestamps are always here: letting a client set them
     * would let it rewrite identity and ordering, and neither is its to choose.
     *
     * @return list<string>
     */
    public function syncReadOnly(): array
    {
        $declared = $this->syncDeclared('syncReadOnly');
        $host = is_array($declared) ? array_values(array_filter($declared, is_string(...))) : [];

        $never = [...$host, ...$this->syncNeverWritable()];

        return array_values(array_intersect($this->syncFields(), $never));
    }

    /**
     * The column holding the tenant this row belongs to.
     *
     * Null means the model is not multi-tenant, and every row lives in one
     * shared space. That is a real answer for a single-tenant application and a
     * dangerous one everywhere else, so it is only ever reached by a table that
     * has none of the conventional columns.
     */
    public function syncScopeColumn(): ?string
    {
        $declared = $this->syncDeclared('syncScope');
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        foreach (['team_id', 'tenant_id', 'organization_id', 'workspace_id', 'account_id'] as $column) {
            if (in_array($column, $this->syncColumns(), true)) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function syncNeverWritable(): array
    {
        $never = [$this->getKeyName()];
        if ($this->usesTimestamps()) {
            $never[] = $this->getCreatedAtColumn();
            $never[] = $this->getUpdatedAtColumn();
        }
        if (method_exists($this, 'getDeletedAtColumn')) {
            $never[] = $this->getDeletedAtColumn();
        }

        return array_values(array_filter($never, is_string(...)));
    }

    /** @return list<string> */
    private function syncColumns(): array
    {
        return self::$syncColumnCache[static::class] ??= Schema::connection($this->getConnectionName())
            ->getColumnListing($this->getTable());
    }

    /** A property the host declared on the model, or null when it did not. */
    private function syncDeclared(string $name): mixed
    {
        return property_exists($this, $name) ? $this->{$name} : null;
    }
}
