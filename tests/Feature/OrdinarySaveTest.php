<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Laravel\Exceptions\SyncRejected;
use Cbox\Sync\Laravel\SyncRecorder;
use Cbox\Sync\Laravel\Tests\Fixtures\Note;
use Cbox\Sync\ValueObjects\EntityKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // A shared database outlives the test: without this the second test to
    // run on MySQL or PostgreSQL finds the first one's table.
    Schema::dropIfExists('notes');
    Schema::create('notes', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('team_id');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });
    config()->set('sync.api.types', [Note::class]);
});

afterEach(function () {
    Schema::dropIfExists('notes');
});

function storedNote(string $id): ?EntityRecord
{
    return app(Store::class)->record(new EntityKey('notes:owners', 'notes', $id));
}

function makeNote(string $id, array $attributes = []): Note
{
    $note = new Note;
    $note->forceFill(['id' => $id, 'team_id' => 'owners'] + $attributes);
    $note->save();

    return $note;
}

/**
 * An edit made anywhere in the application has to reach the devices, and the
 * only way it can is by being in the log.
 */
it('records an ordinary create in the log', function () {
    makeNote('n1', ['title' => 'Written by an admin screen', 'status' => 'open']);

    $record = storedNote('n1');

    expect($record)->not->toBeNull();
    expect($record->value('title')->value())->toBe('Written by an admin screen');
    expect($record->value('status')->value())->toBe('open');
});

it('records an ordinary update, carrying only what changed', function () {
    $note = makeNote('n2', ['title' => 'Before', 'status' => 'open']);

    $note->title = 'After';
    $note->save();

    $record = storedNote('n2');
    expect($record->value('title')->value())->toBe('After');
    expect($record->version->value)->toBe(2);
});

it('records an ordinary delete as a tombstone', function () {
    makeNote('n3', ['title' => 'Doomed'])->delete();

    expect(storedNote('n3')?->deleted)->toBeTrue();
});

/** Columns the model has but does not sync stay the application's own business. */
it('ignores a change to a field that does not sync', function () {
    $note = makeNote('n4', ['title' => 'Kept']);
    $before = storedNote('n4')->version->value;

    $note->forceFill(['team_id' => 'owners'])->save();

    expect(storedNote('n4')->version->value)->toBe($before);
});

/**
 * Sync writes the settled value back to this table. That write is the answer to
 * a mutation, not a new one, and reading it as an edit would loop for ever.
 */
it('does not record sync\'s own write back to the table', function () {
    makeNote('n5', ['title' => 'First']);
    $before = storedNote('n5')->version->value;

    Note::withoutSyncing(function () {
        $note = Note::find('n5');
        $note->title = 'Written by sync itself';
        $note->save();
    });

    expect(storedNote('n5')->version->value)->toBe($before);
});

/**
 * A refusal from the host's own validator used to return quietly, leaving the
 * table with a value the log had refused - which every device would then
 * contradict.
 */
it('refuses an ordinary save the validator refuses, and leaves the row alone', function () {
    $note = makeNote('n6', ['title' => 'Fine']);
    app()->instance(EntityValidator::class, new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return $context->proposed->value('title')->value() === 'Forbidden'
                ? new ValidationResult([new ValidationFailure('no', 'No', 'title')])
                : new ValidationResult;
        }
    });
    foreach ([Engine::class, SyncRecorder::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $note->title = 'Forbidden';
    expect(fn () => $note->save())->toThrow(SyncRejected::class);

    expect(Note::find('n6')->title)->toBe('Fine')
        ->and(storedNote('n6')->value('title')->value())->toBe('Fine');
});

/**
 * A reused instance keeps wasRecentlyCreated and the previous save's changes.
 * Either one used to push stale values over newer ones.
 */
it('records only what this save changed, on an instance that has been saved before', function () {
    $note = makeNote('n7', ['title' => 'Mine', 'status' => 'open']);
    // Someone else moves the status on, through another instance.
    tap(Note::find('n7'), fn (Note $other) => $other->update(['status' => 'done']));

    $note->title = 'Mine, edited';
    $note->save();

    expect(storedNote('n7')->value('status')->value())->toBe('done')
        ->and(storedNote('n7')->value('title')->value())->toBe('Mine, edited');
});

/** The tenant is part of the key in the log; moving it left the old tenant holding a live copy. */
it('refuses to move a synced record to another tenant', function () {
    $note = makeNote('n8', ['title' => 'Stays']);

    $note->team_id = 'someone-else';
    expect(fn () => $note->save())->toThrow(LogicException::class, 'cannot move between tenants');

    expect(Note::find('n8')->team_id)->toBe('owners');
});
