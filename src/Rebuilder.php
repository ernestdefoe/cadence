<?php

namespace ErnestDefoe\Cadence;

use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Rebuilds the posts and likes buckets from the forum's own tables.
 *
 * Used by `cadence:rebuild` and by the migration that cleared out activity
 * recorded before the map was limited to what its audience can see.
 */
class Rebuilder
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @param  callable(string): void  $say
     * @return int buckets written
     */
    public function rebuild(?callable $say = null): int
    {
        $say ??= fn (string $line) => null;

        $likes = $this->db->getSchemaBuilder()->hasTable('post_likes');

        /*
         * 🚨 Only the kinds this can rebuild are cleared. Reactions and best
         * answers have no timestamp to rebuild from, so truncating the table
         * would erase them for good — and this also runs from a migration,
         * where nobody chose to lose them.
         */
        $say('Clearing existing buckets…');
        $this->db->table('cadence_activity')
            ->whereIn('kind', $likes ? [Recorder::DISCUSSION, Recorder::REPLY, Recorder::LIKE] : [Recorder::DISCUSSION, Recorder::REPLY])
            ->delete();

        /*
         * 🚨 Aggregated in SQL rather than by loading posts into PHP. A forum
         * with two million posts is an ordinary forum, and walking them in
         * Eloquent to count them is minutes of work and a memory ceiling for a
         * result the database can produce directly.
         *
         * 🚨 The filter is the correctness of the whole thing, and it is
         * Flarum's own: the posts the map's audience could open (see Audience).
         * That drops private discussions, direct messages, restricted tags,
         * hidden and unapproved posts — and whatever another extension hides —
         * without this class knowing any of those rules. `type = 'comment'`
         * on top, because the event posts core writes for renames, locks and
         * tag changes are Posts too.
         */
        $say('Aggregating posts…');

        $this->db->table('cadence_activity')->insertUsing(
            ['user_id', 'bucket', 'kind', 'count'],
            $this->visiblePosts()
                ->whereNotNull('posts.user_id')
                ->selectRaw(
                    $this->col('posts.user_id').' AS user_id, '
                    .$this->hour('posts.created_at').' AS bucket, '
                    .'CASE WHEN '.$this->col('posts.number')." = 1 THEN 'discussion' ELSE 'reply' END AS kind, "
                    .'COUNT(*)'
                )
                ->groupBy('user_id', 'bucket', 'kind')
        );

        /*
         * Likes carry their own created_at, so they rebuild exactly — and only
         * on posts the audience could see, since liking something in a
         * staff-only tag is activity in a staff-only tag.
         *
         * 🚨 Reactions and best answers do NOT: neither records when it
         * happened, so they can only ever accumulate from the moment this
         * extension is installed. Said plainly in the README rather than left
         * for someone to discover as a hole in their own history.
         */
        if ($likes) {
            $say('Aggregating likes…');

            $this->db->table('cadence_activity')->insertUsing(
                ['user_id', 'bucket', 'kind', 'count'],
                $this->db->table('post_likes')
                    ->whereNotNull('post_likes.created_at')
                    ->whereIn('post_likes.post_id', $this->visiblePosts()->select('posts.id'))
                    ->selectRaw(
                        $this->col('post_likes.user_id').' AS user_id, '
                        .$this->hour('post_likes.created_at')." AS bucket, 'like' AS kind, COUNT(*)"
                    )
                    ->groupBy('user_id', 'bucket')
            );
        } else {
            $say('Skipping likes — flarum/likes is not installed.');
        }

        return $this->db->table('cadence_activity')->count();
    }

    private function visiblePosts(): Builder
    {
        return Post::query()
            ->whereVisibleTo(Audience::viewer())
            ->where('posts.type', 'comment')
            ->toBase();
    }

    /**
     * 🚨 Raw SQL is passed through VERBATIM, so a column named in it must
     * carry the table prefix. The grammar's wrap() is what the builder itself
     * uses for `table.column`, and it adds the prefix — so it is not added
     * again here.
     */
    private function col(string $column): string
    {
        return $this->db->getQueryGrammar()->wrap($column);
    }

    private function hour(string $column): string
    {
        return 'DATE_FORMAT('.$this->col($column).", '%Y-%m-%d %H:00:00')";
    }
}
