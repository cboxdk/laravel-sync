<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\GuardPrincipals;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\Exceptions\SyncRejected;
use Cbox\Sync\Laravel\Syncable;
use Cbox\Sync\Laravel\SyncRecorder;
use Cbox\Sync\Laravel\Tests\Fixtures\Member;
use Cbox\Sync\Resolvers\ServerWins;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

enum CardKind: string
{
    case Task = 'task';
    case Bug = 'bug';
}

class Card extends Model
{
    use Syncable;

    protected $table = 'cards';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['title', 'status', 'due_on', 'due_at', 'done', 'priority', 'amount', 'counter', 'opts', 'secret', 'kind'];

    protected $casts = [
        'due_on' => 'date',
        'due_at' => 'datetime',
        'done' => 'boolean',
        'priority' => 'integer',
        'amount' => 'decimal:2',
        'counter' => 'integer',
        'opts' => AsArrayObject::class,
        'secret' => 'encrypted',
        'kind' => CardKind::class,
    ];
}

class ShoutingCard extends Card
{
    protected function title(): Attribute
    {
        return Attribute::get(fn (?string $value): ?string => $value === null ? null : strtoupper($value));
    }
}

beforeEach(function () {
    Schema::dropIfExists('cards');
    Schema::create('cards', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
        $table->string('title')->nullable();
        $table->string('status')->default('open');
        $table->date('due_on')->nullable();
        $table->dateTime('due_at')->nullable();
        $table->boolean('done')->default(false);
        $table->integer('priority')->nullable();
        $table->decimal('amount', 8, 2)->nullable();
        $table->integer('counter')->default(0);
        $table->json('opts')->nullable();
        $table->text('secret')->nullable();
        $table->string('kind')->nullable();
        $table->string('owner_id')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('cards');
    Schema::dropIfExists('members');
    date_default_timezone_set('UTC');
});

function logged(string $id): ?EntityRecord
{
    return app(Store::class)->record(new EntityKey('cards:owners', 'cards', $id));
}

function card(string $id, array $attributes = [], string $class = Card::class): Model
{
    $card = new $class;
    $card->forceFill(['id' => $id, 'team_id' => 'owners'] + $attributes)->save();

    return $card;
}

/**
 * A create used to log NULL for a column the database defaulted, and the next
 * device push wrote that NULL back - into a NOT NULL column, so every later
 * push to the record failed.
 */
it('logs the value the database filled in, not null', function () {
    card('d1', ['title' => 'x']);

    expect(logged('d1')?->value('status')->value())->toBe('open');
});

/** A date serialized to UTC moved a Copenhagen date back a day. The log holds what the column holds. */
it('keeps a date the date it is, whatever the app timezone', function () {
    config()->set('app.timezone', 'Europe/Copenhagen');
    date_default_timezone_set('Europe/Copenhagen');

    card('t1', ['due_on' => '2026-03-10']);

    expect(logged('t1')?->value('due_on')->value())->toStartWith('2026-03-10');
});

/** An accessor is presentation. Logging its output put upper-cased text in the log and then in the column. */
it('logs the stored value, not what an accessor makes of it', function () {
    card('a1', ['title' => 'hello'], ShoutingCard::class);

    expect(logged('a1')?->value('title')->value())->toBe('hello');
});

/** A save a listener cancels is not recorded, and leaves the log as it was. */
it('records nothing for a save a listener cancels', function () {
    $card = card('c1', ['title' => 'before']);
    Card::updating(fn (): bool => false);

    $card->title = 'after';
    expect($card->save())->toBeFalse();

    expect(Card::find('c1')?->title)->toBe('before')
        ->and(logged('c1')?->value('title')->value())->toBe('before');
});

/** A refused create used to throw with the row already in the table. */
it('leaves no row behind when the log refuses a create', function () {
    app()->instance(EntityValidator::class, new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return new ValidationResult([new ValidationFailure('no', 'No')]);
        }
    });
    foreach ([Engine::class, SyncRecorder::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    expect(fn () => card('r1', ['title' => 'bad']))->toThrow(SyncRejected::class);
    expect(Card::find('r1'))->toBeNull();
});

function routeForCards(): void
{
    Route::middleware(SubstituteBindings::class)->put('/api/cards/{card}', function (Card $card) {
        $card->update(request()->only(['title']));
        // Something else the handler saves along the way.
        $other = Card::find('other');
        if ($other !== null) {
            $other->update(['title' => 'touched by the same request']);
        }

        return response()->json(['id' => $card->id]);
    });
}

/**
 * If-Match lists the versions the caller accepts. A list, or anything the
 * parser did not understand, used to be ignored and the write went through.
 */
it('honours an If-Match list, and refuses one it cannot read', function () {
    routeForCards();
    $card = card('i1', ['title' => 'v1']);
    $card->update(['title' => 'v2']);

    $this->putJson('/api/cards/i1', ['title' => 'one of them'], ['If-Match' => '"1", "2"'])->assertOk();
    $this->putJson('/api/cards/i1', ['title' => 'stale'], ['If-Match' => '"1", "7"'])->assertStatus(412);
    $this->putJson('/api/cards/i1', ['title' => 'garbage'], ['If-Match' => '"abc"'])->assertStatus(412);
    $this->putJson('/api/cards/i1', ['title' => 'anything'], ['If-Match' => '*'])->assertOk();

    expect(Card::find('i1')?->title)->toBe('anything');
});

/** The header is about the route's model. It used to be applied to everything saved during the request. */
it('applies If-Match only to the model the route is about', function () {
    routeForCards();
    card('i2', ['title' => 'v1']);
    card('other', ['title' => 'unrelated']);

    $this->putJson('/api/cards/i2', ['title' => 'mine'], ['If-Match' => '"1"'])->assertOk();

    expect(Card::find('other')?->title)->toBe('touched by the same request');
});

/**
 * One representation whatever the driver and the write path. Raw attributes
 * were 1 on SQLite, true on PostgreSQL and "4" from a form, and a device
 * sending the same value as a number met it as a conflict.
 */
it('logs typed values the same way on every driver and every write path', function () {
    $card = card('v1', ['done' => true, 'priority' => 3, 'amount' => 12.5]);
    expect(logged('v1')?->value('done')->value())->toBeTrue()
        ->and(logged('v1')?->value('priority')->value())->toBe(3)
        ->and(logged('v1')?->value('amount')->value())->toBe('12.50');

    $card->update(['priority' => '4', 'amount' => '13.5', 'done' => '0']);
    expect(logged('v1')?->value('done')->value())->toBeFalse()
        ->and(logged('v1')?->value('priority')->value())->toBe(4)
        ->and(logged('v1')?->value('amount')->value())->toBe('13.50');
});

/** increment() writes without save(); a refusal while recording it used to leave the row incremented. */
it('takes an increment back when the log refuses it', function () {
    $card = card('n1', ['title' => 'x']);
    app()->instance(EntityValidator::class, new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return $context->proposed->value('counter')->value() === 1
                ? new ValidationResult([new ValidationFailure('no', 'No')])
                : new ValidationResult;
        }
    });
    foreach ([Engine::class, SyncRecorder::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    expect(fn () => $card->increment('counter'))->toThrow(SyncRejected::class);
    expect(Card::find('n1')?->counter)->toBe(0);
});

/** A delete refused while recording used to leave the row gone and the log saying it exists. */
it('puts the row back when the log refuses a delete', function () {
    Route::middleware(SubstituteBindings::class)->delete('/api/cards/{card}', function (Card $card) {
        $card->delete();

        return response()->noContent();
    });
    $card = card('x1', ['title' => 'v1']);
    $card->update(['title' => 'v2']);

    $this->deleteJson('/api/cards/x1', [], ['If-Match' => '"1"'])->assertStatus(412);

    expect(Card::find('x1'))->not->toBeNull()->and(logged('x1')?->deleted)->toBeFalse();
});

/** A device writes a date as ISO 8601; raw, it failed on MySQL and was stored verbatim on SQLite. */
it('stores a date a device sends in the form the column takes', function () {
    card('i1', ['title' => 'x']);

    Card::withoutSyncing(fn () => Card::find('i1')?->syncFill(['due_at' => '2026-09-23T10:00:00.000000Z'])->save());

    expect(Card::find('i1')?->getRawOriginal('due_at'))->toStartWith('2026-09-23 10:00:00');
});

it('carries an AsArrayObject column as the document it holds', function () {
    card('o1', ['opts' => ['a' => 1]]);

    expect(logged('o1')?->value('opts')->value())->toEqual((object) ['a' => 1]);
});

/** A device has no key to write an encrypted column, and sending it decrypted would undo the encryption. */
it('never syncs an encrypted column', function () {
    expect((new Card)->syncFields())->not->toContain('secret');
});

/** Cards served over the API with an allow-all policy, as alice of team "owners". */
function cardsOverApi(object $test): void
{
    Schema::dropIfExists('members');
    Schema::create('members', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
    });
    config()->set('sync.api.types', [Card::class]);
    app()->bind(SyncPrincipals::class, GuardPrincipals::class);
    app()->forgetInstance(SyncableTypes::class);
    Gate::policy(Card::class, AllowCards::class);
    $test->actingAs(Member::create(['id' => 'alice', 'team_id' => 'owners']));
}

