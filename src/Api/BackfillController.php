<?php

namespace ErnestDefoe\Cadence\Api;

use ErnestDefoe\Cadence\Backfill;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/cadence/backfill — the admin page's poll while a rebuild is
 * pending: how far it has got.
 *
 * When no queue job or scheduler is working on it right now, the poll itself
 * does up to two seconds of work, so a forum with neither still finishes while
 * the admin watches. Bounded: never the whole rebuild in one request.
 */
class BackfillController implements RequestHandlerInterface
{
    public function __construct(private Backfill $backfill)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // Returns at once with the state when a queue job or the scheduler
        // holds the lock; otherwise this poll moves the rebuild along.
        $state = $this->backfill->run(2);

        return new JsonResponse([
            'pending' => (bool) $state,
            'stage' => $state['stages'][0] ?? null,
            'percent' => Backfill::percent($state),
        ]);
    }
}
