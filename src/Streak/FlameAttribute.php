<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use Closure;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The flame that rides along on every serialized user.
 *
 * 🚨 Same contract as Spark: a flame shows beside every post author, so it may
 * not cost a query per author. The getter notes the member and returns a
 * closure; the first closure the serializer resolves loads every noted member
 * in ONE query. A page of thirty posts by twelve people is one query.
 */
abstract class FlameAttribute
{
    /** @var array<int, Streak|false> */
    private static array $memo = [];

    /** @var array<int, true> */
    private static array $pending = [];

    /** Public: what anyone who may see streaks sees. */
    public static function for(User $user): Closure|array|null
    {
        return self::defer($user, fn (?Streak $row) => $row && $row->longest > 0 ? self::public($row) : null);
    }

    /** Private: only ever serialized for the member themselves. */
    public static function self(User $user): Closure|array|null
    {
        return self::defer($user, fn (?Streak $row) => self::private($row));
    }

    private static function defer(User $user, Closure $shape): Closure|array|null
    {
        $id = (int) $user->id;

        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, self::$memo)) {
            return $shape(self::$memo[$id] ?: null);
        }

        self::$pending[$id] = true;

        return function () use ($id, $shape) {
            if (! array_key_exists($id, self::$memo)) {
                self::load();
            }

            return $shape(self::$memo[$id] ?: null);
        };
    }

    private static function load(): void
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Streak::query()->whereIn('user_id', $chunk)->get()->keyBy('user_id');

            foreach ($chunk as $id) {
                self::$memo[$id] = $rows->get($id) ?? false;
            }
        }
    }

    public static function public(Streak $row): array
    {
        return ['current' => $row->burning(), 'longest' => $row->longest];
    }

    public static function private(?Streak $row): array
    {
        $now = Carbon::now('UTC');

        return [
            'current' => $row?->burning($now) ?? 0,
            'longest' => $row->longest ?? 0,
            'total' => $row->total_days ?? 0,
            'startedOn' => $row?->isAlight($now) ? $row->started_on : null,
            'lastDay' => $row?->last_day,
            'freezes' => $row->freezes ?? 0,
            'embers' => $row?->hasEmbers($now) ? $row->embers : 0,
            'embersUntil' => $row?->hasEmbers($now) ? $row->embers_until->toIso8601String() : null,
            'aliveUntil' => $row?->isAlight($now) ? $row->alive_until->toIso8601String() : null,
            'timezone' => $row?->timezone,
            'celebrate' => $row?->celebrate ? json_decode($row->celebrate, true) : [],
        ];
    }

    /** Forget a member so the next serialization reads their fresh row. */
    public static function forget(int $id): void
    {
        unset(self::$memo[$id]);
    }

    public static function reset(): void
    {
        self::$memo = [];
        self::$pending = [];
    }
}
