<?php

namespace ErnestDefoe\Cadence\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/cadence/streak/ack — the toasts were shown; forget them. */
class AckController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $this->db->table('cadence_streaks')->where('user_id', $actor->id)->whereNotNull('celebrate')->update(['celebrate' => null]);

        return new EmptyResponse(204);
    }
}
