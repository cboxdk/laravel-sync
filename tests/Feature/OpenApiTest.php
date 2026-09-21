<?php

declare(strict_types=1);

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
