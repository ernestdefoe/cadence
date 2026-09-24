<?php

namespace ErnestDefoe\Cadence\Api;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cadence/{id} — one member's map.
 *
 * Reads only `cadence_activity`, never `posts`. The whole point of the rollup
 * is that a profile view costs one indexed range scan over a member's own rows.
 */
class MapController implements RequestHandlerInterface
{
    /** Never serve more than this, whatever the query string says. */
    private const MAX_WEEKS = 106;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();

        $id = (int) ($query['id'] ?? 0);
        $user = User::query()->whereVisibleTo($actor)->find($id);

        /*
         * 🚨 `whereVisibleTo`, and a 404 rather than a 403 for a member the
         * actor may not see. Telling someone "you are not allowed to see this
         * user" confirms the user exists, which on a forum with private or
         * suspended accounts is itself the leak.
         */
        if (! $user) {
            return new JsonResponse(['errors' => [['status' => '404']]], 404);
        }

        $weeks = max(4, min(self::MAX_WEEKS, (int) ($query['weeks'] ?? 53)));

        /*
         * Minutes east of UTC, as JavaScript's `-new Date().getTimezoneOffset()`
         * gives it. Clamped to the range real zones occupy so a hand-typed
         * query string cannot shift the window into a different year.
         */
        $offset = max(-840, min(840, (int) ($query['tz'] ?? 0)));

        $end = Carbon::now('UTC')->addMinutes($offset)->endOfDay()->subMinutes($offset);
        $start = (clone $end)->subWeeks($weeks)->startOfDay();

        /*
         * 🚨 The member's own join date wins when it is later. GitHub shows a
         * year of grey for someone who arrived last month, which on a forum —
         * where the map is how you size up a stranger — reads as "inactive"
         * when the truth is "new". Those are opposite conclusions about the
         * same person.
         */
        $joined = $user->joined_at ? Carbon::instance($user->joined_at)->utc()->startOfDay() : null;

        if ($joined && $joined->greaterThan($start)) {
            $start = $joined;
        }

        $rows = $this->db->table('cadence_activity')
            ->select('bucket', 'kind', 'count')
            ->where('user_id', $user->id)
            ->where('bucket', '>=', $start->toDateTimeString())
            ->where('bucket', '<=', $end->toDateTimeString())
            ->get();

        $days = [];
        $hours = array_fill(0, 168, 0);

        foreach ($rows as $row) {
            $local = Carbon::parse($row->bucket, 'UTC')->addMinutes($offset);
            $date = $local->toDateString();
            $count = (int) $row->count;

            $days[$date] ??= [];
            $days[$date][$row->kind] = ($days[$date][$row->kind] ?? 0) + $count;

            /*
             * Hour of the week, 0 = Monday 00:00, in the viewer's offset. Free
             * from the same rows — the reason the table is hourly.
             */
            $hours[(((int) $local->dayOfWeekIso - 1) * 24) + (int) $local->hour] += $count;
        }

        return new JsonResponse([
            'data' => [
                'userId' => (int) $user->id,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'joinedAt' => $joined?->toDateString(),
                // Sparse: only days with something on them. A member active on
                // 200 days is 200 entries, not 371 mostly-empty ones.
                'days' => $days,
                'hours' => $hours,
            ],
        ]);
    }
}
