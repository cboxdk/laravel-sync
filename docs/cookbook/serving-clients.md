---
title: "Serving clients"
weight: 10
description: "Write the push, bootstrap and delta endpoints your application owns."
---

# Serving clients

This package ships no routes and no wire format. That is deliberate: how a
request becomes a mutation, who may send it, and which space it belongs to are
application decisions that a library cannot make safely on your behalf.

A minimal set of endpoints looks like this.

## Push

```php
public function push(PushRequest $request, Engine $engine): JsonResponse
{
    $space = $request->user()->currentTeam->getKey();   // your tenancy, your rule

    $result = $engine->process(new Mutation(
        id: $request->string('mutation_id')->toString(),
        entity: new EntityKey($space, $request->string('type'), $request->string('id')),
        replica: new Replica($request->string('device')),
        sequence: new MutationSequence($request->integer('sequence')),
        kind: MutationKind::from($request->string('kind')),
        baseVersion: new RecordVersion($request->integer('base_version')),
        operations: $request->operations(),
    ), new AdapterContext(actorId: (string) $request->user()->getKey()));

    return response()->json(['status' => $result->status->value, 'version' => $result->recordVersion->value]);
}
```

The space must come from the authenticated session, never from the request body.
Nothing in the engine checks who is allowed to write where.

`AdapterContext` records who made the change as trusted provenance, separate from
the replica the client claims.

## Bootstrap and delta

```php
$views = app(ViewSyncService::class);
$view = new MyView($request->user());

$token = $request->filled('token')
    ? new BootstrapToken($request->string('token'))
    : $views->openBootstrap($views->context($space, $view), $view, 100);

$page = $views->bootstrap($token, $view);
```

Tokens are stateless by default, so return `$page->nextToken` to the client and
let any worker serve the next page. When `$page->cursor` arrives the bootstrap is
done and the client moves to deltas.

## Handling a reset

`ResetRequired` means the client's local state for that view can no longer be
trusted — the view definition changed, the epoch rotated, or the cursor fell
below the retention horizon. Map it to a status your client understands and have
it bootstrap that view again.

```php
try {
    return response()->json($views->delta($cursor, $view));
} catch (ResetRequired $reset) {
    return response()->json(['reset' => $reset->reason->value], 409);
}
```
