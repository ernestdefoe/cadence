<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use ErnestDefoe\Cadence\Notification\StreakMoment;
use ErnestDefoe\Cadence\Notification\StreakWarning;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The only thing that changes a flame.
 *
 * A flame is fed at most once per local day. The first feed of a day does the
 * work — gap, freezes, revival, milestones, tiers, record — and every later one
 * returns after reading one row, so posting forty times a day costs forty
 * primary-key lookups and one write.
 *
 * 🚨 A day is the MEMBER's day, in their own timezone. A server-day streak
 * breaks for someone in Sydney who posts every evening, because their evening
 * straddles the server's midnight, and that is exactly the member a streak
 * exists to reward.
 */
class Flames
{
    public function __construct(
        private ConnectionInterface $db,
        private Rules $rules,
        private Journal $journal,
        private NotificationSyncer $notifications,
    ) {
    }

    /**
     * Feed a member's flame for today.
     *
     * `$contribution` is true for posting, liking and reacting, false for a
     * visit. Visits only count when the forum is in visit mode, and only a
     * contribution can revive embers — "post any message to bring it back".
     *
     * @return array<int, array{type: string, value: int}> what happened, for a toast
     */
    public function feed(int $userId, bool $contribution, ?Carbon $now = null): array
    {
        /*
         * 🚨 A flame must never cost a member their post. This runs inside the
         * request that saves a reply or a like; anything it throws is logged
         * and swallowed, so the worst a bug here can do is miss a day.
         */
        try {
            return $this->feedUnguarded($userId, $contribution, $now);
        } catch (\Throwable $e) {
            resolve(\Psr\Log\LoggerInterface::class)->error('[cadence] streak feed failed: '.$e->getMessage(), ['exception' => $e]);

            return [];
        }
    }

    private function feedUnguarded(int $userId, bool $contribution, ?Carbon $now): array
    {
        if ($userId <= 0 || ! $this->rules->enabled()) {
            return [];
        }

        if (! $contribution && $this->rules->mode() !== 'visit') {
            return [];
        }

        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();

        $notify = [];

        $events = $this->db->transaction(function () use ($userId, $contribution, $now, &$notify) {
            $row = $this->lock($userId);
            $tz = $this->tz($row);
            $today = $now->copy()->setTimezone($tz)->toDateString();

            /*
             * Already fed "today" — the ordinary case, one indexed read and no
             * write. 🚨 Judged by the instant the last fed day really ended,
             * not only by the date: a member who switches to a timezone ahead
             * of theirs reaches "tomorrow" early, and one who switches behind
             * is back in "yesterday". Neither is a new day.
             */
            $fedToday = $row && $row->last_day !== null
                && ($today <= $row->last_day || ($row->day_ends_at && $now->lt($row->day_ends_at)));

            if ($fedToday) {
                if (! $contribution || ! $row->hasEmbers($now)) {
                    return [];
                }
            }

            /*
             * Permission is asked only on the first feed of a day, so the
             * common path above never loads a user or their groups.
             */
            $user = User::query()->find($userId);

            if (! $user || ! $user->hasPermission('cadence.earnStreaks')) {
                return [];
            }

            if (! $row) {
                $this->db->table('cadence_streaks')->insertOrIgnore(['user_id' => $userId]);
                $row = $this->lock($userId);
            }

            $events = $this->settle($row, $now);

            if ($fedToday) {
                // Only here because embers are glowing and this is a post.
                $events = array_merge($events, $this->revive($row, $row->last_day, $now));
            } else {
                $events = array_merge($events, $this->advance($row, $today, $contribution, $now));
            }

            $this->celebrate($row, $events);
            $row->save();

            /*
             * 🚨 ONE alert per feed, the moment that matters most. Posting runs
             * inside NotificationSyncer::onePerUser(), which silently drops a
             * member's second notification in the same request — so a
             * milestone and a tier reached by one reply would have arrived as
             * whichever happened to be synced first. The toast still shows them
             * all; the alert list gets the headline.
             */
            $rank = ['tier' => 5, 'record' => 4, 'milestone' => 3, 'revived' => 2, 'saved' => 1];
            $notify = array_filter($events, fn ($e) => isset($rank[$e['type']]));
            usort($notify, fn ($a, $b) => $rank[$b['type']] <=> $rank[$a['type']]);
            $notify = array_slice($notify, 0, 1);

            return $events;
        });

        FlameAttribute::forget($userId);

        foreach ($notify as $event) {
            $this->notify($userId, $event);
        }

        return $events;
    }

