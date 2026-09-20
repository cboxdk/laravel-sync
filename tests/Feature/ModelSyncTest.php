<?php

declare(strict_types=1);

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
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Schema::create('notes', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->string('status')->nullable();
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
