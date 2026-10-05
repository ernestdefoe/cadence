<?php

namespace ErnestDefoe\Cadence\Console;

use ErnestDefoe\Cadence\Rebuilder;
use Flarum\Console\AbstractCommand;

/**
 * Rebuilds the posts and likes buckets from the forum's own tables.
 *
 * Needed on install — a map that starts empty on a forum with ten years of
 * history tells every member they have never done anything — and as the repair
 * for any drift, since the incremental path can only ever be as correct as the
 * events it heard. That includes permission changes: a discussion moved into a
 * staff-only tag, or a tag closed to guests, keeps the activity it already
 * contributed until this runs.
 */
class RebuildCommand extends AbstractCommand
{
    public function __construct(private Rebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cadence:rebuild')
            ->setDescription('Rebuild Cadence activity buckets from posts and likes.');
    }

    protected function fire(): int
    {
        $total = $this->rebuilder->rebuild(fn (string $line) => $this->info($line));

        $this->info("Rebuilt {$total} buckets.");

        return 0;
    }
}