function pushCard(object $test, string $mutation, int $sequence, string $kind, string $id, int $base, array $operations): TestResponse
{
    return $test->postJson('/sync/push', [
        'type' => 'cards', 'scope' => 'owners', 'mutation_id' => $mutation, 'id' => $id, 'replica' => 'device',
        'sequence' => $sequence, 'kind' => $kind, 'base_version' => $base, 'operations' => $operations,
    ]);
}

class AllowCards
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(Member $user, Card $card): bool
    {
        // owner_id is not synced: the rule reads the real row.
        return $card->getAttribute('title') !== 'hidden'
            && in_array($card->getAttribute('owner_id'), [null, $user->getAuthIdentifier()], true);
    }

    public function create(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

/**
 * A device's values are put in the model's own form before they are logged, so
 * devices and REST agree. They used to be logged as sent: "4" for an integer,
 * 12.5 for a decimal, and a time with an offset that the table then dropped.
 */
it('logs a device\'s values in the same form a save on the server would', function () {
    cardsOverApi($this);
    $response = pushCard($this, 'm1', 1, 'create', 'h', 0, [
        ['field' => 'title', 'op' => 'set', 'value' => 'x'],
        ['field' => 'done', 'op' => 'set', 'value' => 1],
        ['field' => 'priority', 'op' => 'set', 'value' => '4'],
        ['field' => 'amount', 'op' => 'set', 'value' => 12.5],
        ['field' => 'due_at', 'op' => 'set', 'value' => '2026-09-23T10:00:00+02:00'],
    ])->assertOk();
    $id = $response->json('id');

    expect(logged($id)?->value('done')->value())->toBeTrue()
        ->and(logged($id)?->value('priority')->value())->toBe(4)
        ->and(logged($id)?->value('amount')->value())->toBe('12.50')
        ->and(logged($id)?->value('due_at')->value())->toBe('2026-09-23 08:00:00')
        ->and(Card::find($id)?->getRawOriginal('due_at'))->toStartWith('2026-09-23 08:00:00');
});

/** One bad enum value used to commit, then break every later bootstrap in the tenant. */
it('refuses a value the model cannot hold, before anything is stored', function () {
    cardsOverApi($this);

    pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'kind', 'op' => 'set', 'value' => 'bogus']])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_field_value');

    $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk();
});

