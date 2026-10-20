<?php

namespace ErnestDefoe\Cadence\Api;

use ErnestDefoe\Cadence\Streak\FlameAttribute;
use ErnestDefoe\Cadence\Streak\Flames;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/cadence/streaks/{id}/manage — the flame sheet's buttons.
 *
 * Body: {action: give|edit|revive|reset, value?: int, force?: bool}.
 * Admins only; every change is journaled with the admin who made it.
 */
class ManageController implements RequestHandlerInterface
{
    private const ACTIONS = ['give', 'edit', 'revive', 'reset'];

    public function __construct(private Flames $flames)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $user = User::query()->findOrFail((int) ($request->getQueryParams()['id'] ?? 0));
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        if (! in_array($action, self::ACTIONS, true)) {
            return new JsonResponse(['errors' => [['status' => '422', 'detail' => 'Unknown action.']]], 422);
        }

        $row = $this->flames->manage($user->id, $action, (int) ($body['value'] ?? 0), $actor, (bool) ($body['force'] ?? false));
        FlameAttribute::forget($user->id);

        return new JsonResponse(['data' => FlameAttribute::private($row)]);
    }
}
