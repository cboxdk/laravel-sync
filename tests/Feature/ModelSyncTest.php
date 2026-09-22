<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Laravel\Api\ApiServiceProvider;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\GuardPrincipals;
use Cbox\Sync\Laravel\Api\ModelSyncableType;
use Cbox\Sync\Laravel\Api\Support\IdentityBinding;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Laravel\Syncable;
use Cbox\Sync\Laravel\Tests\Fixtures\Member;
use Cbox\Sync\Laravel\Tests\Fixtures\Note;
use Cbox\Sync\Laravel\Tests\Fixtures\NotePolicy;
use Cbox\Sync\Laravel\Tests\Fixtures\SecondNote;
use Cbox\Sync\ValueObjects\EntityKey;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // A shared database outlives the test: without this the second test to
    // run on MySQL or PostgreSQL finds the first one's table.
    Schema::dropIfExists('notes');
    Schema::dropIfExists('members');
    Schema::create('notes', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->string('status')->nullable();
        $table->string('locked_by')->nullable();
        $table->timestamps();
    });
    Schema::create('members', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
    });

    config()->set('sync.api.types', [Note::class]);
    // The default principal resolver, so the Gate and the protocol identity are
    // the same user - which is the whole point of binding to the policy.
    app()->bind(
        SyncPrincipals::class,
        GuardPrincipals::class,
    );
    Gate::policy(Note::class, NotePolicy::class);
    app()->forgetInstance(SyncableTypes::class);
});

afterEach(function () {
    Schema::dropIfExists('notes');
    Schema::dropIfExists('members');
});

function member(string $id, string $team): Member
{
    return Member::create(['id' => $id, 'team_id' => $team]);
}

/**
 * The name the server gives a record created by this mutation. A create only
 * ever carries a handle the device made up for itself.
 */
function noteId(string $principal, string $mutationId = 'm1'): string
{
    return IdentityBinding::entityId(new SyncPrincipal($principal, $principal), $mutationId);
}

function pushNote(array $overrides = []): TestResponse
{
    return test()->postJson('/sync/push', array_merge([
        'type' => 'notes',
        'scope' => 'owners',
        'mutation_id' => 'm1',
        'id' => 'n1',
        'replica' => 'device',
        'sequence' => 1,
        'kind' => 'create',
        'base_version' => 0,
        'operations' => [
            ['field' => 'title', 'op' => 'set', 'value' => 'First note'],
            ['field' => 'status', 'op' => 'set', 'value' => 'open'],
        ],
    ], $overrides));
}

/** The whole registration is `use Syncable` on the model and listing the class. */
it('serves a model with nothing declared but the trait', function () {
    $this->actingAs(member('alice', 'owners'));

    $response = pushNote();

    $response->assertOk();
    expect($response->json('status'))->toBe('applied');
});

it('derives the entity type, fields and tenant from the model', function () {
    $note = new Note;

    expect($note->syncEntityType())->toBe('notes');
    expect($note->syncFields())->toBe(['title', 'body', 'status']);
    expect($note->syncScopeColumn())->toBe('team_id');
    // The key and the timestamps are never a client's to set.
    expect($note->syncReadOnly())->toBe([]);
});

/** The application's own policy is the authorization, not a second declaration. */
it('refuses a write the model policy refuses', function () {
    $this->actingAs(member('bob', 'readers'));

    pushNote()->assertForbidden();
});

it('refuses a tenant the principal does not belong to', function () {
    $this->actingAs(member('carol', 'owners'));

    pushNote(['scope' => 'someone-else'])->assertForbidden();
});

it('refuses a read for a principal the policy will not show anything to', function () {
    $this->actingAs(member('dave', 'outsiders'));

    $this->postJson('/sync/bootstrap', ['type' => 'notes', 'scope' => 'outsiders'])
        ->assertForbidden();
});

/** Sync owns ordering and conflicts; the table is where the rest of the app reads. */
it('writes the settled value into the application table', function () {
    $this->actingAs(member('erin', 'owners'));

    pushNote()->assertOk();

    $note = Note::find(noteId('erin'));
    expect($note)->not->toBeNull();
    expect($note->title)->toBe('First note');
    expect($note->status)->toBe('open');
    expect($note->team_id)->toBe('owners');
});

it('removes the row when the record becomes a tombstone', function () {
    $this->actingAs(member('frank', 'owners'));
    pushNote()->assertOk();

    $this->postJson('/sync/push', [
        'type' => 'notes', 'scope' => 'owners', 'mutation_id' => 'm2', 'id' => noteId('frank'),
        'replica' => 'device', 'sequence' => 2, 'kind' => 'delete', 'base_version' => 1,
    ])->assertOk();

    expect(Note::find(noteId('frank')))->toBeNull();
});