/**
 * A value only the table itself refuses - here NULL in a NOT NULL column - used
 * to come back as a 500, which a device retries forever. It is final, and
 * nothing of it is kept.
 */
it('refuses a write the table cannot hold as final, and keeps nothing of it', function () {
    cardsOverApi($this);

    pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'status', 'op' => 'set', 'value' => null]])
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_field_value')
        ->assertDontSee('SQLSTATE');

    expect(Card::query()->count())->toBe(0)
        ->and(app(Store::class)->receipt('m1'))->toBeNull();
    pushCard($this, 'm2', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'fine']])->assertOk();
});

/**
 * The read-back after a device's write only compared the fields the device
 * sent, so a column the database defaulted on a device's create never reached
 * the log, and other devices never learned it.
 */
it('logs a column the database defaulted on a device\'s create', function () {
    cardsOverApi($this);

    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'x']])->assertOk()->json('id');

    expect(logged($id)?->value('status')->value())->toBe('open')
        ->and(logged($id)?->value('counter')->value())->toBe(0)
        ->and(logged($id)?->value('done')->value())->toBeFalse()
        ->and(array_key_exists('priority', logged($id)->fields ?? []))->toBeFalse();
});

/** An increment racing another used to log this instance's +1, not the column's +2. */
it('logs what the column holds after a racing increment', function () {
    $first = card('r1', ['title' => 'x']);
    $second = Card::find('r1');
    $first->increment('counter');
    $second?->increment('counter');

    expect(Card::find('r1')?->counter)->toBe(2)
        ->and(logged('r1')?->value('counter')->value())->toBe(2);
});

