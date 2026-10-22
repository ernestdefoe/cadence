<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Recorder;
use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds members' flames from the activity Cadence already holds.
 *
 * This is the thing XenStreak cannot do: it counts visits, which no forum keeps
 * a history of, so every member starts at zero. Cadence's hourly buckets ARE a
 * history, so a member who has posted every day for two years gets a two-year
 * flame the day this is installed.
 *
 * Kept: each member's timezone, freezes and embers. Rebuilt: current, longest,
 * total days, and the Hall of Flames (the runs that already ended). Not
 * rebuilt: the rest of the journal — milestones nobody saw happen are not
 * moments, and back-dating a hundred of them would bury the real ones.
 *
 * 🚨 A slice of members at a time, and a slice is a fixed number of queries
 * whatever its size: one grouped read of the activity, one of the rows, one of
 * the saved days, one upsert, one delete and one insert for the Hall. It used to
 * be an Eloquent find + save per member inside a migration, which on a forum of
 * 568k posts outlived the "enable" request (Discuss #15). See Backfill.
 */
class StreakRebuilder
{
    /** @var array<string, int> UTC hour or date → unix time */
    private array $epoch = [];

    /** @var array<string, array<int, int>> timezone → UTC hour → offset in seconds */
    private array $offsets = [];

    /** @var array<string, \DateTimeZone> */
    private array $zones = [];

    /** @var array<string, string> raw timezone → the one used */
    private array $tzMemo = [];

    public function __construct(private ConnectionInterface $db, private Rules $rules, private Flames $flames)
    {
    }

    /**
     * Every member, a slice at a time. For tests and small forums; the
     * Backfill is what walks a big one in the background.
     *
     * @return int members with a flame
     */
    public function rebuild(int $size = 500): int
    {
        $members = 0;
        $after = 0;

        while ($ids = $this->db->table('users')->where('id', '>', $after)->orderBy('id')->limit($size)->pluck('id')->map(fn ($id) => (int) $id)->all()) {
            $members += $this->rebuildUsers($ids);
            $after = end($ids);
        }

        return $members;
    }

    /**
     * Rebuild these members' flames. Safe to repeat: the same activity gives
     * the same rows, and the slice's Hall of Flames is replaced, not added to.
     *
     * @param  int[]  $ids  members that exist
     * @return int members with a flame
     */
    public function rebuildUsers(array $ids, ?Carbon $now = null): int
    {
        if (! $ids) {
            return 0;
        }

        $now ??= Carbon::now('UTC');
        $grace = $this->rules->grace();
        $hallMin = $this->rules->hallMin();
        $default = $this->rules->defaultTimezone();

        /*
         * One grouped read: per member and UTC date, the first and last active
         * hour. A day is a day in the MEMBER's timezone, so the database cannot
         * pick the date for us — but a UTC date is 24 hours, which fall on at
         * most two local dates, and its first and last active hours land on
         * exactly those. So two hours per UTC date decide the local days, and
         * for a member on UTC they are the same date. (The one exception, a
         * half-hour zone on its daylight-saving night, can only miss a day
         * that both its neighbours already count.)
         *
         * 🚨 Raw SQL is passed through verbatim, so the column is wrapped by
         * the grammar; DATE() is the same function on MySQL, MariaDB,
         * PostgreSQL and SQLite. Streamed, not collected: the first slice holds
         * a forum's heaviest posters, and a slice of objects was 100 MB.
         */
        $bucket = $this->db->getQueryGrammar()->wrap('bucket');
        $query = $this->db->table('cadence_activity')
            ->whereIn('user_id', $ids)
            ->where('count', '>', 0)
            ->selectRaw($this->db->getQueryGrammar()->wrap('user_id').", DATE($bucket) AS utc_day, MIN($bucket) AS first_hour, MAX($bucket) AS last_hour")
            ->groupBy('user_id')
            ->groupByRaw("DATE($bucket)");

        // Visits only keep a flame alive when the admin chose visiting.
        if ($this->rules->mode() !== 'visit') {
            $query->where('kind', '!=', Recorder::VISIT);
        }

        $existing = $this->db->table('cadence_streaks')->whereIn('user_id', $ids)->pluck('timezone', 'user_id')->all();

        $days = [];
        foreach ($query->cursor() as $row) {
            $id = (int) $row->user_id;
            $tz = $this->tz($existing[$id] ?? null, $default);

            if ($tz === 'UTC') {
                $days[$id][$this->dayNumber((string) $row->utc_day)] = true;
            } else {
                $days[$id][$this->localDay((string) $row->first_hour, $tz)] = true;
                $days[$id][$this->localDay((string) $row->last_hour, $tz)] = true;
            }
        }

        return $this->db->transaction(function () use ($ids, $days, $grace, $hallMin, $default, $now) {
            // Locked, so a reply landing mid-slice waits rather than being overwritten.
            $existing = $this->db->table('cadence_streaks')->whereIn('user_id', $ids)->lockForUpdate()
                ->get(['user_id', 'timezone', 'freezes', 'fed_at'])->keyBy('user_id');

            /*
             * 🚨 Days a freeze saved are days the flame burned. They are in the
             * journal, not in the activity, and leaving them out would break
             * every run a freeze ever rescued.
             */
            $saved = [];
            foreach ($this->db->table('cadence_streak_journal')->whereIn('user_id', $ids)->where('type', Journal::SAVED)->get(['user_id', 'data']) as $entry) {
                foreach ((json_decode((string) $entry->data, true)['days'] ?? []) as $day) {
                    $saved[(int) $entry->user_id][$this->dayNumber($day)] = true;
                }
            }

            $rows = [];
            $hall = [];

            foreach ($ids as $id) {
                $have = $existing[$id] ?? null;
                $tz = $this->tz($have->timezone ?? null, $default);
                $fed = ($days[$id] ?? []) + ($saved[$id] ?? []);

                if (! $fed) {
                    continue;
                }

                [$row, $ended] = $this->flame($id, array_keys($fed), $tz, $have, $grace, $now);
                $rows[] = $row;

                foreach ($ended as [$from, $to]) {
                    if ($to - $from + 1 >= $hallMin) {
                        $hall[] = [
                            'user_id' => $id,
                            'type' => Journal::ENDED,
                            'value' => $to - $from + 1,
                            'data' => json_encode(['from' => self::date($from), 'to' => self::date($to)]),
                            'created_at' => Carbon::parse(self::date($to), $tz)->addDay()->endOfDay()->utc()->toDateTimeString(),
                        ];
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $this->db->table('cadence_streaks')->upsert($chunk, ['user_id'], array_keys(array_diff_key($chunk[0], ['user_id' => 0])));
            }

            // The slice's Hall of Flames is replaced, so a repeated slice adds nothing.
            $this->db->table('cadence_streak_journal')->whereIn('user_id', $ids)->where('type', Journal::ENDED)->delete();
            foreach (array_chunk($hall, 500) as $chunk) {
                $this->db->table('cadence_streak_journal')->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * One member's row, and the runs that already ended.
     *
     * @param  int[]  $days  local days as day numbers
     * @return array{0: array<string, mixed>, 1: array<int, array{0: int, 1: int}>}
     */
    private function flame(int $id, array $days, string $tz, ?object $have, int $grace, Carbon $now): array
    {
        sort($days, SORT_NUMERIC);

        // Split into runs: a gap of more than `grace` missed days ends one.
        $runs = [];
        $start = $prev = $days[0];
        $longest = 0;

        foreach ($days as $day) {
            if ($day - $prev - 1 > $grace) {
                $runs[] = [$start, $prev];
                $longest = max($longest, $prev - $start + 1);
                $start = $day;
            }
            $prev = $day;
        }
        $runs[] = [$start, $prev];
        $longest = max($longest, $prev - $start + 1);

        $current = $prev - $start + 1;
        $last = self::date($prev);
        $dayEnds = Carbon::parse($last, $tz)->endOfDay()->utc();

        // A new row starts with the freezes its current run's milestones earned.
        if ($have?->fed_at) {
            $freezes = (int) $have->freezes;
            $fedAt = (string) $have->fed_at;
        } else {
            $crossed = count(array_filter($this->rules->milestones(), fn ($m) => $m <= $current));
            $freezes = min($this->rules->freezeBank(), $crossed * $this->rules->freezesPerMilestone());
            $fedAt = Carbon::parse($last, $tz)->utc()->toDateTimeString();
        }

        $aliveUntil = $this->flames->aliveUntil($dayEnds, $freezes);
        $alight = $aliveUntil->gte($now);

        // 🚨 Strings cut to the second: a DATETIME column ROUNDS 23:59:59.999999
        // up to the next day on MySQL.
        return [[
            'user_id' => $id,
            'current' => $alight ? $current : 0,
            'longest' => $longest,
            'total_days' => count($days),
            'started_on' => $alight ? self::date($start) : null,
            'last_day' => $last,
            'day_ends_at' => $dayEnds->toDateTimeString(),
            'alive_until' => $aliveUntil->toDateTimeString(),
            'freezes' => $freezes,
            'fed_at' => $fedAt,
        ], $alight ? array_slice($runs, 0, -1) : $runs];
    }

    /** The member's local day an hour bucket falls on, as a day number. */
    private function localDay(string $bucket, string $tz): int
    {
        $ts = $this->epoch[$bucket] ??= (int) strtotime($bucket.' UTC');
        $hour = intdiv($ts, 3600);
        $offset = $this->offsets[$tz][$hour] ??= ($this->zones[$tz] ??= new \DateTimeZone($tz))->getOffset(new \DateTimeImmutable('@'.$ts));

        return intdiv($ts + $offset, 86400);
    }

    private function tz(?string $raw, string $default): string
    {
        return $this->tzMemo[$raw ?? ''] ??= Rules::validTimezone($raw) ?? $default;
    }

    /** Days since 1970-01-01: whole numbers make "the next day" a + 1. */
    private function dayNumber(string $date): int
    {
        return intdiv($this->epoch[$date] ??= (int) strtotime(substr($date, 0, 10).' 00:00:00 UTC'), 86400);
    }

    private static function date(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }
}
