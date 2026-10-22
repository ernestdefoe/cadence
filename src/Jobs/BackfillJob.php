<?php

namespace ErnestDefoe\Cadence\Jobs;

use ErnestDefoe\Cadence\Backfill;
use Flarum\Queue\AbstractJob;

/**
 * A few seconds of the rebuild, then the next job. Short, so a worker shared
 * with notifications and email never sits behind it for long.
 */
class BackfillJob extends AbstractJob
{
    public function handle(Backfill $backfill): void
    {
        if ($backfill->run(20)) {
            $backfill->dispatch();
        }
    }
}
