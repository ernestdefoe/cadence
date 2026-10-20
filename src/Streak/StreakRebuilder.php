<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use ErnestDefoe\Cadence\Recorder;
use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds every member's flame from the activity Cadence already holds.
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
 */
class StreakRebuilder
{
    public function __construct(private ConnectionInterface $db, private Rules $rules, private Flames $flames)
    {
    }

    /** @return int members with a flame */
    public function rebuild(?callable $say = null): int
    {
        $say ??= fn (string $line) => null;

        $now = Carbon::now('UTC');
        $grace = $this->rules->grace();
        $hallMin = $this->rules->hallMin();
        $kinds = $this->rules->mode() === 'visit' ? null : [Recorder::VISIT];

        $timezones = $this->db->table('cadence_streaks')->whereNotNull('timezone')->pluck('timezone', 'user_id')->all();
        $default = $this->rules->defaultTimezone();

        /*
         * 🚨 Days a freeze saved are days the flame burned. They are in the
         * journal, not in the activity, and leaving them out would break every
         * run a freeze ever rescued.
         */
        $saved = [];
        foreach ($this->db->table('cadence_streak_journal')->where('type', Journal::SAVED)->get(['user_id', 'data']) as $entry) {
            foreach ((json_decode((string) $entry->data, true)['days'] ?? []) as $day) {
                $saved[(int) $entry->user_id][$day] = true;
            }
        }

        $say('Clearing the Hall of Flames…');
        $this->db->table('cadence_streak_journal')->where('type', Journal::ENDED)->delete();

        $say('Walking every member\'s days…');

        $members = 0;
        $userId = null;
        $days = [];

        /*
         * 🚨 One ordered cursor over the buckets, a member at a time, rather
         * than a query per member or every row in memory. A big forum has
         * millions of buckets; this holds one member's days at once.
         */
        $cursor = $this->db->table('cadence_activity')
            ->select(['user_id', 'bucket'])
            ->when($kinds, fn ($q) => $q->whereNotIn('kind', $kinds))
            ->where('count', '>', 0)
            ->orderBy('user_id')->orderBy('bucket')
            ->cursor();

        $flush = function () use (&$userId, &$days, &$members, $saved, $timezones, $default, $grace, $hallMin, $now) {
            if ($userId !== null && $days) {
                $days += $saved[$userId] ?? [];
                $this->store($userId, array_keys($days), $timezones[$userId] ?? $default, $grace, $hallMin, $now);
                $members++;
            }
            $days = [];
        };

        foreach ($cursor as $row) {
            if ((int) $row->user_id !== $userId) {
                $flush();
                $userId = (int) $row->user_id;
            }

            $tz = Rules::validTimezone($timezones[$userId] ?? null) ?? $default;
            $days[Carbon::parse($row->bucket, 'UTC')->setTimezone($tz)->toDateString()] = true;
        }

        $flush();

        return $members;
    }

    /** @param string[] $days ascending local dates */
    private function store(int $userId, array $days, string $tz, int $grace, int $hallMin, Carbon $now): void
    {
        sort($days);

        // Split into runs: a gap of more than `grace` missed days ends one.
        $runs = [];
        $start = $prev = $days[0];

        foreach (array_slice($days, 1) as $day) {
            $missed = (int) CarbonImmutable::parse($prev)->diffInDays(CarbonImmutable::parse($day)) - 1;

            if ($missed > $grace) {
                $runs[] = [$start, $prev];
                $start = $day;
            }
            $prev = $day;
        }
        $runs[] = [$start, $prev];

        $length = fn (array $run) => (int) CarbonImmutable::parse($run[0])->diffInDays(CarbonImmutable::parse($run[1])) + 1;
        $longest = max(array_map($length, $runs));

        $this->db->table('cadence_streaks')->insertOrIgnore(['user_id' => $userId]);
        $row = Streak::query()->find($userId);

        [$first, $last] = end($runs);
        $row->current = $length([$first, $last]);
        $row->started_on = $first;
        $row->last_day = $last;
        $row->longest = $longest;
        $row->total_days = count($days);

        // A new row starts with the freezes its current run's milestones earned.
        if (! $row->fed_at) {
            $crossed = count(array_filter($this->rules->milestones(), fn ($m) => $m <= $row->current));
            $row->freezes = min($this->rules->freezeBank(), $crossed * $this->rules->freezesPerMilestone());
            $row->fed_at = Carbon::parse($last, $tz)->utc();
        }

        $this->flames->refresh($row);

        // Runs that already ended belong in the Hall of Flames.
        $ended = $row->isAlight($now) ? array_slice($runs, 0, -1) : $runs;

        if (! $row->isAlight($now)) {
            $row->current = 0;
            $row->started_on = null;
        }

        $row->save();

        $rows = [];
        foreach ($ended as $run) {
            if ($length($run) >= $hallMin) {
                $rows[] = [
                    'user_id' => $userId,
                    'type' => Journal::ENDED,
                    'value' => $length($run),
                    'data' => json_encode(['from' => $run[0], 'to' => $run[1]]),
                    'created_at' => Carbon::parse($run[1], $tz)->addDay()->endOfDay()->utc()->toDateTimeString(),
                ];
            }
        }

        if ($rows) {
            $this->db->table('cadence_streak_journal')->insert($rows);
        }
    }
}
