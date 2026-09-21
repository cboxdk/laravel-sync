<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * The description is only useful if it is true.
 *
 * An OpenAPI file maintained by hand drifts from the code, and the drift is
 * invisible until someone generates a client from it and the client is wrong -
 * which, now that an agent may be the one generating it, is the whole risk.
 * These assert the shipped description against what the endpoints actually
 * answer.
 */
function spec(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/openapi.yaml');
}

/** @return list<string> */
function requiredOf(string $schema): array
{
    return spec()['components']['schemas'][$schema]['required'] ?? [];
}

/** @return list<string> */
function declaredOf(string $schema): array
{
    return array_keys(spec()['components']['schemas'][$schema]['properties'] ?? []);
}

function pushTask(object $test, array $overrides = []): TestResponse
{
    return $test->postJson('/sync/push', array_merge([
        'type' => 'tasks', 'scope' => 'team-1', 'mutation_id' => 'm1', 'id' => 'handle',
        'replica' => 'device-1', 'sequence' => 1, 'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'Ship it'], ['field' => 'status', 'op' => 'set', 'value' => 'open']],
    ], $overrides), ['X-Test-Principal' => 'alice']);
}

it('is a well-formed description with no dangling references', function () {
    $spec = spec();

    expect($spec['openapi'])->toStartWith('3.');
    expect(array_keys($spec['paths']))->toBe(['/push', '/bootstrap', '/delta']);

    $refs = [];
    array_walk_recursive($spec, function ($value, $key) use (&$refs): void {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;
        }
    });

    $dangling = [];
    foreach (array_unique($refs) as $ref) {
        if (! preg_match('#^\#/components/(\w+)/(\w+)$#', $ref, $parts)) {
            $dangling[] = $ref;

            continue;
        }
        if (! isset($spec['components'][$parts[1]][$parts[2]])) {
            $dangling[] = $ref;
        }
    }

    expect($dangling)->toBe([]);
});

it('describes every field a push answer carries', function () {
    $body = pushTask($this)->assertOk()->json();

    expect(array_diff(requiredOf('PushResponse'), array_keys($body)))->toBe([]);

    // And the reverse: nothing is answered that the description never mentions.
    expect(array_diff(array_keys($body), declaredOf('PushResponse')))->toBe([]);
});

it('describes every field a bootstrap answer carries', function () {
    pushTask($this)->assertOk();

    $body = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice'])
        ->assertOk()->json();

    expect(array_diff(requiredOf('BootstrapResponse'), array_keys($body)))->toBe([]);
    expect(array_diff(array_keys($body), declaredOf('BootstrapResponse')))->toBe([]);
    expect(array_diff(array_keys($body['records'][0]), declaredOf('Record')))->toBe([]);
    expect(array_diff(array_keys($body['context']), declaredOf('Context')))->toBe([]);
});

it('describes every field a delta answer carries', function () {
    pushTask($this)->assertOk();
    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tasks', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice'])->assertOk();

    $body = $this->postJson('/sync/delta', [
        'type' => 'tasks', 'scope' => 'team-1',
        'cursor' => ['position' => 0, 'context' => $bootstrap->json('cursor.context')],
    ], ['X-Test-Principal' => 'alice'])->assertOk()->json();

    expect(array_diff(requiredOf('DeltaResponse'), array_keys($body)))->toBe([]);
    expect(array_diff(array_keys($body), declaredOf('DeltaResponse')))->toBe([]);
    expect(array_diff(array_keys($body['cursor']), declaredOf('Cursor')))->toBe([]);
});

it('describes every error code the package can answer with', function () {
    $documented = spec()['components']['schemas']['Error']['properties']['error']['enum'];

    // Every code the code can emit, read from the source rather than listed here.
    $source = implode("\n", array_map(file_get_contents(...), [
        dirname(__DIR__, 2).'/src/Api/Exceptions/SyncRequestRejected.php',
        dirname(__DIR__, 2).'/src/Api/Concerns/HandlesSyncRequests.php',
        dirname(__DIR__, 2).'/src/Api/Support/FieldValueCodec.php',
        dirname(__DIR__, 2).'/src/Api/Support/MutationMapper.php',
    ]));

    $emitted = ['invalid_request', 'invalid_field_value', 'invalid_cursor', 'too_many_operations', 'field_not_writable', 'forbidden', 'unknown_type', 'protocol_violation', 'reset_required', 'retry'];

    // Still emitted by the source, and still described.
    expect(array_values(array_filter($emitted, fn (string $code): bool => ! str_contains($source, "'".$code."'"))))->toBe([]);
    expect(array_values(array_diff($emitted, $documented)))->toBe([]);
});

