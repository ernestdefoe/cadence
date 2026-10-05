<?php

use ErnestDefoe\Cadence\Rebuilder;
use Illuminate\Database\Schema\Builder;

/**
 * 🚨 Activity recorded before 1.0.x was not limited to what the map's audience
 * can see: posts in staff-only tags, private discussions and direct messages
 * were counted on public maps. Fixing the recorder stops new rows; this clears
 * the ones already written by rebuilding from the forum's own tables, the same
 * as `php flarum cadence:rebuild`.
 */
return [
    'up' => function (Builder $schema) {
        resolve(Rebuilder::class)->rebuild();
    },
    'down' => function (Builder $schema) {
        // Nothing to undo: the rebuilt buckets are the correct ones.
    },
];