    /**
     * Put out a flame whose time ran out, leaving embers if it was long enough.
     *
     * Called before every feed and by the hourly job. 🚨 The embers window is
     * counted from the moment the flame went OUT, not from when anybody
     * noticed — otherwise a forum without a scheduler would give a member who
     * returns a month later a fresh 48 hours to revive a flame that died weeks
     * ago, and the same rules would mean different things on different hosts.
     *
     * @return array<int, array{type: string, value: int}>
     */
    public function settle(Streak $row, Carbon $now): array
    {
        $events = [];

        if ($row->current > 0 && $row->alive_until && $row->alive_until->lt($now)) {
            $length = $row->current;

            if ($length >= $this->rules->hallMin()) {
                $this->journal->write($row->user_id, Journal::ENDED, $length, ['from' => $row->started_on, 'to' => $row->last_day], null, $row->alive_until);
            }

            if ($this->rules->embersMin() > 0 && $length >= $this->rules->embersMin()) {
                $row->embers = $length;
                $row->embers_until = $row->alive_until->copy()->addHours($this->rules->embersHours());
            }

            $row->current = 0;
            $row->started_on = null;

            $events[] = ['type' => 'ended', 'value' => $length];
        }

        if ($row->embers > 0 && ! $row->hasEmbers($now)) {
            $row->embers = 0;
            $row->embers_until = null;
        }

        return $events;
    }

    /** @return array<int, array{type: string, value: int}> */
    private function advance(Streak $row, string $today, bool $contribution, Carbon $now): array
    {
        $events = [];
        $old = $row->current; // already settled: 0 when out
        $revived = false;

        if ($old > 0 && $row->last_day !== null) {
            // Days strictly between the last fed day and today: yesterday
            // fed means a gap of 0. (`days()` counts both ends.)
            $gap = $this->days($row->last_day, $today) - 2;
            $frozen = max(0, $gap - $this->rules->grace());

            if ($frozen > 0) {
                // settle() guaranteed the bank covers it; the gap's last days
                // are the frozen ones, its first days the grace.
                $row->freezes = max(0, $row->freezes - $frozen);
                $saved = [];
                for ($i = $frozen; $i >= 1; $i--) {
                    $saved[] = CarbonImmutable::parse($today)->subDays($i)->toDateString();
                }
                $this->journal->write($row->user_id, Journal::SAVED, $frozen, ['days' => $saved], null, $now);
                $events[] = ['type' => 'saved', 'value' => $frozen];
            }

            // 🚨 Days covered by grace or a freeze count: the flame is the run
            // from its first day to today, so `started_on` alone defines it.
            $new = $this->days($row->started_on, $today);
        } elseif ($contribution && $row->hasEmbers($now)) {
            $new = $this->revivedLength($row);
            $revived = true;
        } else {
            $new = 1;
            $row->started_on = $today;
        }

        if ($revived) {
            $events = array_merge($events, $this->rekindle($row, $new, $today, $now));
        }

        $row->current = $new;
        $row->last_day = $today;
        $row->day_ends_at = $this->endOf($row, $today);
        $row->total_days = $row->total_days + 1;
        $row->fed_at = $now;

        $events[] = ['type' => 'day', 'value' => $new];

        if (! $revived) {
            $events = array_merge($events, $this->rewards($row, $old, $new, $now));
        }

        if ($new > $row->longest) {
            // A record is news only when there WAS one worth beating.
            if (! $revived && $old <= $row->longest && $row->longest >= 3) {
                $this->journal->write($row->user_id, Journal::RECORD, $new, null, null, $now);
                $events[] = ['type' => 'record', 'value' => $new];
            }
            $row->longest = $new;
        }

        $this->refresh($row);

        return $events;
    }

