<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Laravel\IlluminateStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\KeysetBootstrapSessions;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Support\Facades\DB;

it('resolves a durable store on the application connection', function () {
    expect($this->app->make(Store::class))->toBeInstanceOf(IlluminateStore::class);
    expect($this->app->make(Store::class))->toBe($this->app->make(Store::class));
    expect($this->app->make(Engine::class))->toBeInstanceOf(Engine::class);
});

it('defaults to stateless bootstrap tokens so any worker can serve a page', function () {
    $sessions = (new ReflectionClass(ViewSyncService::class))->getProperty('sessions');

    expect($sessions->getValue($this->syncViews()))->toBeInstanceOf(KeysetBootstrapSessions::class);
});

it('runs the migration on the configured connection', function () {
    foreach (['sync_spaces', 'sync_records', 'sync_fields', 'sync_conflict_groups', 'sync_receipts', 'sync_streams', 'sync_commits'] as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue();
    }
});

it('carries an entity from create through conflict, resolution, bootstrap and delta', function () {
    $entity = new EntityKey('tenant-1', 'notes', 'shared');

    expect($this->syncCreate($entity, [Op::set('project', 'alpha'), Op::set('title', 'draft')])->status)
        ->toBe(MutationStatus::Applied);

    // Two replicas edit the same field from the same base: the loser is kept.
    $this->syncUpdate($entity, [Op::set('title', 'from a')], base: 1, replica: 'a');
    $conflicted = $this->syncUpdate($entity, [Op::set('title', 'from b')], base: 1, replica: 'b');

    expect($conflicted->status)->toBe(MutationStatus::Conflict);
    $group = $this->syncStore()->openGroups($entity)[0] ?? throw new LogicException('Expected an open conflict');
    expect($group->candidates)->toHaveCount(2);

    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'notes');
    $views = $this->syncViews();
    $context = $views->context('tenant-1', $view);
    $page = $views->bootstrap($context, $view, $views->openBootstrap($context, $view, 10));

    expect($page->records)->toHaveCount(1);
    expect($page->records[0]->value('title')->value())->toBe('from a');
    $cursor = $page->cursor ?? throw new LogicException('Single page bootstrap expected');

    $this->syncUpdate($entity, [Op::set('title', 'after bootstrap')], replica: 'c');
    $delta = $views->delta($cursor, $view);

    expect($delta->commits)->not->toBeEmpty();
    expect($this->syncStore()->record($entity)?->value('title')->value())->toBe('after bootstrap');
    expect($this->syncStore()->acknowledged('tenant-1', new Replica('b')))->toBe(1);
});

it('rolls the whole mutation back when the surrounding transaction fails', function () {
    $entity = new EntityKey('tenant-1', 'notes', 'one');
    $this->syncCreate($entity, [Op::set('title', 'kept')]);
    $watermark = $this->syncStore()->watermark('tenant-1')->value;

    try {
        DB::transaction(function () use ($entity): void {
            $this->syncUpdate($entity, [Op::set('title', 'discarded')], replica: 'a');

            throw new RuntimeException('host decided to abort');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->syncStore()->record($entity)?->value('title')->value())->toBe('kept');
    expect($this->syncStore()->watermark('tenant-1')->value)->toBe($watermark);
    expect($this->syncStore()->receipt('a-one-1'))->toBeNull();
});

it('keeps spaces independent', function () {
    $one = new EntityKey('tenant-1', 'notes', 'x');
    $two = new EntityKey('tenant-2', 'notes', 'x');

    $this->syncCreate($one, [Op::set('title', 'first')], replica: 'r1');
    $this->syncCreate($two, [Op::set('title', 'second')], replica: 'r2');

    expect($this->syncStore()->watermark('tenant-1')->value)->toBe(1);
    expect($this->syncStore()->watermark('tenant-2')->value)->toBe(1);
    expect($this->syncStore()->record($one)?->value('title')->value())->toBe('first');
    expect($this->syncStore()->record($two)?->value('title')->value())->toBe('second');
});
