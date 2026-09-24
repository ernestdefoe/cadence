<?php

/*
 * Cadence — see a member's rhythm, not just their volume.
 *
 * The shape of this file is the shape of the extension: events write buckets,
 * one endpoint reads them, and the frontend never aggregates anything.
 */

use ErnestDefoe\Cadence\Api\MapController;
use ErnestDefoe\Cadence\Console\RebuildCommand;
use ErnestDefoe\Cadence\Listener\ActivitySubscriber;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * 🚨 A SUBSCRIBER, not a list of `->listen()` calls.
     *
     * `Extend\Event::listen()` is typed `callable|string`: a class name whose
     * `handle()` runs, or a closure. Its own docblock says an array callable
     * works and the signature refuses it — and the refusal is a TypeError while
     * extenders are applied, so it is a 500 on EVERY route, not a broken
     * feature. Ten events would have meant ten classes; a subscriber is the
     * idiom Flarum inherits from Laravel for exactly this, and it is also where
     * the optional likes/reactions/best-answer events get guarded.
     */
    (new Extend\Event())
        ->subscribe(ActivitySubscriber::class),

    (new Extend\Routes('api'))
        ->get('/cadence/{id}', 'cadence.map', MapController::class),

    (new Extend\Console())
        ->command(RebuildCommand::class),

    /*
     * 🚨 The compact sparkline rides along on the user the page has ALREADY
     * loaded. It is not an endpoint.
     *
     * Cadence can be shown beside every post, and a placement like that with a
     * request of its own is one request per rendered item — thirty on an
     * ordinary page, which is how a shared host runs out of database
     * connections and returns 500 for the whole forum rather than for the
     * decoration that caused it.
     *
     * 26 weekly totals is about a hundred bytes per distinct member on the
     * page, and it is already in the payload the browser was waiting for.
     */
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Arr::make('cadenceSpark')
                ->get(fn (User $user) => \ErnestDefoe\Cadence\Spark::for($user)),
        ]),

    (new Extend\Settings())
        /*
         * 🚨 Three arguments, not four. PHP silently ignores extra positional
         * arguments to a userland method, so a default passed here would look
         * right and do nothing — the defaults below are the ones that work.
         *
         * 🚨 And the cast is not decoration: settings come back as strings, and
         * '0' is a true string. Serialising one raw is how a switch an admin
         * turned OFF arrives at the browser turned ON.
         */
        ->serializeToForum('cadenceShowOnProfile', 'ernestdefoe-cadence.show_on_profile', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('cadenceShowOnPosts', 'ernestdefoe-cadence.show_on_posts', fn ($v) => (bool) (int) $v)
        ->serializeToForum('cadenceShowOnCards', 'ernestdefoe-cadence.show_on_cards', fn ($v) => (bool) (int) $v)
        ->serializeToForum('cadenceRhythm', 'ernestdefoe-cadence.rhythm', fn ($v) => (bool) (int) $v)
        ->default('ernestdefoe-cadence.show_on_profile', true)
        ->default('ernestdefoe-cadence.show_on_posts', false)
        ->default('ernestdefoe-cadence.show_on_cards', false)
        ->default('ernestdefoe-cadence.rhythm', false),
];
