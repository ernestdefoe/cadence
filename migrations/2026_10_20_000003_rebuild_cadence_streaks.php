<?php

use ErnestDefoe\Cadence\Streak\StreakRebuilder;
use Illuminate\Database\Schema\Builder;

/**
 * 🚨 Every member starts with the streak their history already earned.
 *
 * A streak feature that installs with everybody on zero tells the member who
 * has posted every day for two years that they have done nothing, on the very
 * day the feature is announced to them.
 */
return [
    'up' => function (Builder $schema) {
        resolve(StreakRebuilder::class)->rebuild();
    },
    'down' => function (Builder $schema) {
    },
];
