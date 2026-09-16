<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Cbox\Sync\Laravel\Testing\InteractsWithSync;
use Orchestra\Testbench\TestCase;

/** Composes the shipped trait so static analysis covers it the way a host would use it. */
class SyncHarness extends TestCase
{
    use InteractsWithSync;
}
