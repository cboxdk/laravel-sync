<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Contracts;

use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Illuminate\Http\Request;

/**
 * The work behind each endpoint, so the controller trait only has to deal with
 * HTTP. Takes the whole request rather than a decoded array, because the raw
 * body is what the field codec needs.
 */
interface SyncEndpoints
{
    /** @return array<string, mixed> */
    public function push(Request $request, SyncPrincipal $principal): array;

    /** @return array<string, mixed> */
    public function bootstrap(Request $request, SyncPrincipal $principal): array;

    /** @return array<string, mixed> */
    public function delta(Request $request, SyncPrincipal $principal): array;
}
