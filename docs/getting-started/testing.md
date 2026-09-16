---
title: "Testing"
weight: 20
description: "Drive the engine from your own tests with the shipped trait."
---

# Testing

`Cbox\Sync\Laravel\Testing\InteractsWithSync` drives the engine without making
you hand-build mutations. Everything resolves from the container, so if you have
swapped the conflict resolver or validator, your tests exercise your wiring
rather than the defaults.

```php
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Laravel\Testing\InteractsWithSync;
use Cbox\Sync\ValueObjects\EntityKey;

uses(InteractsWithSync::class);

it('keeps both proposals when two devices edit the same field', function () {
    $note = new EntityKey('tenant-1', 'notes', 'shared');

    $this->syncCreate($note, [Op::set('title', 'draft')]);
    $this->syncUpdate($note, [Op::set('title', 'from phone')], base: 1, replica: 'phone');
    $result = $this->syncUpdate($note, [Op::set('title', 'from laptop')], base: 1, replica: 'laptop');

    expect($result->status)->toBe(MutationStatus::Conflict);
    expect($this->syncStore()->openGroups($note)[0]->candidates)->toHaveCount(2);
});
```

`syncStore()`, `syncEngine()` and `syncViews()` return the container's own
instances, so you can assert against the durable state directly.

## Testing against a real database

The package's own suite runs on SQLite in memory by default and against
PostgreSQL and MySQL in CI, through `SYNC_DRIVER`, `SYNC_DATABASE`, `SYNC_HOST`,
`SYNC_PORT`, `SYNC_USERNAME` and `SYNC_PASSWORD`. A host application can do the
same with its own Testbench configuration.
