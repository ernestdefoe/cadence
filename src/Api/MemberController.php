<?php

namespace ErnestDefoe\Cadence\Api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Streak\Boards;
use ErnestDefoe\Cadence\Streak\FlameAttribute;
use ErnestDefoe\Cadence\Streak\Flames;
use ErnestDefoe\Cadence\Streak\Journal;
use ErnestDefoe\Cadence\Streak\Rules;
use ErnestDefoe\Cadence\Streak\Streak;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cadence/streaks/{id} — one member's flame: the card, their standing,
 * the days they fed (and the days a freeze saved) for a year or a month, and —
 * to themselves and to admins only — their journal.
 *
 * `?year=2026` for the year grid, or `?month=2026-10` for the calendar.
 */
class MemberController implements RequestHandlerInterface
{
    public function __construct(private Boards $boards, private Flames $flames, private Journal $journal, private Rules $rules)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('cadence.viewStreaks');

        $query = $request->getQueryParams();
        $user = User::query()->whereVisibleTo($actor)->find((int) ($query['id'] ?? 0));

        // 404, not 403: "you may not see them" confirms they exist.
        if (! $user || ! $this->rules->enabled()) {
            return new JsonResponse(['errors' => [['status' => '404']]], 404);
        }

        $row = Streak::query()->find($user->id);
        $tz = $this->flames->tz($row);
        $now = Carbon::now($tz);

        if (preg_match('/^(\d{4})-(\d{2})$/', (string) ($query['month'] ?? ''), $m)) {
            $from = Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $tz);
            $to = $from->copy()->endOfMonth();
        } else {
            $year = (int) ($query['year'] ?? $now->year);
            $year = max(2000, min($now->year, $year));
            $from = Carbon::create($year, 1, 1, 0, 0, 0, $tz);
            $to = $from->copy()->endOfYear();
        }

        $own = $actor->id === $user->id || $actor->isAdmin();
        $flame = FlameAttribute::private($row);

        $data = [
            'userId' => (string) $user->id,
            'current' => $flame['current'],
            'longest' => $flame['longest'],
            'total' => $flame['total'],
            'startedOn' => $flame['startedOn'],
            'freezes' => $flame['freezes'],
            'today' => $now->toDateString(),
            'firstYear' => $user->joined_at ? max(2000, (int) Carbon::instance($user->joined_at)->setTimezone($tz)->year) : $now->year,
            'standing' => $this->boards->standing($row, $actor),
            /*
             * 🚨 In visit mode a fed day is a day they were HERE. A member who
             * hid their online status ("discloseOnline" off) has said when they
             * visit is nobody else's business, so others see only the days they
             * contributed. Their own flame still counts every visit.
             */
            'days' => $this->boards->days($row, $user->id, $tz, $from, $to, $own || (bool) $user->getPreference('discloseOnline')),
        ];

        if ($own) {
            $data['embers'] = $flame['embers'];
            $data['embersUntil'] = $flame['embersUntil'];
            $data['journal'] = $this->journal->recent($user->id, 40);
        }

        return new JsonResponse(['data' => $data]);
    }
}
