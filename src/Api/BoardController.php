<?php

namespace ErnestDefoe\Cadence\Api;

use ErnestDefoe\Cadence\Streak\Boards;
use ErnestDefoe\Cadence\Streak\Rules;
use ErnestDefoe\Cadence\Streak\Streak;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cadence/streaks — a board, plus where the viewer stands on it.
 *
 * `?board=current|alltime|hall&offset=&limit=`; `?stats=0` skips the sidebar
 * figures when only the list is wanted (the Top flames widget).
 */
class BoardController implements RequestHandlerInterface
{
    public function __construct(private Boards $boards, private Rules $rules)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('cadence.viewStreaks');

        if (! $this->rules->enabled()) {
            return new JsonResponse(['errors' => [['status' => '404']]], 404);
        }

        $query = $request->getQueryParams();
        $board = in_array($query['board'] ?? '', Boards::BOARDS, true) ? $query['board'] : 'current';
        $limit = (int) ($query['limit'] ?? 50);
        $offset = (int) ($query['offset'] ?? 0);

        $data = ['board' => $board, 'entries' => $this->boards->board($board, $actor, $limit, $offset)];

        if (($query['stats'] ?? '1') !== '0') {
            $data['stats'] = $this->boards->stats();
        }

        if (! $actor->isGuest() && ($query['me'] ?? '1') !== '0') {
            $data['me'] = $this->boards->standing(Streak::query()->find($actor->id), $actor);
        }

        return new JsonResponse(['data' => $data]);
    }
}
