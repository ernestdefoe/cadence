<?php

use ErnestDefoe\Cadence\Backfill;
use Illuminate\Database\Schema\Builder;

/**
 * 🚨 Every member starts with the streak their history already earned.
 *
 * A streak feature that installs with everybody on zero tells the member who
 * has posted every day for two years that they have done nothing, on the very
 * day the feature is announced to them.
 *
 * 🚨 This only ASKS for the rebuild; the Backfill does it in the background.
 * 1.2.0–1.2.2 did it right here, inside the "enable" click, and on a forum of
 * 568k posts that outlived PHP-FPM's 30 seconds (Discuss #15). That run was
 * never recorded as done, so this runs again on the next enable or migrate and
 * the rebuild repairs whatever the interrupted one left half-written.
 */
return [
    'up' => function (Builder $schema) {
        resolve(Backfill::class)->request('streaks');
    },
    'down' => function (Builder $schema) {
    },
];
