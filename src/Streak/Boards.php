<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Recorder;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * The read side: boards, ranks, rivals, the bonfire and a member's calendar.
 *
 * Every query here is an indexed range or sort over `cadence_streaks` or the
 * journal; nothing aggregates posts.
 */
class Boards
{
    public const BOARDS = ['current', 'alltime', 'hall'];

    public function __construct(private ConnectionInterface $db, private Rules $rules, private SlugManager $slugs)
    {
    }

    /**
     * @return array<int, array{user: array, value: int, current: int, longest: int, rank: int, when?: string}>
     */
    public function board(string $board, User $actor, int $limit = 50, int $offset = 0): array
    {
        $now = Carbon::now('UTC');
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));

        if ($board === 'hall') {
            $rows = $this->db->table('cadence_streak_journal')
                ->where('type', Journal::ENDED)
                ->whereIn('user_id', $this->visibleIds($actor))
                ->orderByDesc('value')->orderBy('created_at')
                ->offset($offset)->limit($limit)
                ->get(['user_id', 'value', 'created_at']);

            $streaks = Streak::query()->whereIn('user_id', $rows->pluck('user_id')->unique())->get()->keyBy('user_id');
            $users = $this->users($rows->pluck('user_id')->unique()->all());

            $out = [];
            foreach ($rows as $i => $row) {
                $streak = $streaks->get($row->user_id);
                if (! isset($users[$row->user_id])) {
                    continue;
                }
                $out[] = [
                    'rank' => $offset + $i + 1,
                    'user' => $users[$row->user_id],
                    'value' => (int) $row->value,
                    'current' => $streak?->burning($now) ?? 0,
                    'longest' => $streak->longest ?? (int) $row->value,
                    'when' => Carbon::parse($row->created_at, 'UTC')->toIso8601String(),
                ];
            }

            return $out;
        }

        $query = Streak::query()->whereIn('user_id', $this->visibleIds($actor));

        if ($board === 'alltime') {
            $query->where('longest', '>', 0)->orderByDesc('longest')->orderByDesc('current');
        } else {
            $this->alight($query->getQuery(), $now);
            $query->orderByDesc('current')->orderByDesc('longest');
        }

        $rows = $query->orderBy('user_id')->offset($offset)->limit($limit)->get();
        $users = $this->users($rows->pluck('user_id')->all());

        $out = [];
        foreach ($rows->values() as $i => $row) {
            if (! isset($users[$row->user_id])) {
                continue;
            }
            $out[] = [
                'rank' => $offset + $i + 1,
                'user' => $users[$row->user_id],
                'value' => $board === 'alltime' ? $row->longest : $row->burning($now),
                'current' => $row->burning($now),
                'longest' => $row->longest,
            ];
        }

        return $out;
    }

    /**
     * Where a member stands: rank among flames alight, and the rival — the
     * member just ahead, which is the number that makes tomorrow matter.
     */
    public function standing(?Streak $row, User $actor): array
    {
        $now = Carbon::now('UTC');
        $mine = $row?->burning($now) ?? 0;

        if ($mine <= 0) {
            return ['rank' => null, 'rival' => null];
        }

        $above = fn () => $this->alight(Streak::query()->getQuery(), $now)
            ->whereIn('user_id', $this->visibleIds($actor))
            ->where('user_id', '!=', $row->user_id);

        $rank = $above()->where('current', '>', $mine)->count() + 1;

        // Level with you first (someone to keep up with), else the next above.
        $rival = $above()->where('current', '>=', $mine)->orderBy('current')->orderBy('user_id')->first(['user_id', 'current']);

        $user = $rival ? ($this->users([(int) $rival->user_id])[(int) $rival->user_id] ?? null) : null;

        return [
            'rank' => $rank,
            'rival' => $user ? ['user' => $user, 'ahead' => (int) $rival->current - $mine] : null,
        ];
    }

    /** Flames alight, fed today, best run on record, and the bonfire. */
    public function stats(): array
    {
        $now = Carbon::now('UTC');
        $todayStart = $now->copy()->setTimezone($this->rules->defaultTimezone())->startOfDay()->utc();

        $alight = $this->alight($this->db->table('cadence_streaks'), $now)->count();
        $fed = $this->db->table('cadence_streaks')->where('fed_at', '>=', $todayStart->toDateTimeString())->count();
        $best = (int) $this->db->table('cadence_streaks')->max('longest');

        $levels = $this->rules->bonfireLevels();
        $level = count(array_filter($levels, fn ($n) => $fed >= $n));
        $next = $levels[$level] ?? null;

        return [
            'alight' => $alight,
            'fedToday' => $fed,
            'best' => $best,
            'bonfire' => [
                'level' => $level,
                'levels' => count($levels),
                'toNext' => $next !== null ? $next - $fed : 0,
                // 0..1 across the current level, for the bar.
                'progress' => $next !== null ? round(($fed - ($levels[$level - 1] ?? 0)) / max(1, $next - ($levels[$level - 1] ?? 0)), 3) : 1,
            ],
        ];
    }

    /**
     * Every day in a window, in the member's own timezone: 1 = fed, 2 = saved
     * by a freeze. Sparse, like the activity map.
     *
     * @return array<string, int>
     */
    public function days(Streak|null $row, int $userId, string $tz, Carbon $from, Carbon $to, bool $visits = true): array
    {
        // Visits only in visit mode, and only when the member lets it be seen.
        $kinds = $this->rules->mode() === 'visit' && $visits ? [] : [Recorder::VISIT];

        $buckets = $this->db->table('cadence_activity')
            ->where('user_id', $userId)
            ->when($kinds, fn ($q) => $q->whereNotIn('kind', $kinds))
            ->where('count', '>', 0)
            ->where('bucket', '>=', $from->copy()->utc()->toDateTimeString())
            ->where('bucket', '<=', $to->copy()->utc()->toDateTimeString())
            ->distinct()->pluck('bucket');

        $days = [];
        foreach ($buckets as $bucket) {
            $days[Carbon::parse($bucket, 'UTC')->setTimezone($tz)->toDateString()] = 1;
        }

        $saved = $this->db->table('cadence_streak_journal')
            ->where('user_id', $userId)->where('type', Journal::SAVED)
            ->where('created_at', '>=', $from->copy()->utc()->toDateTimeString())
            ->pluck('data');

        foreach ($saved as $data) {
            foreach ((json_decode((string) $data, true)['days'] ?? []) as $day) {
                $days[$day] ??= 2;
            }
        }

        ksort($days);

        return $days;
    }

    public function alight(Builder $query, Carbon $now): Builder
    {
        return $query->where('current', '>', 0)->where('alive_until', '>=', $now->toDateTimeString());
    }

    /**
     * 🚨 Only members the viewer may see, asked through Flarum's own scope — a
     * leaderboard is otherwise a list of every account, suspended or hidden.
     */
    private function visibleIds(User $actor): Builder
    {
        return User::query()->whereVisibleTo($actor)->select('users.id')->toBase();
    }

    /** @return array<int, array{id: string, username: string, displayName: string, avatarUrl: ?string, slug: string}> */
    public function users(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(User::class);

        return User::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (User $user) => [$user->id => [
            'id' => (string) $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
            'slug' => $slugger->toSlug($user),
        ]])->all();
    }
}
