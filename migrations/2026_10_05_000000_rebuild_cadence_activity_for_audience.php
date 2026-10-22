<?php

use ErnestDefoe\Cadence\Backfill;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Schema\Builder;

/**
 * 🚨 Activity recorded before 1.0.x was not limited to what the map's audience
 * can see: posts in staff-only tags, private discussions and direct messages
 * were counted on public maps. On a fresh install this is also what fills the
 * map from the forum's history.
 *
 * 🚨 It only ASKS for the rebuild. Doing it here ran it inside the admin's
 * "enable" click, which a forum with half a million posts cannot finish before
 * PHP-FPM gives up (Discuss #15). The Backfill does it in the background.
 */
return [
    'up' => function (Builder $schema) {
        resolve(Backfill::class)->request('activity');
    },
    'down' => function (Builder $schema) {
        // Last down on uninstall: nothing is left to rebuild.
        resolve(SettingsRepositoryInterface::class)->delete(Backfill::KEY);
    },
];
