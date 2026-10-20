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
 *
 * 🚨 The create-table migration already has this column, and only a forum
 * that ran 1.2.0's create first needs it added. It cannot be a plain add:
 * 1.2.0 shipped it AFTER the history rebuild, which writes it — so every
 * upgrade from 1.1.x failed on "Unknown column 'day_ends_at'".
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('cadence_streaks', 'day_ends_at')) {
            return;
        }

        $schema->table('cadence_streaks', function (Blueprint $table) {
            $table->dateTime('day_ends_at')->nullable()->after('last_day');
        });
    },
    'down' => function (Builder $schema) {
        // Dropped with the table by the create migration on a fresh install.
        if (! $schema->hasColumn('cadence_streaks', 'day_ends_at')) {
            return;
        }

        $schema->table('cadence_streaks', function (Blueprint $table) {
            $table->dropColumn('day_ends_at');
        });
    },
];
