<?php

namespace ErnestDefoe\Cadence\Console;

use ErnestDefoe\Cadence\Streak\StreakRebuilder;
use Flarum\Console\AbstractCommand;

/** Rebuild every flame from the activity Cadence holds. Safe to re-run. */
class RebuildStreaksCommand extends AbstractCommand
{
    public function __construct(private StreakRebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cadence:streaks:rebuild')
            ->setDescription('Rebuild Cadence streaks from recorded activity.');
    }

    protected function fire(): int
    {
        $members = $this->rebuilder->rebuild(fn (string $line) => $this->info($line));
        $this->info("Rebuilt flames for {$members} members.");

        return 0;
    }
}