it('answers an error in the shape it describes', function () {
    $body = $this->postJson('/sync/bootstrap', ['type' => 'nope', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice'])->json();

    expect(array_diff(requiredOf('Error'), array_keys($body)))->toBe([]);
    expect(array_diff(array_keys($body), declaredOf('Error')))->toBe([]);
    expect(spec()['components']['schemas']['Error']['properties']['error']['enum'])->toContain($body['error']);
});

/**
 * The brief an agent is handed names statuses, error codes and endpoints. It is
 * prose, so nothing else would notice it going stale - and a stale brief is
 * worse than none: it is confidently wrong, and an agent has no way to tell.
 */
it('hands an agent only things that still exist', function () {
    $brief = file_get_contents(dirname(__DIR__, 2).'/docs/getting-started/for-an-agent.md');
    $spec = spec();

    $statuses = $spec['components']['schemas']['PushResponse']['properties']['status']['enum'];
    $errors = $spec['components']['schemas']['Error']['properties']['error']['enum'];

    // Every status the brief tells an agent to branch on.
    foreach (['applied', 'partial', 'noop', 'conflict', 'rejected', 'validation_failed', 'precondition_failed', 'mutation_gap'] as $status) {
        expect($brief)->toContain($status);
        expect($statuses)->toContain($status);
    }

    expect($brief)->toContain('reset_required');
    expect($errors)->toContain('reset_required');

    // Every endpoint it names is one the description actually serves.
    foreach (['/push', '/bootstrap', '/delta'] as $path) {
        expect($brief)->toContain($path);
        expect($spec['paths'])->toHaveKey($path);
    }

    // And the fields it tells an agent to send.
    foreach (['mutation_id', 'base_version', 'acknowledged_sequence', 'temp_id', 'next_token', 'has_more'] as $field) {
        expect($brief)->toContain($field);
    }
});

/** @return list<string> Declared properties of an inline nested object. */
function declaredNested(string $schema, string $property, bool $inItems = true): array
{
    $node = spec()['components']['schemas'][$schema]['properties'][$property];

    return array_keys(($inItems ? $node['items'] : $node)['properties'] ?? []);
}

function pushAs(object $test, string $principal, array $mutation): TestResponse
{
    return $test->postJson('/sync/push', ['type' => 'tasks', 'scope' => 'team-1'] + $mutation, ['X-Test-Principal' => $principal]);
}

function seedTaskFor(object $test): string
{
    return pushAs($test, 'alice', [
        'mutation_id' => 'seed', 'id' => 'handle', 'replica' => 'device-1', 'sequence' => 1,
        'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'original'], ['field' => 'status', 'op' => 'set', 'value' => 'open']],
    ])->assertOk()->json('id');
}

/**
 * A conflict is the answer this package exists to give, and the one a client has
 * the most work to do with. Describing it wrong is worse than not describing it.
 */
it('describes a conflict exactly as it answers one', function () {
    $id = seedTaskFor($this);

    pushAs($this, 'alice', [
        'mutation_id' => 'a2', 'id' => $id, 'replica' => 'device-1', 'sequence' => 2,
        'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'from alice']],
    ])->assertOk();

    $body = pushAs($this, 'bob', [
        'mutation_id' => 'b1', 'id' => $id, 'replica' => 'device-2', 'sequence' => 1,
        'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'from bob']],
    ])->assertOk()->json();

    expect($body['status'])->toBe('conflict');
    expect(array_diff(array_keys($body), declaredOf('PushResponse')))->toBe([]);
    expect(spec()['components']['schemas']['PushResponse']['properties']['status']['enum'])->toContain('conflict');

    // The two nested shapes a client has to read to resolve anything.
    expect($body['conflict_groups'])->not->toBeEmpty();
    expect(array_diff(array_keys($body['conflict_groups'][0]), declaredNested('PushResponse', 'conflict_groups')))->toBe([]);

    expect($body['conflicts'])->not->toBeEmpty();
    expect(array_diff(array_keys($body['conflicts'][0]), declaredNested('PushResponse', 'conflicts')))->toBe([]);
    expect(array_diff(array_keys($body['conflicts'][0]['current']), declaredOf('FieldValue')))->toBe([]);
});

/** A gap answers with far less than an applied push. The description allows that. */
it('describes a mutation gap exactly as it answers one', function () {
    $id = seedTaskFor($this);

    $body = pushAs($this, 'alice', [
        'mutation_id' => 'g1', 'id' => $id, 'replica' => 'device-9', 'sequence' => 5,
        'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'x']],
    ])->assertOk()->json();

    expect($body['status'])->toBe('mutation_gap');
    expect(array_diff(array_keys($body), declaredOf('PushResponse')))->toBe([]);

    // The field the brief tells an agent to resume from has to actually be here.
    expect($body)->toHaveKey('acknowledged_sequence');
    expect($body['acknowledged_sequence'])->toBeInt();
});

it('describes a precondition failure exactly as it answers one', function () {
    $id = seedTaskFor($this);

    pushAs($this, 'alice', [
        'mutation_id' => 'a2', 'id' => $id, 'replica' => 'device-1', 'sequence' => 2,
        'kind' => 'update', 'base_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'moved on']],
    ])->assertOk();

    $body = pushAs($this, 'alice', [
        'mutation_id' => 'p1', 'id' => $id, 'replica' => 'device-1', 'sequence' => 3,
        'kind' => 'update', 'base_version' => 1, 'expected_version' => 1,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'too late']],
    ])->assertOk()->json();

    expect($body['status'])->toBe('precondition_failed');
    expect(array_diff(array_keys($body), declaredOf('PushResponse')))->toBe([]);
    expect(array_diff(array_keys($body['precondition']), declaredNested('PushResponse', 'precondition', inItems: false)))->toBe([]);
    expect(array_diff(declaredNested('PushResponse', 'precondition', inItems: false), array_keys($body['precondition'])))->toBe([]);
});

it('describes a validation failure exactly as it answers one', function () {
    app()->bind(EntityValidator::class, fn (): EntityValidator => new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return new ValidationResult([new ValidationFailure('title_reserved', 'That title is reserved.', 'title')]);
        }
    });

    $body = pushAs($this, 'alice', [
        'mutation_id' => 'v1', 'id' => 'handle', 'replica' => 'device-1', 'sequence' => 1,
        'kind' => 'create', 'base_version' => 0,
        'operations' => [['field' => 'title', 'op' => 'set', 'value' => 'nope'], ['field' => 'status', 'op' => 'set', 'value' => 'open']],
    ])->assertOk()->json();

    expect($body['status'])->toBe('validation_failed');
    expect(array_diff(array_keys($body), declaredOf('PushResponse')))->toBe([]);
    expect($body['validation'])->not->toBeEmpty();
    expect(array_diff(array_keys($body['validation'][0]), declaredNested('PushResponse', 'validation')))->toBe([]);
});