/** What the application's own observers make of a device's value reaches the devices too. */
it('logs what an observer made of a device\'s write', function () {
    cardsOverApi($this);
    Card::saving(function (Card $card): void {
        $card->setAttribute('title', trim((string) $card->getAttribute('title')));
    });

    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => '  hello ']])->assertOk()->json('id');

    expect(Card::find($id)?->title)->toBe('hello')
        ->and(logged($id)?->value('title')->value())->toBe('hello');
});

/** A global scope hides rows from the application, not from sync. */
it('writes a row the application\'s global scope hides', function () {
    cardsOverApi($this);
    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'archived']])->assertOk()->json('id');
    Card::addGlobalScope('live', fn ($query) => $query->where('title', '!=', 'archived'));

    // Version 2 is the server logging the status the table defaulted.
    pushCard($this, 'm2', 2, 'update', $id, 2, [['field' => 'status', 'op' => 'set', 'value' => 'done']])->assertOk()->assertJsonPath('status', 'applied');
    pushCard($this, 'm3', 3, 'delete', $id, 3, [])->assertOk();

    expect(Card::query()->withoutGlobalScopes()->whereKey($id)->exists())->toBeFalse();
});

/** The view rule decides which rows a device gets. */
it('keeps a row the view rule hides out of bootstrap', function () {
    cardsOverApi($this);
    pushCard($this, 'm1', 1, 'create', 'a', 0, [['field' => 'title', 'op' => 'set', 'value' => 'shown']])->assertOk();
    pushCard($this, 'm2', 2, 'create', 'b', 0, [['field' => 'title', 'op' => 'set', 'value' => 'hidden']])->assertOk();

    $records = $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk()->json('records');

    expect(array_map(fn (array $r) => $r['fields']['title']['value'], $records))->toBe(['shown']);
});

/** A request's precondition is checked once; its own later saves of the record are not a race. */
it('checks If-Match once per request, not on every save of the record', function () {
    Route::middleware(SubstituteBindings::class)->put('/api/cards/{card}/twice', function (Card $card) {
        $card->update(['title' => 'new']);
        $card->update(['status' => 'done']);

        return response()->json(['ok' => true]);
    });
    card('t2', ['title' => 'orig']);

    $this->putJson('/api/cards/t2/twice', [], ['If-Match' => '"1"'])->assertOk();

    expect(Card::find('t2')?->status)->toBe('done');
});

/**
 * The row is gone by the time the delta judges it, so a rule reading a column
 * devices never see met a model of nulls and hid the delete itself - the
 * owner's other devices kept the row for ever.
 */
it('sends a delete even when the view rule reads a column devices never see', function () {
    cardsOverApi($this);
    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'mine']])->assertOk()->json('id');
    Card::withoutSyncing(fn () => Card::query()->whereKey($id)->update(['owner_id' => 'alice']));
    $cursor = $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk()->json('cursor');

    Card::find($id)?->delete();

    $kinds = [];
    foreach ($this->postJson('/sync/delta', ['type' => 'cards', 'scope' => 'owners', 'cursor' => $cursor])->assertOk()->json('commits') as $commit) {
        foreach ($commit['changes'] as $change) {
            $kinds[] = $change['kind'];
        }
    }
    expect($kinds)->toContain('deleted');
});

