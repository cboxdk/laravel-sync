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
     * The casts whose stored form is JSON text. Their value travels as the
     * JSON it holds, so a device sees an object rather than a string of one.
     */
    private const SYNC_JSON_CASTS = ['array', 'json', 'json:unicode', 'object', 'collection'];

    /**
     * Save, with the row and the log moving together.
     *
     * The write and its recording share one transaction on the model's
     * connection. A conflict or a refusal raised while recording rolls the
     * row back, and an observer that cancels the save rolls back a recording
     * that already happened - there is no order of listeners in which the
     * table and the log can end up disagreeing.
     *
     * A model that defines its own save() replaces this one; it still records,
     * but loses the shared transaction.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (self::$syncSuspended) {
            return parent::save($options);
        }

        try {
            return $this->getConnection()->transaction(function () use ($options): bool {
                if (! parent::save($options)) {
                    throw new Exceptions\SaveCancelled;
                }

                return true;
            });
        } catch (Exceptions\SaveCancelled) {
            return false;
        }
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
     * Recorded after the write, inside save()'s transaction: a create is read
     * back from the table so the log holds the defaults the database filled
     * in, and an update carries exactly this save's changes.
     *
     * These are events rather than overrides of delete() on purpose:
     * overriding delete() would collide with SoftDeletes, which a host is very
     * likely to be using already.
     */
    public static function bootSyncable(): void
    {
        static::created(static function (self $model): void {
            if (self::$syncSuspended) {
                return;
            }
            // Read back, so a column the database defaulted is in the log as
            // the value it has - not left out, and not recorded as null.
            $stored = $model->newQueryWithoutScopes()->whereKey($model->getKey())->first() ?? $model;
            app(SyncRecorder::class)->record($stored, changed: $stored->syncFields());
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
        });

        static::updated(static function (self $model): void {
            if (self::$syncSuspended) {
                return;
            }
            app(SyncRecorder::class)->record($model, changed: array_keys($model->getChanges()));
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
     * The values these fields carry on the wire: what the column holds.
     *
     * The stored form, not the cast or accessor form. An accessor's output is
     * presentation - recording it put upper-cased text in the log and then in
     * the column - and a date serialized to UTC moved a Copenhagen date back a
     * day. JSON columns are the one exception: they travel as the JSON they
     * hold, so a device sees a document, not a string.
     *
     * A field the row has no attribute for is left out, rather than recorded
     * as null.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function syncValues(array $fields): array
    {
        $raw = $this->getAttributes();
        $values = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];
            if (is_string($value) && $this->hasCast($field, self::SYNC_JSON_CASTS)) {
                $value = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * The inverse of syncValues(): put values from the log into the row as the
     * column holds them, past casts and mutators.
     *
     * @param  array<string, mixed>  $values
     */
    public function syncFill(array $values): static
    {
        $raw = [];
        foreach ($values as $field => $value) {
            if ($value !== null && ($this->hasCast($field, self::SYNC_JSON_CASTS) || is_array($value) || is_object($value))) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $raw[$field] = $value;
        }
        $this->setRawAttributes(array_merge($this->getAttributes(), $raw));

        return $this;
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
        // The tenant is never a field. It is the space the record lives in; a
        // field for it would let a client write a tenant id into the log that
        // disagrees with where the record actually is.
        $tenant = $this->syncScopeColumn();

        $declared = $this->syncDeclared('syncFields');
        if (is_array($declared) && $declared !== []) {
            return array_values(array_diff(array_filter($declared, is_string(...)), [$tenant]));
        }

        // Fillable is the host's own statement of what a request may set, which
        // is the same question this is asking. Hidden is its statement of what
        // a response must never show, so a hidden fillable field is not synced:
        // syncing it would put it in every device's database.
        $fillable = array_values(array_diff($this->getFillable(), $this->getHidden(), [$tenant]));
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
        // get_object_vars from inside the class sees protected properties, and
        // names the property without a variable-variable lookup.
        return get_object_vars($this)[$name] ?? null;
    }
}
