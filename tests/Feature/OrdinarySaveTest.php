<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Laravel\Tests\Fixtures\Note;
use Cbox\Sync\ValueObjects\EntityKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
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