/** Suspending the whole class during a sync write hid an observer's write to another row of it. */
it('logs what an observer writes to another row of the same model during a sync write', function () {
    cardsOverApi($this);
    card('summary', ['title' => 'none yet']);
    Card::saved(function (Card $card): void {
        if ($card->getKey() !== 'summary') {
            Card::find('summary')?->update(['title' => 'last: '.$card->getAttribute('title')]);
        }
    });

    pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'news']])->assertOk();

    expect(Card::find('summary')?->title)->toBe('last: news')
        ->and(logged('summary')?->value('title')->value())->toBe('last: news');
});

/** "abc" became 0 on one driver and a server error on another; a server error made the device retry for ever. */
it('refuses a number that is not one', function (string $field, mixed $value) {
    cardsOverApi($this);

    pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => $field, 'op' => 'set', 'value' => $value]])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_field_value');
})->with([['priority', 'abc'], ['amount', '1e400'], ['amount', 'twelve']]);

/**
 * What the table makes of a device's write - a defaulted column - is logged one
 * version later. The device's next edit, queued offline behind its create or
 * based on the version its answer gave, used to conflict with that echo of its
 * own write and wait in a conflict group.
 */
it('counts the table\'s echo of a device\'s write as that device\'s own', function () {
    cardsOverApi($this);
    $created = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'x']])->assertOk();
    $id = $created->json('id');

    $this->postJson('/sync/push', [
        'type' => 'cards', 'scope' => 'owners', 'mutation_id' => 'm2', 'id' => $id, 'replica' => 'device',
        'sequence' => 2, 'kind' => 'update', 'base_version' => 0, 'depends_on' => 'm1',
        'operations' => [['field' => 'status', 'op' => 'set', 'value' => 'done']],
    ])->assertOk()->assertJsonPath('status', 'applied');

    expect($created->json('record_version'))->toBe(2)
        ->and(Card::find($id)?->status)->toBe('done');
});

/** An unset field is NULL in the table and absent in the log - the same thing, not an echo. */
it('does not echo a field the device unset', function () {
    cardsOverApi($this);
    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'x']])->assertOk()->json('id');
    $unset = pushCard($this, 'm2', 2, 'update', $id, 2, [['field' => 'title', 'op' => 'unset']])->assertOk();

    pushCard($this, 'm3', 3, 'update', $id, $unset->json('record_version'), [['field' => 'title', 'op' => 'set', 'value' => 'y']])
        ->assertOk()->assertJsonPath('status', 'applied');
    expect($unset->json('record_version'))->toBe(3)
        ->and(logged($id)?->version->value)->toBe(4);
});

/** A row from before the model was synced is logged whole on its first save, not as the field that changed. */
it('logs a row that predates syncing whole on its first save', function () {
    Card::withoutSyncing(fn () => card('legacy', ['title' => 'old', 'status' => 'review', 'priority' => 2]));
    expect(logged('legacy'))->toBeNull();

    Card::find('legacy')?->update(['title' => 'new']);

    expect(logged('legacy')?->value('title')->value())->toBe('new')
        ->and(logged('legacy')?->value('status')->value())->toBe('review')
        ->and(logged('legacy')?->value('priority')->value())->toBe(2);
});

/** A delete an observer vetoes used to be answered "applied", with the tombstone committed and the row still there. */
it('keeps the record when the application vetoes a device\'s delete', function () {
    cardsOverApi($this);
    $id = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'keep me']])->assertOk()->json('id');
    Card::deleting(fn (): bool => false);

    pushCard($this, 'm2', 2, 'delete', $id, 2, [])->assertStatus(403)->assertJsonPath('error', 'forbidden');

    expect(Card::find($id))->not->toBeNull()
        ->and(logged($id)?->deleted)->toBeFalse();
});

/**
 * A private row created and deleted since a device's cursor was sent to it
 * with its content, because a row that is gone was judged visible. Now only
 * its id leaves.
 */
