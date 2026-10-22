<?php

namespace ErnestDefoe\Cadence\Console;

use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuilds the posts and likes buckets from the forum's own tables, then every
 * flame from them.
 *
 * The repair for any drift, since the incremental path can only ever be as
 * correct as the events it heard. That includes permission changes: a
 * discussion moved into a staff-only tag, or a tag closed to guests, keeps the
 * activity it already contributed until this runs.
 */
class RebuildCommand extends RebuildStreaksCommand
{
    protected function configure(): void
    {
        $this->setName('cadence:rebuild')
            ->setDescription('Rebuild Cadence activity buckets from posts and likes, then every streak.')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Ignored: this always starts from the first member.');
    }

    protected function stages(): array
    {
        return ['activity', 'streaks'];
    }

    protected function fire(): int
    {
        $this->input->setOption('fresh', true);

        return parent::fire();
    }
}
