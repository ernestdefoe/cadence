<?php

namespace ErnestDefoe\Cadence\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Backfill;
use ErnestDefoe\Cadence\Console\RebuildStreaksCommand;
use ErnestDefoe\Cadence\Extend\StartBackfill;
use ErnestDefoe\Cadence\Spark;
use ErnestDefoe\Cadence\Streak\FlameAttribute;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The history rebuild runs in the background, a slice at a time — never in a
 * migration, never unbounded in a request (Discuss #15) — and an interrupted
 * one finishes with exactly what an uninterrupted one gives.
 */
class BackfillTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const MEMBERS = [3, 4, 5, 6, 7];

    protected function setUp(): void
    {
        parent::setUp();

        Spark::reset();
        FlameAttribute::reset();

        $this->extension('flarum-likes', 'ernestdefoe-cadence');

        $users = [$this->normalUser()];
        $posts = [];
        $id = 0;

        /*
         * Five members with histories: each posts on the days in its pattern,
         * counted back from today. Two runs each, so there is a current flame
         * and a Hall of Flames entry to get right.
         */
        foreach (self::MEMBERS as $member) {
            $users[] = ['id' => $member, 'username' => "m$member", 'email' => "m$member@machine.local", 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYears(2)];

            foreach (array_merge(range(0, $member - 3), range($member + 2, 2 * $member + 9)) as $daysAgo) {
                $id++;
                $posts[] = ['id' => $id, 'discussion_id' => 1, 'number' => $id, 'created_at' => Carbon::now('UTC')->subDays($daysAgo)->setTime(12, 0), 'user_id' => $member, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>'];
            }
        }

        $this->prepareDatabase([
            User::class => $users,
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now()->subYear(), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => $id, 'last_post_number' => $id],
            ],
            Post::class => $posts,
        ]);
    }

    private function backfill(): Backfill
    {
        return $this->app()->getContainer()->make(Backfill::class);
    }

    /** Everything the rebuild writes, comparable across runs. */
    private function snapshot(): array
    {
        $db = $this->database();

        return [
            'activity' => $db->table('cadence_activity')->orderBy('user_id')->orderBy('bucket')->orderBy('kind')->get(['user_id', 'bucket', 'kind', 'count'])->map(fn ($r) => (array) $r)->all(),
            'streaks' => $db->table('cadence_streaks')->orderBy('user_id')->get(['user_id', 'current', 'longest', 'total_days', 'started_on', 'last_day', 'alive_until', 'freezes'])->map(fn ($r) => (array) $r)->all(),
            'hall' => $db->table('cadence_streak_journal')->where('type', 'ended')->orderBy('user_id')->orderBy('value')->get(['user_id', 'value', 'data'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function finish(): void
    {
        while ($this->backfill()->step()) {
        }
    }

    #[Test]
    public function the_migrations_only_ask_for_a_rebuild()
    {
        $this->app();
        $schema = $this->database()->getSchemaBuilder();

        foreach (['2026_10_05_000000_rebuild_cadence_activity_for_audience', '2026_10_20_000003_rebuild_cadence_streaks'] as $file) {
            $migration = include __DIR__."/../../../migrations/$file.php";
            $migration['up']($schema);
        }

        $this->assertSame(0, $this->database()->table('cadence_activity')->count(), 'No activity is built in the migration');
        $this->assertSame(0, $this->database()->table('cadence_streaks')->count(), 'No flame is built in the migration');
        $this->assertSame(['activity', 'streaks'], $this->backfill()->state()['stages']);
    }

    #[Test]
    public function enabling_with_no_queue_leaves_the_work_to_the_background()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');

        $extension = $this->app()->getContainer()->make(ExtensionManager::class)->getExtension('ernestdefoe-cadence');
        (new StartBackfill())->onEnable($this->app()->getContainer(), $extension);

        $this->assertFalse($this->backfill()->hasQueue());
        $this->assertSame(0, $this->database()->table('cadence_streaks')->count(), 'The sync queue would have run it inside the request');
        $this->assertNotNull($this->backfill()->state());
    }

    #[Test]
    public function the_backfill_builds_activity_and_flames_from_history()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');
        $this->finish();

        $this->assertNull($this->backfill()->state(), 'Nothing is left pending');

        // m5: today back to 2 days ago (3 days), then 7 to 19 days ago (13).
        $row = $this->database()->table('cadence_streaks')->where('user_id', 5)->first();
        $this->assertSame(3, (int) $row->current);
        $this->assertSame(13, (int) $row->longest);
        $this->assertSame(16, (int) $row->total_days);
        $this->assertSame(1, $this->database()->table('cadence_streak_journal')->where('user_id', 5)->where('type', 'ended')->where('value', 13)->count());
        $this->assertSame(16, (int) $this->database()->table('cadence_activity')->where('user_id', 5)->sum('count'));
    }

    #[Test]
    public function an_interrupted_rebuild_resumes_and_matches_an_uninterrupted_one()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');
        $this->finish();
        $expected = $this->snapshot();

        // What 1.2.2's migration left when PHP-FPM killed it: the whole Hall
        // of Flames deleted, some flames written, some wrong, some missing.
        $this->database()->table('cadence_streak_journal')->delete();
        $this->database()->table('cadence_streaks')->where('user_id', '>', 4)->delete();
        $this->database()->table('cadence_streaks')->where('user_id', 3)->update(['current' => 99, 'longest' => 99]);

        // Two members at a time, and the process "dies" after two slices.
        $this->backfill()->request('streaks');
        $this->database()->table('settings')->where('key', Backfill::KEY)->update(['value' => json_encode(['size' => 2] + $this->backfill()->state())]);
        $this->backfill()->step();
        $this->backfill()->step();
        $this->assertNotNull($this->backfill()->state(), 'Still pending after the interruption');
        $this->assertGreaterThan(0, $this->backfill()->state()['after']);

        // A new process picks it up where it stopped.
        $this->app()->getContainer()->forgetInstance(Backfill::class);
        $this->finish();

        $this->assertEquals($expected, $this->snapshot());
    }

    #[Test]
    public function running_it_twice_changes_nothing()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');
        $this->finish();
        $first = $this->snapshot();

        $this->backfill()->request('activity', 'streaks');
        $this->finish();

        $this->assertEquals($first, $this->snapshot(), 'No duplicate buckets or Hall of Flames entries');
    }

    #[Test]
    public function the_command_finishes_a_pending_rebuild()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');

        $output = new BufferedOutput();
        $status = $this->app()->getContainer()->make(RebuildStreaksCommand::class)->run(new ArrayInput([]), $output);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Done in', $output->fetch());
        $this->assertNull($this->backfill()->state());
        $this->assertSame(count(self::MEMBERS), $this->database()->table('cadence_streaks')->count());
    }

    #[Test]
    public function the_admin_poll_reports_progress_and_only_admins_may_ask()
    {
        $this->app();
        $this->backfill()->request('activity', 'streaks');

        $response = $this->send($this->request('POST', '/api/cadence/backfill', ['authenticatedAs' => 2]));
        $this->assertSame(403, $response->getStatusCode());

        // No queue and no scheduler has reported in: the poll does the work.
        $response = $this->send($this->request('POST', '/api/cadence/backfill', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pending' => false, 'stage' => null, 'percent' => null], json_decode((string) $response->getBody(), true));
        $this->assertSame(count(self::MEMBERS), $this->database()->table('cadence_streaks')->count());
    }

    #[Test]
    public function the_forum_says_how_far_the_build_has_got()
    {
        $this->app();
        $this->backfill()->request('streaks');

        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => 2]));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(0, $body['data']['attributes']['cadenceStreakRules']['building']);
    }
}
