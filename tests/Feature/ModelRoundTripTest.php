<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\GuardPrincipals;
use Cbox\Sync\Laravel\Exceptions\SyncRejected;
use Cbox\Sync\Laravel\IlluminateStore;
use Cbox\Sync\Laravel\Syncable;
use Cbox\Sync\Laravel\SyncRecorder;
use Cbox\Sync\Laravel\Tests\Fixtures\Member;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
 * A server write that loses a race with a device used to preserve a conflict
 * on its first attempt and then apply on the retry, leaving a group nobody
 * would ever be asked to resolve.
 */
it('leaves no conflict group behind when a server write races a device', function () {
    card('g1', ['title' => 'first']);
    $stale = new class(app('db')->connection()) extends IlluminateStore
    {
        public ?EntityRecord $before = null;

        public function record(EntityKey $entity): ?EntityRecord
        {
            // As read by a writer that looked just before a device wrote.
            if ($this->before !== null) {
                $record = $this->before;
                $this->before = null;

                return $record;
            }

            return parent::record($entity);
        }
    };
    $stale->before = logged('g1');
    // The device's write lands after that read.
    app(Engine::class)->process(new Mutation('device', new EntityKey('cards:owners', 'cards', 'g1'), new Replica('device'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [FieldOperation::set('title', 'device')]));
    app()->instance(SyncRecorder::class, new SyncRecorder($stale, app(Engine::class), app(Factory::class)));

    $card = Card::find('g1');
    $card->title = 'admin';
    $card->save();

    expect(logged('g1')?->value('title')->value())->toBe('admin')
        ->and(app(Store::class)->openGroups(new EntityKey('cards:owners', 'cards', 'g1')))->toBe([]);
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
        return $card->getAttribute('title') !== 'hidden';
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

    pushCard($this, 'm2', 2, 'update', $id, 1, [['field' => 'status', 'op' => 'set', 'value' => 'done']])->assertOk()->assertJsonPath('status', 'applied');
    pushCard($this, 'm3', 3, 'delete', $id, 2, [])->assertOk();

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
