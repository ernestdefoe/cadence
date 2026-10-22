<?php

/*
 * Cadence — see a member's rhythm, not just their volume.
 *
 * The shape of this file is the shape of the extension: events write buckets,
 * one endpoint reads them, and the frontend never aggregates anything.
 */

use ErnestDefoe\Cadence\Api;
use ErnestDefoe\Cadence\Api\MapController;
use ErnestDefoe\Cadence\Backfill;
use ErnestDefoe\Cadence\Console\BackfillCommand;
use ErnestDefoe\Cadence\Console\RebuildCommand;
use ErnestDefoe\Cadence\Console\RebuildStreaksCommand;
use ErnestDefoe\Cadence\Console\SettleCommand;
use ErnestDefoe\Cadence\Extend\StartBackfill;
use ErnestDefoe\Cadence\Listener\ActivitySubscriber;
use ErnestDefoe\Cadence\Notification\StreakMoment;
use ErnestDefoe\Cadence\Notification\StreakWarning;
use ErnestDefoe\Cadence\Streak\FlameAttribute;
use ErnestDefoe\Cadence\Streak\Rules;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event;

/** Streaks are on, and this viewer may see them. */
$sees = fn (Context $context) => resolve(Rules::class)->enabled() && $context->getActor()->hasPermission('cadence.viewStreaks');

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        // 🚨 Publishes the lazy chunks (leaderboard, profile tab, header menu).
        // Without it they build fine and 404 on the forum.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        // So a pasted leaderboard link loads. The profile's Streak tab rides
        // on core's own /u/{username}/{filter} route.
        ->route('/streaks', 'cadence.streaks'),

    (new Extend\View())->namespace('ernestdefoe-cadence', __DIR__.'/views'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

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

    /*
     * 🚨 The static streak routes go BEFORE `/cadence/{id}`. FastRoute refuses
     * a static route that an earlier variable route already matches — and the
     * refusal is a BadRouteException while the route table is built, which is
     * a 500 on every API request, not just these.
     */
    (new Extend\Routes('api'))
        ->get('/cadence/streaks', 'cadence.streaks.board', Api\BoardController::class)
        ->get('/cadence/streaks-overview', 'cadence.streaks.overview', Api\OverviewController::class)
        ->get('/cadence/streaks/{id:[0-9]+}', 'cadence.streaks.member', Api\MemberController::class)
        ->post('/cadence/streaks/{id:[0-9]+}/manage', 'cadence.streaks.manage', Api\ManageController::class)
        ->post('/cadence/streak/visit', 'cadence.streak.visit', Api\VisitController::class)
        ->post('/cadence/streak/ack', 'cadence.streak.ack', Api\AckController::class)
        ->post('/cadence/backfill', 'cadence.backfill', Api\BackfillController::class)
        ->get('/cadence/{id}', 'cadence.map', MapController::class),

    (new Extend\Notification())
        ->type(StreakMoment::class, ['alert'])
        ->type(StreakWarning::class, ['alert', 'email']),

    (new Extend\User())
        // How loudly the first page of the day celebrates: every day, only the
        // moments that matter, or never.
        ->registerPreference('cadenceCelebrate', fn ($v) => in_array($v, ['all', 'important', 'never'], true) ? $v : 'important', 'important'),

    (new Extend\Console())
        ->command(RebuildCommand::class)
        ->command(RebuildStreaksCommand::class)
        ->command(SettleCommand::class)
        ->command(BackfillCommand::class)
        ->schedule(SettleCommand::class, function (Event $event): void {
            $event->hourly()->withoutOverlapping();
        })
        // A pending rebuild on a forum with no queue; a no-op otherwise.
        ->schedule(BackfillCommand::class, function (Event $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    // 🚨 Hands the history rebuild to the queue on enable; see Backfill.
    new StartBackfill(),

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

            // The flame beside a name: two numbers, batched like the spark.
            Schema\Arr::make('cadenceStreak')
                ->visible(fn (User $user, Context $context) => $sees($context))
                ->get(fn (User $user) => FlameAttribute::for($user)),

            // 🚨 The member's own flame in full — freezes, embers, timezone,
            // unseen moments — and only ever to the member themselves.
            Schema\Arr::make('cadenceFlame')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id && resolve(Rules::class)->enabled())
                ->get(fn (User $user) => FlameAttribute::self($user)),
        ]),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('cadenceStreaks')
                ->get(fn ($forum, Context $context) => $sees($context)),
            Schema\Boolean::make('canEarnStreaks')
                ->get(fn ($forum, Context $context) => resolve(Rules::class)->enabled() && $context->getActor()->hasPermission('cadence.earnStreaks')),
            Schema\Arr::make('cadenceStreakRules')
                ->visible(fn ($forum, Context $context) => $sees($context))
                ->get(function () {
                    $rules = resolve(Rules::class);

                    return [
                        'mode' => $rules->mode(),
                        'tiers' => array_values(array_filter($rules->tiers(), fn ($t) => $t['enabled'])),
                        'seasons' => $rules->seasons(),
                        'milestones' => $rules->milestones(),
                        'grace' => $rules->grace(),
                        'freezeBank' => $rules->freezeBank(),
                        'embersMin' => $rules->embersMin(),
                        'embersHours' => $rules->embersHours(),
                        'embersShare' => $rules->embersShare(),
                        // While the history rebuild runs: how far it has got.
                        'building' => Backfill::percent(json_decode((string) resolve(SettingsRepositoryInterface::class)->get(Backfill::KEY), true)),
                    ];
                }),
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
        ->serializeToForum('cadenceStreakOnPosts', 'ernestdefoe-cadence.streak_on_posts', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('cadenceStreakOnCards', 'ernestdefoe-cadence.streak_on_cards', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('cadenceStreakInHeader', 'ernestdefoe-cadence.streak_in_header', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('cadenceStreakOnProfile', 'ernestdefoe-cadence.streak_on_profile', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('cadenceStreakSidebar', 'ernestdefoe-cadence.streak_sidebar', fn ($v) => (bool) (int) $v)
        ->serializeToForum('cadenceStreakNav', 'ernestdefoe-cadence.streak_nav', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->default('ernestdefoe-cadence.rhythm', false)
        ->default('ernestdefoe-cadence.streaks', true)
        ->default('ernestdefoe-cadence.streak_mode', 'contribute')
        ->default('ernestdefoe-cadence.streak_on_posts', true)
        ->default('ernestdefoe-cadence.streak_on_cards', true)
        ->default('ernestdefoe-cadence.streak_in_header', true)
        ->default('ernestdefoe-cadence.streak_on_profile', true)
        ->default('ernestdefoe-cadence.streak_sidebar', false)
        ->default('ernestdefoe-cadence.streak_nav', true)
        // Shown in the admin as the values Rules falls back to anyway.
        ->default('ernestdefoe-cadence.streak_grace', 0)
        ->default('ernestdefoe-cadence.streak_milestones', Rules::DEFAULT_MILESTONES)
        ->default('ernestdefoe-cadence.streak_freeze_per_milestone', 1)
        ->default('ernestdefoe-cadence.streak_freeze_bank', 5)
        ->default('ernestdefoe-cadence.streak_embers_min', 14)
        ->default('ernestdefoe-cadence.streak_embers_hours', 48)
        ->default('ernestdefoe-cadence.streak_embers_share', 50)
        ->default('ernestdefoe-cadence.streak_hall_min', 7)
        ->default('ernestdefoe-cadence.streak_risk_min', 3)
        ->default('ernestdefoe-cadence.streak_risk_hour', 20)
        ->default('ernestdefoe-cadence.streak_bonfire_levels', '10,25,50,100')
        ->default('ernestdefoe-cadence.streak_timezone', 'UTC'),
];
