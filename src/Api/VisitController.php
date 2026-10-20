<?php

namespace ErnestDefoe\Cadence\Api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Recorder;
use ErnestDefoe\Cadence\Streak\FlameAttribute;
use ErnestDefoe\Cadence\Streak\Flames;
use ErnestDefoe\Cadence\Streak\Rules;
use ErnestDefoe\Cadence\Streak\Streak;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/cadence/streak/visit — the browser checking in.
 *
 * 🚨 Called at most once per local day, by the member's own browser, and only
 * when there is something to say: in visit mode when today has not been fed
 * yet, or in any mode when the browser's timezone differs from the one stored.
 * That is the whole cost of visit streaks — no middleware on every request,
 * no write on every page.
 */
class VisitController implements RequestHandlerInterface
{
    public function __construct(private Flames $flames, private Rules $rules, private Recorder $recorder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = (array) $request->getParsedBody();

        if ($tz = Rules::validTimezone((string) ($body['timezone'] ?? ''))) {
            $this->flames->setTimezone($actor->id, $tz);
        }

        $events = [];

        if ($this->rules->mode() === 'visit') {
            $events = $this->flames->feed($actor->id, false);

            // Today's first visit is a square on the streak calendar.
            if ($events) {
                $this->recorder->record($actor->id, Carbon::now('UTC'), Recorder::VISIT);
            }
        }

        FlameAttribute::forget($actor->id);

        return new JsonResponse(['data' => [
            'streak' => FlameAttribute::private(Streak::query()->find($actor->id)),
            'events' => $events,
        ]]);
    }
}