    /** Fed today by a visit, embers still glowing, and now a post: bring it back. */
    private function revive(Streak $row, string $today, Carbon $now): array
    {
        $new = max($row->current, $this->revivedLength($row));
        $events = $this->rekindle($row, $new, $today, $now);
        $row->current = $new;
        $row->longest = max($row->longest, $new);
        $this->refresh($row);

        return $events;
    }

    private function revivedLength(Streak $row): int
    {
        return max(1, intdiv($row->embers * $this->rules->embersShare(), 100));
    }

    private function rekindle(Streak $row, int $new, string $today, Carbon $now): array
    {
        $row->started_on = CarbonImmutable::parse($today)->subDays($new - 1)->toDateString();
        $this->journal->write($row->user_id, Journal::REVIVED, $new, ['from' => $row->embers], null, $now);
        $row->embers = 0;
        $row->embers_until = null;

        return [['type' => 'revived', 'value' => $new]];
    }

    /** Milestones crossed (and the freezes they earn) and a tier reached. */
    private function rewards(Streak $row, int $old, int $new, Carbon $now): array
    {
        $events = [];

        foreach ($this->rules->milestones() as $milestone) {
            if ($milestone > $old && $milestone <= $new) {
                $earned = min($this->rules->freezesPerMilestone(), max(0, $this->rules->freezeBank() - $row->freezes));
                $row->freezes += $earned;
                $this->journal->write($row->user_id, Journal::MILESTONE, $milestone, $earned ? ['freezes' => $earned] : null, null, $now);
                $events[] = ['type' => 'milestone', 'value' => $milestone];
            }
        }

        $before = $this->rules->tierFor($old);
        $after = $this->rules->tierFor($new);

        // The first tier (day one) is not news; reaching the second is.
        if ($after && $after['days'] > 1 && $after['days'] > ($before['days'] ?? 0)) {
            $this->journal->write($row->user_id, Journal::TIER, $after['days'], ['key' => $after['key'], 'name' => $after['name']], null, $now);
            $events[] = ['type' => 'tier', 'value' => $after['days']];
        }

        return $events;
    }

    /**
     * Recompute when the flame goes out if nothing feeds it.
     *
     * The end of the member's tomorrow, widened by grace and by every freeze in
     * the bank, because each of those is a day the flame survives unfed.
     */
    public function refresh(Streak $row): void
    {
        if ($row->current <= 0 || ! $row->last_day) {
            $row->alive_until = null;

            return;
        }

        // Counted from the fixed end of the last fed day, so a later timezone
        // change cannot stretch (or cut) the deadline.
        $ends = $row->day_ends_at ? Carbon::instance($row->day_ends_at)->utc() : $this->endOf($row, $row->last_day);
        $row->alive_until = $this->aliveUntil($ends, $row->freezes);
    }

    /** When a flame whose last fed day ends at `$ends` goes out: whole days of 24 hours. */
    public function aliveUntil(Carbon $ends, int $freezes): Carbon
    {
        return $ends->copy()->addHours(24 * (1 + $this->rules->grace() + $freezes));
    }

    /** The UTC instant a local date ends, in the member's current timezone. */
    public function endOf(Streak $row, string $date): Carbon
    {
        return Carbon::parse($date, $this->tz($row))->endOfDay()->utc();
    }

    public function tz(?Streak $row): string
    {
        return Rules::validTimezone($row?->timezone) ?? $this->rules->defaultTimezone();
    }

