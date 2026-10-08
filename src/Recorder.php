<?php

namespace ErnestDefoe\Cadence;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;

/**
 * The only thing that writes to `cadence_activity`.
 *
 * Everything here is a delta against one (member, hour, kind) bucket, so the
 * table is maintained incrementally and a profile view never aggregates over
 * `posts`. That is not a micro-optimisation: a map built by scanning posts on
 * every profile visit is a table scan per visit, and on a forum of any size it
 * takes the whole site down long before anyone notices the graph is pretty.
 */
class Recorder
{
    /** Kinds this extension records itself. Others may add their own. */
    public const DISCUSSION = 'discussion';
    public const REPLY = 'reply';
    public const LIKE = 'like';
    public const REACTION = 'reaction';
    public const BEST_ANSWER = 'best_answer';

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * Add `$delta` to one bucket, creating it if it does not exist.
     *
     * 🚨 One atomic upsert, never read-then-write — the query builder's, so
     * it is the right statement on every database Flarum runs on. Two people liking the same
     * post in the same second is the ordinary case on a busy forum, and a
     * select-then-update loses one of them silently — the kind of drift that is
     * invisible until someone's map disagrees with their post count and there
     * is no way left to tell which was right.
     *
     * 🚨 Floored at zero because a delete can arrive for activity recorded
     * before this extension was installed, and a negative count would render as
     * a hole in the map that no rebuild could explain.
     */
    public function record(int $userId, \DateTimeInterface $at, string $kind, int $delta = 1): void
    {
        if ($userId <= 0 || $delta === 0) {
            return;
        }

        $bucket = Carbon::instance(Carbon::parse($at))->utc()->startOfHour();

        $this->db->table('cadence_activity')->upsert(
            [['user_id' => $userId, 'bucket' => $bucket->toDateTimeString(), 'kind' => $kind, 'count' => max(0, $delta)]],
            ['user_id', 'bucket', 'kind'],
            ['count' => new Expression($this->adjusted($delta))]
        );
    }

    /**
     * The existing count moved by `$delta`, floored at zero.
     *
     * 🚨 Written so it never goes below zero on the way: the column is
     * unsigned, and MySQL refuses `count - 1` on a 0 outright. A CASE rather
     * than GREATEST(), which SQLite does not have.
     *
     * 🚨 Qualified with the table on PostgreSQL, where a bare column in ON
     * CONFLICT DO UPDATE is ambiguous with EXCLUDED. `$delta` is an int, so
     * writing it into the SQL is safe.
     */
    private function adjusted(int $delta): string
    {
        $grammar = $this->db->getQueryGrammar();
        $count = $grammar->wrap('count');

        if ($this->db->getDriverName() === 'pgsql') {
            $count = $grammar->wrapTable('cadence_activity').'.'.$count;
        }

        return $delta > 0
            ? "$count + $delta"
            : "CASE WHEN $count < ".(-$delta)." THEN 0 ELSE $count - ".(-$delta).' END';
    }
}