/** A row the engine refused must not be in the table either. */
it('leaves the table untouched when the mutation is refused', function () {
    $this->actingAs(member('gina', 'readers'));

    pushNote()->assertForbidden();

    expect(Note::find(noteId('gina')))->toBeNull();
});

/**
 * Offline creation is the whole point, and a device cannot create a row whose
 * id the database has not handed out yet. Saying so beats a NOT NULL error.
 */
it('refuses a model whose key the database hands out', function () {
    $model = new class extends Model
    {
        use Syncable;

        protected $table = 'notes';

        protected $fillable = ['title'];
    };

    expect(fn () => new ModelSyncableType(
        $model::class,
        app(Illuminate\Contracts\Auth\Access\Gate::class),
        app(Factory::class),
    ))->toThrow(LogicException::class, 'auto-incrementing key');
});

/**
 * Two registrations for one entity type means one is unreachable, and which one
 * depends on array order. Two models sharing a table is the usual way in.
 */
it('refuses two syncable types claiming the same entity type', function () {
    config()->set('sync.api.types', [Note::class, SecondNote::class]);
    app()->forgetInstance(SyncableTypes::class);
    (new ApiServiceProvider(app()))->register();

    // Raised when the registry is first resolved rather than at boot, so it
    // surfaces on the first sync request. Loud either way, which is the point:
    // silently serving one of the two and never the other is what it did before.
    expect(fn () => app(SyncableTypes::class)->registered())
        ->toThrow(RuntimeException::class, 'unreachable');
});

/**
 * A policy reads more than the synced fields. Built from synced fields alone,
 * the model had locked_by as null, and the policy let through an edit the real
 * row forbids.
 */
it('asks the policy about the real row, not only what sync can see', function () {
    $this->actingAs(member('alice', 'owners'));
    pushNote()->assertOk();
    Note::withoutSyncing(fn () => Note::query()->whereKey(noteId('alice'))->update(['locked_by' => 'bob']));

    pushNote([
        'mutation_id' => 'm2', 'id' => noteId('alice'), 'sequence' => 2, 'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'sneaky']],
    ])->assertForbidden();

    expect(Note::find(noteId('alice'))->title)->toBe('First note');
});

/**
 * A lost response, then the field stops being writable. The retry used to be
 * refused, the device could not advance, and every later write was rejected
 * for reusing a sequence.
 */
it('answers a replay from its receipt even after the caller lost permission to make it again', function () {
    $this->actingAs(member('alice', 'owners'));
    $first = pushNote()->assertOk()->json();

    Gate::policy(Note::class, get_class(new class
    {
        public function viewAny(): bool
        {
            return true;
        }

        public function create(): bool
        {
            return false;
        }
    }));

    expect(pushNote()->assertOk()->json())->toBe($first);
});

it('refuses a replayed id carrying a different position in the stream', function () {
    $this->actingAs(member('alice', 'owners'));
    pushNote()->assertOk();

    pushNote(['sequence' => 7])->assertStatus(409)->assertJsonPath('error', 'protocol_violation');
});

/** A receipt replay used to write the row again, firing the application's saved observers twice. */
it('writes the table once per change, not once per request', function () {
    $this->actingAs(member('alice', 'owners'));
    $saves = 0;
    Note::saved(function () use (&$saves): void {
        $saves++;
    });

    pushNote()->assertOk();
    pushNote()->assertOk();

    expect($saves)->toBe(1);
});

/** A column has no "absent". Skipping an unset left the old value in the table while the log said none. */
it('writes NULL for a field the device unset', function () {
    $this->actingAs(member('alice', 'owners'));
    pushNote()->assertOk();

    pushNote([
        'mutation_id' => 'm2', 'id' => noteId('alice'), 'sequence' => 2, 'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'status', 'op' => 'unset']],
    ])->assertOk()->assertJsonPath('status', 'applied');

    expect(Note::find(noteId('alice'))->status)->toBeNull();
});

/** An observer that cancels the save used to leave the log saying "applied". */
it('rolls the write back when the application cancels saving the row', function () {
    $this->actingAs(member('alice', 'owners'));
    Note::saving(fn (): bool => false);

    // Final, not a 500 the device retries for ever with its queue behind it.
    pushNote()->assertStatus(403)->assertJsonPath('error', 'forbidden');

    expect(app(Store::class)->receipt(IdentityBinding::mutationId(new SyncPrincipal('alice', 'alice'), 'm1')))->toBeNull();
});

/** Hidden is the host saying a response must never show a column; syncing it would put it on every device. */
it('never syncs a field the model hides', function () {
    $model = new class extends Note
    {
        protected $hidden = ['body'];
    };

    expect($model->syncFields())->toBe(['title', 'status']);
});

