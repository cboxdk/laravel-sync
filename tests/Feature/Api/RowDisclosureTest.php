<?php

declare(strict_types=1);

/**
 * A field whitelist bounds columns. Only the view bounds rows, and a conflict
 * response is the one place that hands back a canonical value the caller did
 * not supply.
 */
it('does not disclose a conflicting value from a row outside the caller\'s view', function () {
    // A closed ticket: writable by this policy, but outside the view.
    $this->postJson('/sync/push', ['type' => 'tickets', 'scope' => 'team-1'] + mutation(
        'm1', 'secret-1', 1, 'create', 0, [setOp('subject', 'CONFIDENTIAL merger terms'), setOp('state', 'closed')]
    ), ['X-Test-Principal' => 'alice'])->assertOk();

    // It is genuinely invisible to a read.
    $bootstrap = $this->postJson('/sync/bootstrap', ['type' => 'tickets', 'scope' => 'team-1'], ['X-Test-Principal' => 'alice'])->assertOk();
    expect($bootstrap->json('records'))->toBeEmpty();

    // A stale write to it conflicts, and the response must not carry what a
    // bootstrap refused to show.
    $conflict = $this->postJson('/sync/push', ['type' => 'tickets', 'scope' => 'team-1'] + mutation(
        'm2', 'secret-1', 2, 'update', 0, [setOp('subject', 'guess')]
    ), ['X-Test-Principal' => 'alice']);

    $body = $conflict->getContent();
    expect($body)->not->toContain('CONFIDENTIAL merger terms');
});

it('still discloses a conflicting value for a row the caller can see', function () {
    $this->postJson('/sync/push', ['type' => 'tickets', 'scope' => 'team-1'] + mutation(
        'm1', 'open-1', 1, 'create', 0, [setOp('subject', 'visible'), setOp('state', 'open')]
    ), ['X-Test-Principal' => 'alice'])->assertOk();

    $this->postJson('/sync/push', ['type' => 'tickets', 'scope' => 'team-1'] + mutation(
        'm2', 'open-1', 2, 'update', 1, [setOp('subject', 'from alice')]
    ), ['X-Test-Principal' => 'alice'])->assertOk();

    $conflict = $this->postJson('/sync/push', ['type' => 'tickets', 'scope' => 'team-1'] + mutation(
        'm3', 'open-1', 3, 'update', 1, [setOp('subject', 'from bob')]
    ), ['X-Test-Principal' => 'alice']);

    expect($conflict->json('status'))->toBe('conflict');
    expect($conflict->json('conflicts.0.current.value'))->toBe('from alice');
});
