<?php

namespace ErnestDefoe\Cadence;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

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
     * 🚨 One atomic upsert, never read-then-write. Two people liking the same
     * post in the same second is the ordinary case on a busy forum, and a
     * select-then-update loses one of them silently — the kind of drift that is
     * invisible until someone's map disagrees with their post count and there
     * is no way left to tell which was right.
     *
     * 🚨 `GREATEST(0, …)` because a delete can arrive for activity recorded
     * before this extension was installed, and a negative count would render as
     * a hole in the map that no rebuild could explain.
     */
    public function record(int $userId, \DateTimeInterface $at, string $kind, int $delta = 1): void
    {
        if ($userId <= 0 || $delta === 0) {
            return;
        }

        $bucket = Carbon::instance(Carbon::parse($at))->utc()->startOfHour();

        $this->db->statement(
            'INSERT INTO '.$this->table().' (user_id, bucket, kind, count) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE count = GREATEST(0, CAST(count AS SIGNED) + ?)',
            [$userId, $bucket->toDateTimeString(), $kind, max(0, $delta), $delta]
        );
    }

    /**
     * 🚨 The table name is built from the connection's own prefix rather than
     * written literally, because this is raw SQL and Laravel does NOT apply the
     * prefix to it. A forum with a table prefix — which is every forum sharing
     * a database, and most one-click installs — would get "table does not
     * exist" on the first post anyone made.
     */
    private function table(): string
    {
        return $this->db->getTablePrefix().'cadence_activity';
    }
}