/** Hidden was only honoured for fields derived from fillable; a model listing its sync fields synced a hidden one to every device. */
it('never syncs a hidden field, even when the model lists it', function () {
    $model = new class extends Note
    {
        protected array $syncFields = ['title', 'body', 'status'];

        protected $hidden = ['body'];
    };

    expect($model->syncFields())->toBe(['title', 'status']);
});

it('refuses a model whose listed sync fields are all hidden', function () {
    $model = new class extends Note
    {
        protected array $syncFields = ['body'];

        protected $hidden = ['body'];
    };

    expect(fn () => $model->syncFields())->toThrow(LogicException::class, 'nothing to sync');
});

it('refuses to guess the fields of a model that declares none', function () {
    $model = new class extends Note
    {
        protected $fillable = [];
    };

    expect(fn () => $model->syncFields())->toThrow(LogicException::class, 'declares nothing to sync');
});

/** A row on another connection keeps its write when the log rolls back. */
it('refuses a model on a different connection from the sync store', function () {
    $this->actingAs(member('alice', 'owners'));
    config()->set('database.connections.elsewhere', config('database.connections.sync-testing'));
    config()->set('sync.api.types', [ElsewhereNote::class]);
    app()->forgetInstance(SyncableTypes::class);
    Gate::policy(ElsewhereNote::class, NotePolicy::class);

    $this->withoutExceptionHandling();
    expect(fn () => pushNote())->toThrow(LogicException::class, 'must share one');
});

class ElsewhereNote extends Note
{
    protected $connection = 'elsewhere';
}

class CastNote extends Note
{
    protected $casts = ['body' => 'array'];
}

/**
 * The log used to take the raw column - JSON text - and writing it back
 * through the cast encoded it again. One unrelated edit turned a document
 * into a string.
 */
it('round-trips a cast field through the log without re-encoding it', function () {
    $this->actingAs(member('alice', 'owners'));
    config()->set('sync.api.types', [CastNote::class]);
    app()->forgetInstance(SyncableTypes::class);
    Gate::policy(CastNote::class, NotePolicy::class);
    $note = new CastNote;
    $note->forceFill(['id' => 'c1', 'team_id' => 'owners', 'title' => 't', 'body' => ['a' => 1]])->save();

    $stored = app(Store::class)->record(new EntityKey('notes:owners', 'notes', 'c1'));
    // A JSON object, not the text of one.
    expect($stored?->value('body')->value())->toEqual((object) ['a' => 1]);

    pushNote([
        'mutation_id' => 'm2', 'id' => 'c1', 'sequence' => 1, 'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'edited']],
    ])->assertOk()->assertJsonPath('status', 'applied');

    expect(CastNote::find('c1')->body)->toBe(['a' => 1]);
});

/** A row this key names in another tenant is never handed to this one. */
it('refuses to write a row that belongs to another tenant', function () {
    $this->actingAs(member('alice', 'owners'));
    pushNote()->assertOk();
    Note::withoutSyncing(fn () => Note::query()->whereKey(noteId('alice'))->update(['team_id' => 'others']));

    $this->withoutExceptionHandling();
    expect(fn () => pushNote([
        'mutation_id' => 'm2', 'id' => noteId('alice'), 'sequence' => 2, 'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'mine now']],
    ]))->toThrow(LogicException::class);

    expect(Note::find(noteId('alice'))->team_id)->toBe('others');
});

/**
 * viewAny opens the type; view decides the rows. A row the policy hides used to
 * be synced to everyone in the tenant anyway.
 */
it('keeps a row the view policy hides off the device, and takes it away when it becomes hidden', function () {
    $this->actingAs(member('alice', 'owners'));
    pushNote()->assertOk();
    pushNote(['mutation_id' => 'm2', 'sequence' => 2, 'operations' => [
        ['field' => 'title', 'op' => 'set', 'value' => 'Secret'], ['field' => 'status', 'op' => 'set', 'value' => 'private'],
    ]])->assertOk();

    $page = $this->postJson('/sync/bootstrap', ['type' => 'notes', 'scope' => 'owners', 'page_size' => 10])->assertOk();
    expect(array_column($page->json('records'), 'id'))->toBe([noteId('alice')]);

    pushNote([
        'mutation_id' => 'm3', 'id' => noteId('alice'), 'sequence' => 3, 'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'status', 'op' => 'set', 'value' => 'private']],
    ])->assertOk();
    $delta = $this->postJson('/sync/delta', ['type' => 'notes', 'scope' => 'owners', 'cursor' => $page->json('cursor')])->assertOk();

    $kinds = [];
    foreach ($delta->json('commits') as $commit) {
        foreach ($commit['changes'] as $change) {
            $kinds[] = $change['kind'];
        }
    }
    expect($kinds)->toBe(['removed_from_scope']);
});
