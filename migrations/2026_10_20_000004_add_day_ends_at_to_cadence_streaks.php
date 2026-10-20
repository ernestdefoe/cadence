<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The instant the last fed day really ended, fixed when it was fed.
 *
 * 🚨 Without it a streak could be gamed by changing timezone: hop east and
 * "tomorrow" arrives hours early to be fed again; hop west and the deadline
 * slides a day later. Anchoring both the next day and the deadline to this
 * instant makes a timezone change move neither.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('cadence_streaks', function (Blueprint $table) {
            $table->dateTime('day_ends_at')->nullable()->after('last_day');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('cadence_streaks', function (Blueprint $table) {
            $table->dropColumn('day_ends_at');
        });
    },
];
