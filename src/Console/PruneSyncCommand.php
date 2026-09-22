<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Console;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\ValueObjects\CommitSequence;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Drops commit history a space no longer needs.
 *
 * The log is the only thing in the engine that grows without bound, and nothing
 * prunes it on its own: how much history a tenant owes its slowest device is a
 * question only the host can answer. This is the supported way to answer it.
 *
 * A space is named rather than discovered. The store deliberately cannot list
 * its spaces - a tenant boundary is the host's to enumerate - and widening the
 * contract to feed a command would be the tail wagging the dog.
 */
class PruneSyncCommand extends Command
{
    protected $signature = 'sync:prune
        {space* : The spaces to prune}
        {--keep= : Commits to retain per space, defaulting to sync.retention.keep_commits}
        {--pretend : Report what would be dropped and drop nothing}';

    protected $description = 'Drop commit history below the retention window';

    public function handle(Store $store, Repository $config): int
    {
        $keep = $this->keep($config);
        if ($keep < 1) {
            $this->error('Retention must keep at least one commit.');

            return self::FAILURE;
        }

        foreach ($this->spaces() as $space) {
            $watermark = $store->watermark($space)->value;
            $retained = $store->retainedFrom($space)->value;
            $horizon = $watermark - $keep + 1;

            if ($horizon <= $retained) {
                $this->line(sprintf('%s: nothing to drop (%d commits retained)', $space, max(0, $watermark - $retained + 1)));

                continue;
            }

            $dropping = $horizon - $retained;
            if ($this->option('pretend') === true) {
                $this->line(sprintf('%s: would drop %d commit(s), keeping from %d', $space, $dropping, $horizon));

                continue;
            }

            $store->prune($space, new CommitSequence($horizon));
            $this->info(sprintf('%s: dropped %d commit(s), keeping from %d', $space, $dropping, $horizon));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function spaces(): array
    {
        $spaces = [];
        foreach ((array) $this->argument('space') as $space) {
            if (is_string($space) && $space !== '') {
                $spaces[] = $space;
            }
        }

        return $spaces;
    }

    private function keep(Repository $config): int
    {
        // An int from Artisan::call, a string from the terminal.
        $option = $this->option('keep');
        if (is_int($option)) {
            return $option;
        }
        if (is_string($option) && ctype_digit($option)) {
            return (int) $option;
        }

        $configured = $config->get('sync.retention.keep_commits');

        return is_int($configured) ? $configured : 10_000;
    }
}
