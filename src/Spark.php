<?php

namespace ErnestDefoe\Cadence;

use Carbon\Carbon;
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
     * 🚨 Memoised per request, keyed by member.
     *
     * The same twelve people write most of the posts on a page, and without
     * this the same member's sparkline is recomputed for every post they made
     * in the thread. With it, a page costs one small indexed query per DISTINCT
     * member, which is the floor for something serialized per user.
     */
    private static array $memo = [];

    private static ?bool $wanted = null;

    /**
     * @return array<int>|null
     */
    public static function for(User $user): ?array
    {
        if (! self::wanted()) {
            return null;
        }

        $id = (int) $user->id;

        if ($id <= 0) {
            return null;
        }

        return self::$memo[$id] ??= self::compute($id);
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

    /** @return array<int> */
    private static function compute(int $userId): array
    {
        $db = resolve(ConnectionInterface::class);

        $start = Carbon::now('UTC')->startOfWeek()->subWeeks(self::WEEKS - 1);

        $rows = $db->table('cadence_activity')
            ->selectRaw('bucket, count')
            ->where('user_id', $userId)
            ->where('bucket', '>=', $start->toDateTimeString())
            ->get();

        $weeks = array_fill(0, self::WEEKS, 0);

        foreach ($rows as $row) {
            $index = (int) $start->diffInWeeks(Carbon::parse($row->bucket, 'UTC'));

            if ($index >= 0 && $index < self::WEEKS) {
                $weeks[$index] += (int) $row->count;
            }
        }

        return $weeks;
    }

    /** Between requests in a queue worker, the memo must not outlive its request. */
    public static function reset(): void
    {
        self::$memo = [];
        self::$wanted = null;
    }
}
