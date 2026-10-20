<?php

namespace ErnestDefoe\Cadence\Api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Streak\Boards;
use ErnestDefoe\Cadence\Streak\Rules;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cadence/streaks-overview — the whole board at a glance, for admins:
 * key figures, flames by tier, the longest flames alight and recent events.
 */
class OverviewController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db, private Boards $boards, private Rules $rules)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $now = Carbon::now('UTC');
        $alight = fn () => $this->boards->alight($this->db->table('cadence_streaks'), $now);

        // One COUNT per tier band; a handful of indexed queries, admin-only.
        $tiers = array_values(array_filter($this->rules->tiers(), fn ($t) => $t['enabled']));
        $byTier = [];
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1]['days'] ?? null;
            $byTier[] = [
                'key' => $tier['key'],
                'name' => $tier['name'],
                'days' => $tier['days'],
                'color' => $tier['color'],
                'icon' => $tier['icon'],
                'count' => $alight()->where('current', '>=', $tier['days'])->when($next, fn ($q) => $q->where('current', '<', $next))->count(),
            ];
        }

        $events = $this->db->table('cadence_streak_journal')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(25)
            ->get(['user_id', 'type', 'value', 'data', 'actor_id', 'created_at']);
        $users = $this->boards->users($events->pluck('user_id')->merge($events->pluck('actor_id'))->filter()->unique()->all());

        $stats = $this->boards->stats();

        return new JsonResponse(['data' => [
            'figures' => [
                'tracked' => $this->db->table('cadence_streaks')->where('longest', '>', 0)->count(),
                'alight' => $stats['alight'],
                'fedToday' => $stats['fedToday'],
                'best' => $stats['best'],
                'freezes' => (int) $alight()->sum('freezes'),
                'embers' => $this->db->table('cadence_streaks')->where('embers', '>', 0)->where('embers_until', '>', $now->toDateTimeString())->count(),
            ],
            'bonfire' => $stats['bonfire'],
            'tiers' => $byTier,
            'longest' => $this->boards->board('current', $actor, 10),
            'events' => $events->map(fn ($e) => [
                'user' => $users[$e->user_id] ?? null,
                'actor' => $e->actor_id ? ($users[$e->actor_id] ?? null) : null,
                'type' => $e->type,
                'value' => (int) $e->value,
                'data' => $e->data ? json_decode($e->data, true) : null,
                'at' => Carbon::parse($e->created_at, 'UTC')->toIso8601String(),
            ])->all(),
        ]]);
    }
}
