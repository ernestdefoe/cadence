<?php

namespace ErnestDefoe\Cadence\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Rebuilder;
use ErnestDefoe\Cadence\Recorder;
use ErnestDefoe\Cadence\Spark;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class CadenceTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        // The sparkline memo is static; each test starts from nothing.
        Spark::reset();

        $this->extension('flarum-likes', 'ernestdefoe-cadence');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYears(2)],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 2, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 3, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'hidden_at' => Carbon::now()],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>'],
            ],
            'group_permission' => [
                // Several posts in one test would otherwise trip flood control.
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

    private function reply(int $actor, int $discussion = 1): int
    {
        [$status, $body] = $this->json('POST', '/api/posts', $actor, ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]],
        ]]);
        $this->assertSame(201, $status);

        return (int) $body['data']['id'];
    }

    /** @return array<string, int> kind => total over every bucket */
    private function totals(int $userId): array
    {
        return $this->database()->table('cadence_activity')->where('user_id', $userId)
            ->get()->groupBy('kind')->map(fn ($rows) => $rows->sum('count'))->all();
    }

    #[Test]
    public function the_map_is_built_from_what_everyone_can_see()
    {
        // What enabling the extension runs (a migration) and the console
        // command both do: the open discussion's first post counts, the
        // hidden one's does not.
        $this->app()->getContainer()->make(Rebuilder::class)->rebuild();
        $this->assertSame(['discussion' => 1], $this->totals(3));

        [$status, $body] = $this->json('GET', '/api/cadence/3');
        $this->assertSame(200, $status);
        $this->assertSame(3, $body['data']['userId']);
        $this->assertSame(['discussion' => 1], $body['data']['days'][Carbon::now('UTC')->toDateString()]);
        $this->assertCount(168, $body['data']['hours']);
        $this->assertSame(1, array_sum($body['data']['hours']));
    }

    #[Test]
    public function an_unknown_member_is_not_found()
    {
        [$status] = $this->json('GET', '/api/cadence/999');
        $this->assertSame(404, $status);
    }

    #[Test]
    public function a_new_members_map_starts_on_the_day_they_joined()
    {
        $this->database()->table('users')->where('id', 2)->update(['joined_at' => Carbon::now()->subDays(10)]);

        [, $body] = $this->json('GET', '/api/cadence/2');
        $this->assertSame(Carbon::now('UTC')->subDays(10)->toDateString(), $body['data']['start']);
        $this->assertSame(Carbon::now('UTC')->subDays(10)->toDateString(), $body['data']['joinedAt']);
    }

    #[Test]
    public function replies_are_counted_as_they_happen_and_taken_back_when_hidden()
    {
        $first = $this->reply(2);
        $this->reply(2);
        $this->assertSame(['reply' => 2], $this->totals(2));

        [$status] = $this->json('PATCH', "/api/posts/$first", 1, ['data' => ['type' => 'posts', 'id' => (string) $first, 'attributes' => ['isHidden' => true]]]);
        $this->assertSame(200, $status);
        $this->assertSame(['reply' => 1], $this->totals(2));

        $this->json('PATCH', "/api/posts/$first", 1, ['data' => ['type' => 'posts', 'id' => (string) $first, 'attributes' => ['isHidden' => false]]]);
        $this->assertSame(['reply' => 2], $this->totals(2));
    }

    #[Test]
    public function activity_nobody_else_could_see_is_never_counted()
    {
        // An admin replying in the hidden discussion.
        $this->reply(1, 2);

        $this->assertSame([], $this->totals(1));
    }

    #[Test]
    public function a_like_is_credited_to_whoever_liked()
    {
        [$status] = $this->json('PATCH', '/api/posts/1', 2, ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isLiked' => true]]]);
        $this->assertSame(200, $status);
        $this->assertSame(['like' => 1], $this->totals(2));

        $this->json('PATCH', '/api/posts/1', 2, ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isLiked' => false]]]);
        $this->assertSame(['like' => 0], $this->totals(2), 'Unliking takes it back');

        // A take-back for something recorded before Cadence was installed.
        $this->app()->getContainer()->make(Recorder::class)->record(2, Carbon::now(), Recorder::LIKE, -1);
        $this->assertSame(['like' => 0], $this->totals(2), 'Never below zero');
    }

    #[Test]
    public function the_sparkline_is_off_unless_a_compact_placement_is_on()
    {
        [, $body] = $this->json('GET', '/api/users/3', 1);
        $this->assertNull($body['data']['attributes']['cadenceSpark']);

        [, $body] = $this->json('GET', '/api');
        $attributes = $body['data']['attributes'];
        $this->assertTrue($attributes['cadenceShowOnProfile']);
        $this->assertFalse($attributes['cadenceShowOnPosts']);
        $this->assertFalse($attributes['cadenceShowOnCards']);
        $this->assertFalse($attributes['cadenceRhythm']);
    }

    #[Test]
    public function a_page_of_members_loads_every_sparkline_in_one_query()
    {
        $this->setting('ernestdefoe-cadence.show_on_posts', '1');

        $users = [];
        for ($id = 10; $id < 22; $id++) {
            $users[] = ['id' => $id, 'username' => "user$id", 'email' => "user$id@machine.local", 'is_email_confirmed' => 1];
        }
        $this->prepareDatabase([User::class => $users, 'cadence_activity' => [
            ['user_id' => 3, 'bucket' => Carbon::now('UTC')->startOfHour()->toDateTimeString(), 'kind' => 'reply', 'count' => 4],
        ]]);

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [$status, $body] = $this->json('GET', '/api/users', 1);
        $sparkQueries = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'cadence_activity'));

        $this->assertSame(200, $status);
        $sparks = array_column(array_column($body['data'], 'attributes'), 'cadenceSpark', 'username');
        $this->assertCount(26, $sparks['carol']);
        $this->assertSame(4, $sparks['carol'][25], 'This week is the last of the 26');
        $this->assertSame(0, array_sum($sparks['user15']));
        $this->assertCount(1, $sparkQueries);
    }
}