it('never sends the content of a row the device may not see, even once it is gone', function () {
    cardsOverApi($this);
    $cursor = $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk()->json('cursor');
    card('secret', ['title' => 'the secret plan', 'owner_id' => 'bob']);
    Card::find('secret')?->delete();

    $delta = $this->postJson('/sync/delta', ['type' => 'cards', 'scope' => 'owners', 'cursor' => $cursor])->assertOk();

    expect($delta->getContent())->not->toContain('the secret plan');
});

/** A row whose owner changed left the previous owner's devices only as a removal nobody sent. */
it('takes a row away from a device when it stops being theirs', function () {
    cardsOverApi($this);
    card('moving', ['title' => 'mine', 'owner_id' => 'alice']);
    $cursor = $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk()->json('cursor');

    $row = Card::find('moving') ?? throw new LogicException('expected the row');
    $row->forceFill(['owner_id' => 'bob', 'title' => 'bob now'])->save();

    $changes = [];
    foreach ($this->postJson('/sync/delta', ['type' => 'cards', 'scope' => 'owners', 'cursor' => $cursor])->assertOk()->json('commits') as $commit) {
        foreach ($commit['changes'] as $change) {
            $changes[] = [$change['id'] ?? $change['entity']['id'] ?? null, $change['kind'], isset($change['record'])];
        }
    }

    expect($changes)->toContain(['moving', 'removed_from_scope', false])
        ->and(json_encode($changes))->not->toContain('bob now');
});

/**
 * A mutator that reads another column met an empty model when a device's value
 * was put through it, and stored what it made of the null there - an ordinary
 * save of the same value would have seen the row.
 */
it('puts a device\'s value through the model as the row stands', function () {
    cardsOverApi($this);
    $model = new class extends Card
    {
        protected function title(): Attribute
        {
            return Attribute::make(set: fn (?string $value, array $attributes): string => ($attributes['status'] ?? 'none').': '.$value);
        }
    };
    config()->set('sync.api.types', [$model::class]);
    app()->forgetInstance(SyncableTypes::class);
    Gate::policy($model::class, AllowCards::class);
    card('m1', ['title' => 'old', 'status' => 'review']);

    pushCard($this, 'm1', 1, 'update', 'm1', 1, [['field' => 'title', 'op' => 'set', 'value' => 'new']])->assertOk();

    expect(Card::find('m1')?->title)->toBe('review: new')
        ->and(Card::find('m1')?->status)->toBe('review');
});

/**
 * A save that met its If-Match marked the request as done checking. When the
 * host's transaction rolled back and retried, the retry skipped the check and
 * overwrote a version another writer had committed in between.
 */
it('checks If-Match again when the transaction that met it rolls back', function () {
    card('p1', ['title' => 'v1']);
    $attempts = 0;
    Route::middleware(SubstituteBindings::class)->put('/api/cards/{card}/retried', function (Card $card) use (&$attempts) {
        DB::transaction(function () use ($card, &$attempts): void {
            $attempts++;
            if ($attempts === 2) {
                // Someone else's write lands between the attempts.
                app(Engine::class)->recordTrusted(new EntityKey('cards:owners', 'cards', 'p1'), new Replica('server'), [FieldOperation::set('title', 'theirs')], false);
            }
            $card->title = 'mine '.$attempts;
            $card->save();
            if ($attempts === 1) {
                $deadlock = new PDOException('Deadlock found when trying to get lock');
                $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new QueryException('sync-testing', 'update cards', [], $deadlock);
            }
        }, 2);

        return response()->json(['ok' => true]);
    });

    $this->putJson('/api/cards/p1/retried', [], ['If-Match' => '"1"'])->assertStatus(412);
    expect($attempts)->toBe(2);
});

/**
 * With ServerWins the engine keeps the stored value and answers noop - and the
 * save that lost had already written its own value to the table, answered 200,
 * and left table and log disagreeing for good.
 */
it('answers a stale save the resolver keeps the server\'s value for as a conflict', function () {
    app()->instance(ConflictResolver::class, new ServerWins);
    app()->forgetInstance(Engine::class);
    app()->forgetInstance(SyncRecorder::class);
    Route::middleware(SubstituteBindings::class)->put('/api/cards/{card}/stale', function (Card $card) {
        $card->update(request()->only(['title']));

        return response()->json(['ok' => true]);
    });
    $card = card('sw', ['title' => 'first']);
    $card->update(['title' => 'theirs']);

    $this->putJson('/api/cards/sw/stale', ['title' => 'mine', 'base_version' => 1])->assertStatus(409);

    expect(Card::find('sw')?->title)->toBe('theirs')
        ->and(logged('sw')?->value('title')->value())->toBe('theirs');
});

