<?php

namespace ErnestDefoe\Cadence\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Spark;
use ErnestDefoe\Cadence\Streak\FlameAttribute;
use ErnestDefoe\Cadence\Streak\Flames;
use ErnestDefoe\Cadence\Streak\StreakRebuilder;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class StreaksTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Spark::reset();
        FlameAttribute::reset();

        $this->extension('flarum-likes', 'ernestdefoe-cadence');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYears(2)],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYears(2)],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'postWithoutThrottle'],
            ],
        ]);
    }

    private function json(string $method, string $path, ?int $actor = null, ?array $body = null, array $query = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->send($this->request($method, $path, $options)->withQueryParams($query));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function reply(int $actor): void
    {
        [$status] = $this->json('POST', '/api/posts', $actor, ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]);
        $this->assertSame(201, $status);
    }

    private function row(int $userId): ?object
    {
        return $this->database()->table('cadence_streaks')->where('user_id', $userId)->first();
    }

    /** A flame as if it had been fed `$length` days ending `$lastDay` days ago. */
    private function flame(int $userId, int $length, int $daysAgo, int $freezes = 0): void
    {
        $last = Carbon::now('UTC')->subDays($daysAgo)->toDateString();
        $this->database()->table('cadence_streaks')->updateOrInsert(['user_id' => $userId], [
            'current' => $length,
            'longest' => $length,
            'total_days' => $length,
            'started_on' => Carbon::parse($last)->subDays($length - 1)->toDateString(),
            'last_day' => $last,
            'day_ends_at' => Carbon::parse($last, 'UTC')->endOfDay()->toDateTimeString(),
            'freezes' => $freezes,
            'timezone' => 'UTC',
            'alive_until' => Carbon::parse($last, 'UTC')->addDays(1 + $freezes)->endOfDay()->toDateTimeString(),
            'fed_at' => Carbon::parse($last, 'UTC')->setTime(12, 0)->toDateTimeString(),
        ]);
    }

    private function journal(int $userId): array
    {
        return $this->database()->table('cadence_streak_journal')->where('user_id', $userId)->orderBy('id')->pluck('value', 'type')->all();
    }

    #[Test]
    public function history_becomes_a_flame_on_install()
    {
        $rows = [];
        foreach ([0, 1, 2, 3, 4, 9, 10, 11, 12, 13, 14, 15, 16, 17] as $daysAgo) {
            $rows[] = ['user_id' => 4, 'bucket' => Carbon::now('UTC')->subDays($daysAgo)->setTime(12, 0)->toDateTimeString(), 'kind' => 'reply', 'count' => 1];
        }
        // A member whose account is gone; their activity stays behind.
        $rows[] = ['user_id' => 99, 'bucket' => Carbon::now('UTC')->setTime(12, 0)->toDateTimeString(), 'kind' => 'reply', 'count' => 1];
        $this->prepareDatabase(['cadence_activity' => $rows]);
        $this->app()->getContainer()->make(StreakRebuilder::class)->rebuild();

        $row = $this->row(4);
        $this->assertSame(5, (int) $row->current, 'Today and the four days before');
        $this->assertSame(9, (int) $row->longest, 'The earlier nine-day run');
        $this->assertSame(14, (int) $row->total_days);
        $this->assertSame(['ended' => 9], $this->journal(4), 'The ended run is in the Hall of Flames');
        $this->assertNull($this->row(99), 'A deleted account is skipped, not fatal');
    }

    #[Test]
    public function posting_feeds_the_flame_once_a_day()
    {
        $this->reply(2);
        $this->reply(2);

        $row = $this->row(2);
        $this->assertSame(1, (int) $row->current);
        $this->assertSame(1, (int) $row->total_days);
        $this->assertSame(Carbon::now('UTC')->toDateString(), $row->last_day);
    }

    #[Test]
    public function a_freeze_melts_to_save_a_missed_day()
    {
        $this->app();
        $this->flame(2, 10, 2, freezes: 1);

        $this->reply(2);

        $row = $this->row(2);
        $this->assertSame(12, (int) $row->current, 'The saved day counts');
        $this->assertSame(0, (int) $row->freezes);
        $this->assertSame(1, $this->journal(2)['saved'] ?? null);
    }

    #[Test]
    public function a_long_flame_that_went_out_is_revived_at_half_by_a_post()
    {
        $this->app();
        // Last fed three days ago: out since the end of the day before
        // yesterday, so its 48 hours of embers run to the end of today.
        $this->flame(2, 20, 3);

        $this->reply(2);

        $row = $this->row(2);
        $this->assertSame(10, (int) $row->current);
        $this->assertSame(20, (int) $row->longest);
        $this->assertSame(0, (int) $row->embers);
        $journal = $this->journal(2);
        $this->assertSame(20, $journal['ended']);
        $this->assertSame(10, $journal['revived']);
    }

    #[Test]
    public function cold_embers_cannot_be_revived()
    {
        $this->app();
        $this->flame(2, 20, 6);

        $this->reply(2);

        $this->assertSame(1, (int) $this->row(2)->current);
    }

    #[Test]
    public function a_milestone_earns_a_freeze_a_tier_and_an_alert()
    {
        $this->app();
        $this->flame(2, 6, 1);

        $this->reply(2);

        $row = $this->row(2);
        $this->assertSame(7, (int) $row->current);
        $this->assertSame(1, (int) $row->freezes);
        $this->assertArrayNotHasKey('saved', $this->journal(2), 'Yesterday to today is not a gap');
        $journal = $this->journal(2);
        $this->assertSame(7, $journal['milestone']);
        $this->assertSame(7, $journal['tier']);
        $this->assertGreaterThan(0, $this->database()->table('notifications')->where('user_id', 2)->where('type', 'cadenceStreakMoment')->count());

        $moments = array_column(json_decode($row->celebrate, true), 'type');
        $this->assertContains('milestone', $moments);
        $this->assertContains('day', $moments);
    }

    #[Test]
    public function visits_count_only_in_visit_mode()
    {
        [$status, $body] = $this->json('POST', '/api/cadence/streak/visit', 2, ['timezone' => 'Australia/Sydney']);
        $this->assertSame(200, $status);
        $this->assertSame(0, $body['data']['streak']['current']);
        $this->assertSame('Australia/Sydney', $this->row(2)->timezone, 'The timezone is remembered either way');

        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('ernestdefoe-cadence.streak_mode', 'visit');
        [, $body] = $this->json('POST', '/api/cadence/streak/visit', 2);
        $this->assertSame(1, $body['data']['streak']['current']);
        $this->assertSame(Carbon::now('Australia/Sydney')->toDateString(), $this->row(2)->last_day, "The member's own day");
        $this->assertSame(1, $this->database()->table('cadence_activity')->where('user_id', 2)->where('kind', 'visit')->count());

        // Others see the visit day only while the member discloses being online.
        [, $theirs] = $this->json('GET', '/api/cadence/streaks/2', 3, null, ['month' => Carbon::now('Australia/Sydney')->format('Y-m')]);
        $this->assertNotEmpty($theirs['data']['days']);
        $this->database()->table('users')->where('id', 2)->update(['preferences' => json_encode(['discloseOnline' => false])]);
        [, $theirs] = $this->json('GET', '/api/cadence/streaks/2', 3, null, ['month' => Carbon::now('Australia/Sydney')->format('Y-m')]);
        $this->assertSame([], $theirs['data']['days'], 'A hidden online status hides visit days');
        [, $mine] = $this->json('GET', '/api/cadence/streaks/2', 2, null, ['month' => Carbon::now('Australia/Sydney')->format('Y-m')]);
        $this->assertNotEmpty($mine['data']['days'], 'The member still sees their own');

        // A visit is not activity: the map does not draw it.
        [, $map] = $this->json('GET', '/api/cadence/2', 2);
        $this->assertSame([], $map['data']['days']);
    }

    #[Test]
    public function guests_cannot_check_in()
    {
        [$status] = $this->json('POST', '/api/cadence/streak/visit');
        $this->assertContains($status, [400, 401], 'Refused by CSRF or by assertRegistered, never fed');
        $this->assertSame(0, $this->database()->table('cadence_streaks')->count());
    }

    #[Test]
    public function the_board_ranks_flames_alight_and_names_your_rival()
    {
        $this->app();
        $this->flame(2, 5, 0);
        $this->flame(3, 9, 0);
        $this->flame(4, 30, 6); // went out

        [$status, $body] = $this->json('GET', '/api/cadence/streaks', 2);
        $this->assertSame(200, $status);
        $this->assertSame(['carol', 'normal'], array_column(array_column($body['data']['entries'], 'user'), 'username'));
        $this->assertSame(2, $body['data']['me']['rank']);
        $this->assertSame('carol', $body['data']['me']['rival']['user']['username']);
        $this->assertSame(4, $body['data']['me']['rival']['ahead']);
        $this->assertSame(2, $body['data']['stats']['alight']);

        [, $body] = $this->json('GET', '/api/cadence/streaks', null, null, ['board' => 'alltime']);
        $this->assertSame('dave', $body['data']['entries'][0]['user']['username']);
        $this->assertSame(0, $body['data']['entries'][0]['current']);
    }

    #[Test]
    public function the_flame_rides_along_and_the_private_half_only_to_its_owner()
    {
        $this->app();
        $this->flame(3, 9, 0, freezes: 2);

        [, $body] = $this->json('GET', '/api/users/3', 2);
        $this->assertSame(['current' => 9, 'longest' => 9], $body['data']['attributes']['cadenceStreak']);
        $this->assertArrayNotHasKey('cadenceFlame', $body['data']['attributes']);

        [, $body] = $this->json('GET', '/api/users/3', 3);
        $this->assertSame(2, $body['data']['attributes']['cadenceFlame']['freezes']);
    }

    #[Test]
    public function a_dead_flame_reads_as_out_without_the_scheduler()
    {
        $this->app();
        $this->flame(3, 9, 4);

        [, $body] = $this->json('GET', '/api/users/3', 2);
        $this->assertSame(0, $body['data']['attributes']['cadenceStreak']['current']);
    }

    #[Test]
    public function the_settle_job_puts_out_flames_and_leaves_embers()
    {
        $this->app();
        $this->flame(3, 20, 3);

        $output = new BufferedOutput();
        $this->app()->getContainer()->make(\ErnestDefoe\Cadence\Console\SettleCommand::class)->run(new ArrayInput([]), $output);

        $row = $this->row(3);
        $this->assertSame(0, (int) $row->current);
        $this->assertSame(20, (int) $row->embers);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'cadenceStreakWarning')->count());
    }

    #[Test]
    public function only_admins_can_manage_a_flame()
    {
        [$status] = $this->json('POST', '/api/cadence/streaks/3/manage', 2, ['action' => 'give', 'value' => 2]);
        $this->assertSame(403, $status);

        [$status, $body] = $this->json('POST', '/api/cadence/streaks/3/manage', 1, ['action' => 'give', 'value' => 2]);
        $this->assertSame(200, $status);
        $this->assertSame(2, $body['data']['freezes']);

        [, $body] = $this->json('POST', '/api/cadence/streaks/3/manage', 1, ['action' => 'edit', 'value' => 40]);
        $this->assertSame(40, $body['data']['current']);
        $this->assertSame(1, $this->database()->table('cadence_streak_journal')->where('user_id', 3)->where('type', 'edited')->where('actor_id', 1)->count());
    }

    #[Test]
    public function the_member_endpoint_shows_the_journal_only_to_its_owner()
    {
        $this->app();
        $this->flame(3, 6, 1);
        $this->app()->getContainer()->make(Flames::class)->feed(3, true);

        [, $theirs] = $this->json('GET', '/api/cadence/streaks/3', 2);
        $this->assertArrayNotHasKey('journal', $theirs['data']);
        $this->assertSame(7, $theirs['data']['current']);

        [, $mine] = $this->json('GET', '/api/cadence/streaks/3', 3, null, ['month' => Carbon::now('UTC')->format('Y-m')]);
        $this->assertNotEmpty($mine['data']['journal']);
    }

    #[Test]
    public function changing_timezone_neither_adds_a_day_nor_moves_the_deadline()
    {
        $this->app();
        // Fed today in UTC, at noon.
        $noon = Carbon::now('UTC')->setTime(12, 0);
        $this->flame(2, 10, 0);
        $before = $this->row(2);
        $flames = $this->app()->getContainer()->make(Flames::class);

        // Hop to UTC+14, where it is already tomorrow, and post: still today.
        $flames->setTimezone(2, 'Pacific/Kiritimati');
        $flames->feed(2, true, $noon);
        $this->assertSame(10, (int) $this->row(2)->current, 'Tomorrow does not arrive early');
        $this->assertSame($before->alive_until, $this->row(2)->alive_until, 'The deadline did not move');

        // Hop to UTC-11 early in the UTC day, where it is still yesterday.
        $flames->setTimezone(2, 'Pacific/Pago_Pago');
        $flames->feed(2, true, Carbon::now('UTC')->setTime(5, 0));
        $this->assertSame(10, (int) $this->row(2)->current, 'Yesterday cannot shrink or re-feed it');
        $this->assertSame($before->alive_until, $this->row(2)->alive_until);

        // Once the UTC day has really ended, tomorrow counts in any zone.
        $flames->feed(2, true, Carbon::now('UTC')->addDay()->setTime(12, 0));
        $this->assertSame(11, (int) $this->row(2)->current);
    }

    #[Test]
    public function switched_off_streaks_are_off_everywhere()
    {
        $this->setting('ernestdefoe-cadence.streaks', '0');
        $this->app();
        $this->flame(3, 9, 0);

        // The forum hides every placement; nothing streak-shaped is sent.
        [, $forum] = $this->json('GET', '/api', 2);
        $this->assertFalse($forum['data']['attributes']['cadenceStreaks']);
        $this->assertFalse($forum['data']['attributes']['canEarnStreaks']);
        $this->assertArrayNotHasKey('cadenceStreakRules', $forum['data']['attributes']);

        [, $user] = $this->json('GET', '/api/users/3', 3);
        $this->assertArrayNotHasKey('cadenceStreak', $user['data']['attributes']);
        $this->assertArrayNotHasKey('cadenceFlame', $user['data']['attributes']);

        // The pages behind it are gone.
        $this->assertSame(404, $this->json('GET', '/api/cadence/streaks', 2)[0]);
        $this->assertSame(404, $this->json('GET', '/api/cadence/streaks/3', 2)[0]);

        // Nothing is counted: not a post, not a visit, not a timezone.
        $this->reply(2);
        [$status, $body] = $this->json('POST', '/api/cadence/streak/visit', 2, ['timezone' => 'Europe/London']);
        $this->assertSame(200, $status);
        $this->assertNull($body['data']['streak']);
        $this->assertNull($this->row(2));

        // And the activity map works on its own.
        [$status, $map] = $this->json('GET', '/api/cadence/2', 2);
        $this->assertSame(200, $status);
        $this->assertNotEmpty($map['data']['days']);
    }
}
