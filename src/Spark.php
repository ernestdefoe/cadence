<?php

namespace ErnestDefoe\Cadence;

use Carbon\Carbon;
use Closure;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The twenty-six weekly totals that ride along on a serialized user.
 *
 * This is what makes Cadence showable beside every post, on hover cards and in
 * a Page Builder section without any of those placements costing a request.
 *
 * 🚨 The thing this class exists to avoid: a sparkline that fetches its own
 * data is one request per rendered item. Thirty posts on a page is thirty
 * requests, which exhausts a shared host's database connection limit and
 * returns 500 for the entire forum — not for the decoration that caused it,
 * which is what makes it so hard to trace back.
 */
abstract class Spark
{
    private const WEEKS = 26;

    /**
     * 🚨 Memoised per request, keyed by member, and loaded in ONE query.
     *
     * The getter does not query. It notes the member and hands the serializer
     * a closure, which the serializer resolves only after every resource in
     * the document has been visited (the same deferral core's own relation
     * buffer uses). The first closure to run loads every noted member at once,
     * so a page costs one query however many members are on it.
     */
    private static array $memo = [];

    /** @var array<int, true> Members noted but not loaded yet. */
    private static array $pending = [];

    private static ?bool $wanted = null;

    /**
     * @return array<int>|Closure|null
     */
    public static function for(User $user): array|Closure|null
    {
        if (! self::wanted()) {
            return null;
        }

        $id = (int) $user->id;

        if ($id <= 0) {
            return null;
        }

        if (isset(self::$memo[$id])) {
            return self::$memo[$id];
        }

        self::$pending[$id] = true;

        return function () use ($id): array {
            if (! isset(self::$memo[$id])) {
                self::loadPending();
            }

            return self::$memo[$id] ?? array_fill(0, self::WEEKS, 0);
        };
    }

    /**
     * Nothing is computed at all unless a compact placement is switched on.
     *
     * 🚨 Both placements default to OFF. A forum that only wants the profile
     * map should not pay a query per member on every page it renders, and an
     * extension that quietly adds one the moment it is installed is the kind
     * that gets uninstalled without a bug report.
     */
    private static function wanted(): bool
    {
        if (self::$wanted !== null) {
            return self::$wanted;
        }

        $settings = resolve(SettingsRepositoryInterface::class);

        return self::$wanted = (bool) (int) $settings->get('ernestdefoe-cadence.show_on_posts')
            || (bool) (int) $settings->get('ernestdefoe-cadence.show_on_cards');
    }

    private static function loadPending(): void
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];

        if ($ids === []) {
            return;
        }

        $db = resolve(ConnectionInterface::class);

        $start = Carbon::now('UTC')->startOfWeek()->subWeeks(self::WEEKS - 1);

        $weeks = array_fill_keys($ids, array_fill(0, self::WEEKS, 0));

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $db->table('cadence_activity')
                ->select(['user_id', 'bucket', 'count'])
                ->whereIn('user_id', $chunk)
                ->where('bucket', '>=', $start->toDateTimeString())
                ->get();

            foreach ($rows as $row) {
                $index = (int) $start->diffInWeeks(Carbon::parse($row->bucket, 'UTC'));

                if ($index >= 0 && $index < self::WEEKS) {
                    $weeks[(int) $row->user_id][$index] += (int) $row->count;
                }
            }
        }

        self::$memo = $weeks + self::$memo;
    }

    /** Between requests in a queue worker, the memo must not outlive its request. */
    public static function reset(): void
    {
        self::$memo = [];
        self::$pending = [];
        self::$wanted = null;
    }
}