/**
 * A unique race in an observer's write to ANOTHER table is not the device's
 * value; answering it 422 told the device to drop a valid edit for good.
 */
it('does not call an observer\'s own constraint failure the device\'s bad value', function () {
    cardsOverApi($this);
    Schema::create('card_audit', function (Blueprint $table) {
        $table->string('id')->primary();
    });
    DB::table('card_audit')->insert(['id' => 'taken']);
    Card::saved(fn () => DB::table('card_audit')->insert(['id' => 'taken']));

    $response = pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'fine']]);
    Schema::dropIfExists('card_audit');

    expect($response->status())->toBe(500)
        ->and(Card::query()->count())->toBe(0);
});

/** A device's push is the package's own transaction, and on MySQL it runs at READ COMMITTED like the store's. */
it('runs a device push at read committed on MySQL', function () {
    if (config('database.connections.sync-testing.driver') !== 'mysql') {
        $this->markTestSkipped('The isolation level is MySQL\'s.');
    }
    cardsOverApi($this);
    $seen = null;
    Card::saving(function () use (&$seen): void {
        // The running transaction's level; the session variable shows the default.
        $seen = DB::connection('sync-testing')->selectOne('SELECT trx_isolation_level AS level FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()')->level;
    });

    pushCard($this, 'm1', 1, 'create', 'h', 0, [['field' => 'title', 'op' => 'set', 'value' => 'x']])->assertOk();

    expect($seen)->toBe('READ COMMITTED');
});

/**
 * The binding wrapper dropped the view's current-state nature, so for exactly
 * the principals whose permissions are expected to change, a delete and a row
 * moving to another owner reached no device at all.
 */
it('still takes deleted and moved rows away from a principal with a binding', function () {
    cardsOverApi($this);
    app()->bind(SyncPrincipals::class, fn () => new class implements SyncPrincipals
    {
        public function resolve(Request $request): ?SyncPrincipal
        {
            return new SyncPrincipal('alice', 'alice:perm-v1');
        }
    });
    card('gone', ['title' => 'x', 'owner_id' => 'alice']);
    card('moving', ['title' => 'y', 'owner_id' => 'alice']);
    $cursor = $this->postJson('/sync/bootstrap', ['type' => 'cards', 'scope' => 'owners', 'page_size' => 10])->assertOk()->json('cursor');

    Card::find('gone')?->delete();
    Card::find('moving')?->forceFill(['owner_id' => 'bob', 'title' => 'bob now'])->save();

    $kinds = [];
    foreach ($this->postJson('/sync/delta', ['type' => 'cards', 'scope' => 'owners', 'cursor' => $cursor])->assertOk()->json('commits') as $commit) {
        foreach ($commit['changes'] as $change) {
            $kinds[] = [$change['id'] ?? null, $change['kind']];
        }
    }

    expect($kinds)->toContain(['gone', 'deleted'])
        ->and($kinds)->toContain(['moving', 'removed_from_scope']);
});

/**
 * Under Octane the request lives in a per-request container. The listeners
 * checked the application's for the recorder, found nothing, and a
 * precondition met in a transaction that rolled back stayed met.
 */
it('forgets a precondition met in a rolled-back transaction, whatever container holds the request', function () {
    $request = Request::create('/api/cards/x', 'PUT');
    $request->attributes->set('sync.precondition_held.'.Card::class.':x', ['sync-testing', 1]);
    $application = app();
    $sandbox = clone $application;
    $sandbox->instance('request', $request);
    Container::setInstance($sandbox);

    try {
        DB::connection('sync-testing')->beginTransaction();
        DB::connection('sync-testing')->rollBack();
    } finally {
        Container::setInstance($application);
    }

    expect($request->attributes->has('sync.precondition_held.'.Card::class.':x'))->toBeFalse();
});
