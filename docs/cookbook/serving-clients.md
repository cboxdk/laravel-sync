---
title: "Serving clients"
weight: 10
description: "Write the push, bootstrap and delta endpoints your application owns."
---

# Serving clients

The endpoints ship with the package now; see [transport](../core-concepts/transport.md)
for the wire format. What you write is the declaration of what may be reached.

## Declare a type

```php
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;

class TaskType implements SyncableType
{
    public function entityType(): string
    {
        return 'tasks';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        // A selector, not a space. Map it through the caller's memberships.
        return $this->teams->forUser($principal->id)->assert($scope)->syncSpace();
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('open-tasks', '1', 'status', 'open', 'tasks');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['title', 'status', 'due_at'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['title', 'status'];
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return $principal->id !== null;
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        return true;
    }
}
```

```php
// config/sync.php
'api' => [
    'enabled' => true,
    'middleware' => ['api', 'auth:sanctum'],
    'types' => ['tasks' => TaskType::class],
],
```

A type that is not listed is refused with 404. A type nobody declared is a type
nobody decided the authorization rules for.

## Two things worth saying twice

**`readableFields()` is the only column filter.** A view decides which *rows* a
client sees and has no opinion about fields. A record reaching the wire carries
every field, and each field's origin names the actor who wrote it, so the
whitelist is what stops both the values and the authorship from escaping.

**`view()` should reflect the caller's actual access.** A cursor is invalidated
only when its context fingerprint changes, and that fingerprint comes from the
view. A view whose signature ignores who is asking keeps serving deltas to
someone whose access was revoked.

## Mounting the endpoints yourself

Leave `sync.api.enabled` false and use the trait in your own controller, so
routing, naming and middleware stay yours:

```php
class MySyncController
{
    use HandlesSyncRequests;

    public function __construct(private readonly SyncEndpoints $sync) {}

    public function push(Request $request): JsonResponse
    {
        return $this->syncPush($request);
    }

    protected function syncEndpoints(): SyncEndpoints
    {
        return $this->sync;
    }
}
```

Your routes must still run `ResolveSyncPrincipal`, or every request is refused
with 401.