    /** Remember a member's own timezone. Written only when it changes. */
    public function setTimezone(int $userId, string $tz): void
    {
        if (! Rules::validTimezone($tz)) {
            return;
        }

        $this->db->transaction(function () use ($userId, $tz) {
            $row = $this->lock($userId);

            if (! $row) {
                $this->db->table('cadence_streaks')->insertOrIgnore(['user_id' => $userId, 'timezone' => $tz]);

                return;
            }

            // Only the zone changes: the last fed day's end, and so the
            // deadline, stay where they were (see feed()).
            if ($row->timezone !== $tz) {
                $row->timezone = $tz;
                $row->save();
            }
        });
    }

    /** Moments the member has not seen as a toast yet; at most the last ten. */
    private function celebrate(Streak $row, array $events): void
    {
        if (! $events) {
            return;
        }

        $queue = json_decode((string) $row->celebrate, true) ?: [];
        $row->celebrate = json_encode(array_slice(array_merge($queue, array_values(array_filter($events, fn ($e) => $e['type'] !== 'ended'))), -10));
    }

    private function notify(int $userId, array $event): void
    {
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $this->notifications->sync(new StreakMoment($user, $event['type'], (int) $event['value'], Carbon::now('UTC')->toDateString()), [$user]);
    }

    public function warn(User $user, string $kind, int $value, string $on): void
    {
        $this->notifications->sync(new StreakWarning($user, $kind, $value, $on), [$user]);
    }

    /**
     * Staff changes from the flame sheet. Every one is journaled with who did it.
     *
     * - give:   add freezes (kept within the bank unless $force)
     * - edit:   set the current length, ending today
     * - revive: relight the last flame that went out, at full length
     * - reset:  put the current flame out, without embers
     */
    public function manage(int $userId, string $action, int $value, User $actor, bool $force = false): Streak
    {
        return $this->db->transaction(function () use ($userId, $action, $value, $actor, $force) {
            $this->db->table('cadence_streaks')->insertOrIgnore(['user_id' => $userId]);
            $row = $this->lock($userId);
            $now = Carbon::now('UTC');
            $this->settle($row, $now);
            $today = $now->copy()->setTimezone($this->tz($row))->toDateString();

            switch ($action) {
                case 'give':
                    $cap = $force ? 255 : $this->rules->freezeBank();
                    $before = $row->freezes;
                    $row->freezes = max(0, min($cap, $row->freezes + $value));
                    $this->journal->write($userId, Journal::GIVEN, abs($row->freezes - $before), ['freezes' => $row->freezes], $actor->id);
                    break;

                case 'edit':
                    $value = max(0, min(100000, $value));
                    $this->light($row, $value, $today);
                    $this->journal->write($userId, Journal::EDITED, $value, null, $actor->id);
                    break;

                case 'revive':
                    $last = $this->db->table('cadence_streak_journal')
                        ->where('user_id', $userId)->where('type', Journal::ENDED)
                        ->orderByDesc('created_at')->value('value');
                    $length = max((int) $last, $row->embers, $row->burning($now)) + ($row->last_day === $today ? 0 : 1);
                    $this->light($row, max(1, $length), $today);
                    $row->embers = 0;
                    $row->embers_until = null;
                    $this->journal->write($userId, Journal::REVIVED, $row->current, ['staff' => true], $actor->id);
                    break;

                case 'reset':
                    $row->current = 0;
                    $row->started_on = null;
                    $row->embers = 0;
                    $row->embers_until = null;
                    $this->journal->write($userId, Journal::RESET, 0, null, $actor->id);
                    break;
            }

            $this->refresh($row);
            $row->save();

            return $row;
        });
    }

    private function light(Streak $row, int $length, string $today): void
    {
        $row->current = $length;
        $row->started_on = $length > 0 ? CarbonImmutable::parse($today)->subDays($length - 1)->toDateString() : null;
        $row->last_day = $length > 0 ? $today : $row->last_day;
        $row->day_ends_at = $length > 0 ? $this->endOf($row, $today) : $row->day_ends_at;
        $row->longest = max($row->longest, $length);
    }

    /** Inclusive count of days from one local date to another. */
    private function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }

    private function lock(int $userId): ?Streak
    {
        return Streak::query()->whereKey($userId)->lockForUpdate()->first();
    }
}
