<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Http\Controllers;

use Cbox\Sync\Laravel\Api\Concerns\HandlesSyncRequests;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncPushController
{
    use HandlesSyncRequests;

    public function __construct(private readonly SyncEndpoints $sync) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->syncPush($request);
    }

    protected function syncEndpoints(): SyncEndpoints
    {
        return $this->sync;
    }
}
