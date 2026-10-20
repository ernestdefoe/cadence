<?php

namespace ErnestDefoe\Cadence\Console;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Streak\Flames;
use ErnestDefoe\Cadence\Streak\Rules;
use ErnestDefoe\Cadence\Streak\Streak;
use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Hourly: put out flames whose time ran out (leaving embers and a Hall of
 * Flames entry), let cold embers go, and warn members whose flame is at risk.
 *
 * 🚨 Nothing a reader sees depends on this running — `alive_until` already
 * makes an unfed flame read as out. A forum without a scheduler loses only the
 * alerts and email, never correctness.
 */
class SettleCommand extends AbstractCommand
{
    public function __construct(private Flames $flames, private Rules $rules, private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cadence:streaks:settle')
            ->setDescription('Settle Cadence streaks: put out unfed flames and send at-risk reminders.');
    }

    protected function fire(): int
    {
        if (! $this->rules->enabled()) {
            return 0;
        }

        $now = Carbon::now('UTC');
        $ended = 0;
        $warned = 0;

        Streak::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('current', '>', 0)->where('alive_until', '<', $now->toDateTimeString()))
                ->orWhere(fn ($q) => $q->where('embers', '>', 0)->where('embers_until', '<=', $now->toDateTimeString())))
            ->select('user_id')
            ->chunkById(200, function ($rows) use ($now, &$ended) {
                foreach ($rows as $stub) {
                    $embers = $this->db->transaction(function () use ($stub, $now) {
                        $row = Streak::query()->whereKey($stub->user_id)->lockForUpdate()->first();
                        $events = $row ? $this->flames->settle($row, $now) : [];
                        $row?->save();

                        return $events && $row->hasEmbers($now) ? $row : null;
                    });

                    $ended++;

                    if ($embers && ($user = User::query()->find($embers->user_id))) {
                        $this->flames->warn($user, 'embers', $embers->embers, $embers->embers_until->toDateString());
                    }
                }
            }, 'user_id');

        /*
         * At risk: alight, long enough to care about, not fed today in the
         * member's own day, and past the reminder hour there. The SQL narrows
         * to flames that could go out within a day; the timezone check is PHP.
         */
        Streak::query()
            ->where('current', '>=', $this->rules->riskMin())
            ->where('alive_until', '>=', $now->toDateTimeString())
            ->where('last_day', '<', $now->copy()->addDay()->toDateString())
            ->where('last_day', '>=', $now->copy()->subDays(3 + $this->rules->grace())->toDateString())
            ->chunkById(200, function ($rows) use ($now, &$warned) {
                foreach ($rows as $row) {
                    $local = $now->copy()->setTimezone($this->flames->tz($row));
                    $today = $local->toDateString();

                    if ($row->last_day >= $today || $row->reminded_on === $today || $local->hour < $this->rules->riskHour()) {
                        continue;
                    }

                    // Only when tonight would actually cost something: the
                    // flame going out, or a freeze melting.
                    $user = User::query()->find($row->user_id);
                    if (! $user || ! $user->hasPermission('cadence.earnStreaks')) {
                        continue;
                    }

                    $this->db->table('cadence_streaks')->where('user_id', $row->user_id)->update(['reminded_on' => $today]);
                    $this->flames->warn($user, 'risk', $row->current, $today);
                    $warned++;
                }
            }, 'user_id');

        $this->info("Settled {$ended} flames, sent {$warned} reminders.");

        return 0;
    }
}
