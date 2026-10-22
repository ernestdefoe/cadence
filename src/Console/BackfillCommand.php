<?php

namespace ErnestDefoe\Cadence\Console;

use ErnestDefoe\Cadence\Backfill;
use Flarum\Console\AbstractCommand;

/**
 * Every minute, from the scheduler: twenty seconds of a pending rebuild when
 * there is no queue to do it, or when the queue's runner has gone quiet. One
 * read of one setting when there is nothing to do.
 */
class BackfillCommand extends AbstractCommand
{
    public function __construct(private Backfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cadence:backfill')
            ->setDescription('Continue a pending Cadence rebuild for a few seconds (run by the scheduler).')
            ->setHidden();
    }

    protected function fire(): int
    {
        $state = $this->backfill->state();

        if ($state && (! $this->backfill->hasQueue() || $this->backfill->stalled($state))) {
            $this->backfill->run(20);
        }

        return 0;
    }
}
