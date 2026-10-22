<?php

namespace ErnestDefoe\Cadence\Console;

use ErnestDefoe\Cadence\Backfill;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuild every flame from the activity Cadence holds, with a progress bar.
 *
 * Resumes a rebuild that is already pending — one a migration asked for, or
 * one that was interrupted — rather than starting over, unless --fresh. Safe
 * to stop with Ctrl+C and run again. Works alongside a queue worker or the
 * scheduler: whichever runner holds the lock works, and this follows along.
 */
class RebuildStreaksCommand extends AbstractCommand
{
    public function __construct(protected Backfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cadence:rebuild-streaks')
            ->setAliases(['cadence:streaks:rebuild'])
            ->setDescription('Rebuild Cadence streaks from recorded activity. Resumes an unfinished rebuild.')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Start again from the first member.');
    }

    /** @return string[] what a fresh run rebuilds */
    protected function stages(): array
    {
        return ['streaks'];
    }

    protected function fire(): int
    {
        if ($this->input->getOption('fresh') || ! $this->backfill->state()) {
            $this->backfill->request(...$this->stages());
        }

        $start = microtime(true);
        $bar = new ProgressBar($this->output, 100);
        $bar->setFormat(' %stage:-9s% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->setMessage($this->backfill->state()['stages'][0] ?? 'streaks', 'stage');
        $bar->start();

        $last = null;
        while ($state = $this->backfill->run(2, fn (array $s) => $this->show($bar, $s))) {
            // Another runner holds the lock (a queue job, the scheduler, or a
            // run that was killed, until its lock expires): follow along.
            if ($state === $last) {
                usleep(500000);
            }
            $this->show($bar, $state);
            $last = $state;
        }

        $bar->finish();
        $this->output->writeln('');
        $this->info(sprintf('Done in %.1f s.', microtime(true) - $start));

        return 0;
    }

    private function show(ProgressBar $bar, array $state): void
    {
        $bar->setMessage($state['stages'][0], 'stage');
        $bar->setProgress((int) Backfill::percent($state));
    }
}
