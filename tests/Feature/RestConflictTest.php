<?php

declare(strict_types=1);

use Cbox\Sync\Laravel\Tests\Fixtures\Note;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
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

    // An ordinary REST controller. No sync in sight.
    Route::middleware(SubstituteBindings::class)->patch('/api/notes/{note}', function (Note $note) {
        $note->update(request()->only(['title', 'status']));

        return response()->json(['id' => $note->id, 'title' => $note->title]);
    });
});

afterEach(function () {
    Schema::dropIfExists('notes');
});

function seedNote(string $id, string $title = 'Original'): Note
{
    $note = new Note;
    $note->forceFill(['id' => $id, 'team_id' => 'owners', 'title' => $title, 'status' => 'open']);
    $note->save();

    return $note;
}

/** A client that knows nothing about sync gets exactly what it always got. */
it('applies a plain patch with no sync metadata', function () {
    seedNote('n1');

    $this->patchJson('/api/notes/n1', ['title' => 'Updated'])
        ->assertOk()
        ->assertJson(['title' => 'Updated']);

    expect(Note::find('n1')->title)->toBe('Updated');
});

/** Sending the version you were looking at is what buys conflict detection. */
it('detects a conflict when the caller names a version that has moved', function () {
    seedNote('n2');
    // Someone else edits the same field in between.
    tap(Note::find('n2'), fn (Note $note) => $note->update(['title' => 'Theirs']));

    $response = $this->patchJson('/api/notes/n2', ['title' => 'Mine', 'base_version' => 1]);

    $response->assertStatus(409);
    expect($response->json('conflicts.0.field'))->toBe('title');
    expect($response->json('conflicts.0.current'))->toBe('Theirs');
    expect($response->json('conflicts.0.proposed'))->toBe('Mine');
});

it('applies a versioned patch when nothing moved underneath it', function () {
    seedNote('n3');

    $this->patchJson('/api/notes/n3', ['title' => 'Mine', 'base_version' => 1])->assertOk();

    expect(Note::find('n3')->title)->toBe('Mine');
});

/** The HTTP-native spelling of the same thing. */
it('accepts If-Match as the version', function () {
    seedNote('n4');
    tap(Note::find('n4'), fn (Note $note) => $note->update(['title' => 'Theirs']));

    $this->patchJson('/api/notes/n4', ['title' => 'Mine'], ['If-Match' => '"1"'])
        ->assertStatus(409);
});

it('leaves two writers to different fields alone', function () {
    seedNote('n5');
    tap(Note::find('n5'), fn (Note $note) => $note->update(['status' => 'done']));

    $this->patchJson('/api/notes/n5', ['title' => 'Mine', 'base_version' => 1])->assertOk();

    $note = Note::find('n5');
    expect($note->title)->toBe('Mine');
    expect($note->status)->toBe('done');
});

/**
 * The log refuses BEFORE the row is written. Recording after the write left
 * the losing value in the table and the winning one in the log - measured:
 * a 409 with the table holding "Mine".
 */
it('leaves the table holding the winning value when it answers 409', function () {
    seedNote('n6');
    tap(Note::find('n6'), fn (Note $note) => $note->update(['title' => 'Theirs']));

    $this->patchJson('/api/notes/n6', ['title' => 'Mine', 'base_version' => 1])->assertStatus(409);

    expect(Note::find('n6')->title)->toBe('Theirs');
});
