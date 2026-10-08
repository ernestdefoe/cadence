<?php

namespace ErnestDefoe\Cadence\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Cadence\Spark;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ReactionsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Spark::reset();

        $this->extension('fof-reactions', 'ernestdefoe-cadence');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
            ],
        ]);
    }

    private function react(?string $identifier): int
    {
        $id = $identifier === null ? null : (string) $this->database()->table('reactions')->where('identifier', $identifier)->value('id');

        return $this->send($this->request('PATCH', '/api/posts/1', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['reaction' => $id]]],
        ]))->getStatusCode();
    }

    private function reactions(): int
    {
        return (int) $this->database()->table('cadence_activity')->where('user_id', 2)->where('kind', 'reaction')->sum('count');
    }

    #[Test]
    public function changing_a_reaction_is_still_one_reaction()
    {
        $this->assertSame(200, $this->react('heart'));
        $this->assertSame(1, $this->reactions());

        $this->assertSame(200, $this->react('tada'));
        $this->assertSame(1, $this->reactions(), 'Swapping heart for tada is not a second reaction');

        $this->assertSame(200, $this->react(null));
        $this->assertSame(0, $this->reactions());

        $this->assertSame(200, $this->react('laughing'));
        $this->assertSame(1, $this->reactions(), 'Reacting again after taking it back counts');
    }
}
