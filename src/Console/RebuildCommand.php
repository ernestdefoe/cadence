<?php

namespace ErnestDefoe\Cadence\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuilds every bucket from the forum's own tables.
 *
 * Needed on install — a map that starts empty on a forum with ten years of
 * history tells every member they have never done anything — and as the repair
 * for any drift, since the incremental path can only ever be as correct as the
 * events it heard.
 */
class RebuildCommand extends AbstractCommand
{
    protected $signature = 'cadence:rebuild';
    protected $description = 'Rebuild Cadence activity buckets from posts and likes.';

    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setName('cadence:rebuild')
            ->setDescription('Rebuild Cadence activity buckets from posts and likes.')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Rows to aggregate per pass.', '50000');
    }

    protected function fire(): int
    {
        $prefix = $this->db->getTablePrefix();
        $table = $prefix.'cadence_activity';

        $this->info('Clearing existing buckets…');
        $this->db->table('cadence_activity')->truncate();

        /*
         * 🚨 Aggregated in SQL rather than by loading posts into PHP. A forum
         * with two million posts is an ordinary forum, and walking them in
         * Eloquent to count them is minutes of work and a memory ceiling for a
         * result the database can produce directly.
         *
         * 🚨 The filters are the correctness of the whole thing:
         *   type = 'comment'  — the event posts core writes for renames, locks
         *                       and tag changes are Posts too, and counting
         *                       them makes tidying up look like contribution.
         *   is_private = 0    — a private post is not activity anyone may see.
         *   is_approved = 1   — content still in the approval queue has not
         *                       happened yet as far as readers are concerned.
         *   hidden_at IS NULL — hiding is a soft delete; the map should agree
         *                       with what a reader can actually see.
         */
        $this->info('Aggregating posts…');

        $posted = $this->db->statement(
            "INSERT INTO {$table} (user_id, bucket, kind, count)
             SELECT user_id,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket,
                    CASE WHEN number = 1 THEN 'discussion' ELSE 'reply' END AS kind,
                    COUNT(*)
             FROM {$prefix}posts
             WHERE type = 'comment'
               AND user_id IS NOT NULL
               AND is_private = 0
               AND is_approved = 1
               AND hidden_at IS NULL
             GROUP BY user_id, bucket, kind
             ON DUPLICATE KEY UPDATE count = VALUES(count)"
        );

        $this->info($posted ? '  posts done' : '  posts: nothing to do');

        /*
         * Likes carry their own created_at, so they rebuild exactly.
         *
         * 🚨 Reactions and best answers do NOT: neither records when it
         * happened, so they can only ever accumulate from the moment this
         * extension is installed. Said plainly in the README rather than left
         * for someone to discover as a hole in their own history.
         */
        if ($this->db->getSchemaBuilder()->hasTable('post_likes')) {
            $this->info('Aggregating likes…');

            $this->db->statement(
                "INSERT INTO {$table} (user_id, bucket, kind, count)
                 SELECT l.user_id,
                        DATE_FORMAT(l.created_at, '%Y-%m-%d %H:00:00') AS bucket,
                        'like',
                        COUNT(*)
                 FROM {$prefix}post_likes l
                 JOIN {$prefix}posts p ON p.id = l.post_id
                 WHERE l.created_at IS NOT NULL
                   AND p.is_private = 0
                 GROUP BY l.user_id, bucket
                 ON DUPLICATE KEY UPDATE count = VALUES(count)"
            );

            $this->info('  likes done');
        } else {
            $this->info('Skipping likes — flarum/likes is not installed.');
        }

        $total = $this->db->table('cadence_activity')->count();
        $this->info("Rebuilt {$total} buckets.");

        return 0;
    }
}
