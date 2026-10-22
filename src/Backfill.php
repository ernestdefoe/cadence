<?php

namespace ErnestDefoe\Cadence;

use ErnestDefoe\Cadence\Streak\StreakRebuilder;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\SyncQueue;

/**
 * Rebuilds the activity map and every flame from the forum's history, a slice
 * of members at a time, in the background.
 *
 * 🚨 Never inside a migration, and never unbounded inside a web request. A
 * migration runs in the admin's "enable" click; on a forum with 568k posts and
 * 17k members the rebuild outlived PHP-FPM's 30 seconds and the click came back
 * as a Cloudflare 502, with the extension half-installed (Discuss #15). The
 * migrations now only ask for a rebuild, and this does it:
 *
 *  - a queued job, when the forum has a real queue — each job works for a few
 *    seconds and queues the next;
 *  - the scheduler, every minute, when it has none (or its worker has died);
 *  - `php flarum cadence:rebuild-streaks`, with a progress bar, for anyone who
 *    would rather watch it finish;
 *  - the admin page, a couple of seconds per poll while it is open, so even a
 *    forum with no cron and no queue finishes.
 *
 * Progress is one setting: which stages are left and the last member id done.
 * Each slice is its own transaction and repeating one changes nothing, so an
 * interrupted run — a killed worker, a deploy, a timeout — resumes where it
 * stopped and never leaves a member half-built.
 */
class Backfill
{
    public const KEY = 'ernestdefoe-cadence.backfill';

    /** In order: flames are walked from the activity, so they follow it. */
    public const STAGES = ['activity', 'streaks'];

    /** A runner that has not reported in this long has stopped. */
    public const STALLED = 120;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ConnectionInterface $db,
        private Rebuilder $activity,
        private StreakRebuilder $streaks,
        private Queue $queue,
        private Cache $cache,
    ) {
    }

    /** Ask for a rebuild. Cheap: one setting. Merges with one already pending. */
    public function request(string ...$stages): void
    {
        $pending = $this->state()['stages'] ?? [];
        $stages = array_values(array_intersect(self::STAGES, array_merge($pending, $stages)));

        $this->save([
            'stages' => $stages,
            'of' => count($stages),
            'after' => 0,
            'done' => 0,
            'total' => $this->db->table('users')->count(),
            'touched' => null,
        ]);
    }

    /**
     * Read past the settings cache: a queue worker lives for hours, and the
     * cached copy it booted with is not where the run is now.
     *
     * @return array{stages: string[], of: int, after: int, done: int, total: int, touched: ?int, size?: int}|null
     */
    public function state(): ?array
    {
        $state = json_decode((string) $this->db->table('settings')->where('key', self::KEY)->value('value'), true);

        return is_array($state) && ! empty($state['stages']) ? $state : null;
    }

    /** 0–100 across every stage left, or null when nothing is pending. */
    public static function percent(?array $state): ?int
    {
        if (empty($state['stages'])) {
            return null;
        }

        $of = max(1, (int) $state['of']);
        $total = max(1, (int) $state['total']);
        $finished = $of - count($state['stages']);

        return (int) min(99, floor(100 * ($finished + min(1, $state['done'] / $total)) / $of));
    }

    /** Whether no runner has reported in for a while (or none ever started). */
    public function stalled(?array $state): bool
    {
        return $state && (empty($state['touched']) || time() - (int) $state['touched'] > self::STALLED);
    }

    /**
     * Work for up to `$seconds`, a slice at a time. One runner at a time:
     * another that arrives meanwhile leaves at once.
     *
     * @param  (callable(array): void)|null  $tick  after every slice
     * @return array|null the state left, null when everything is built
     */
    public function run(float $seconds, ?callable $tick = null): ?array
    {
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('cadence-backfill', (int) ceil($seconds) + 10) : null;

        if ($lock && ! $lock->get()) {
            return $this->state();
        }

        try {
            $until = microtime(true) + $seconds;
            $state = $this->state();

            // The lock holder is the only writer, so its state carries over.
            while ($state) {
                $state = $this->advance($state);
                $state && $tick && $tick($state);

                if (microtime(true) >= $until) {
                    break;
                }
            }

            return $state;
        } finally {
            $lock?->release();
        }
    }

    /**
     * One slice of members through the current stage.
     *
     * 🚨 Sized by how long the last slice took, not fixed: members are not
     * equal, and the first slice of a forum holds its oldest, busiest members
     * — 500 of them were a fifth of all posts in the benchmark. Starting small
     * and growing keeps every slice near a second, so a poll or a job is never
     * held up by one.
     */
    public function step(): ?array
    {
        return ($state = $this->state()) ? $this->advance($state) : null;
    }

    private function advance(array $state): ?array
    {
        $size = (int) ($state['size'] ?? 100);
        $started = microtime(true);

        $ids = $this->db->table('users')->where('id', '>', (int) $state['after'])->orderBy('id')->limit($size)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (! $ids) {
            array_shift($state['stages']);
            $state['after'] = $state['done'] = 0;
        } else {
            $state['stages'][0] === 'activity'
                ? $this->db->transaction(fn () => $this->activity->rebuildUsers($ids))
                : $this->streaks->rebuildUsers($ids);

            $state['after'] = end($ids);
            $state['done'] += count($ids);

            $took = microtime(true) - $started;
            $state['size'] = $took > 1 ? max(10, intdiv($size, 2)) : ($took < 0.25 ? min(2000, $size * 2) : $size);
        }

        $state['touched'] = time();
        $this->save($state['stages'] ? $state : null);

        return $state['stages'] ? $state : null;
    }

    /** Hand the rest to the queue, if this forum has a real one. */
    public function dispatch(): bool
    {
        if (! $this->hasQueue()) {
            return false;
        }

        $this->queue->push(new Jobs\BackfillJob());

        return true;
    }

    /**
     * 🚨 The sync "queue" runs a job the instant it is pushed — inside the
     * request that pushed it, which is the very thing this class exists to
     * avoid. Core wraps a real driver in a router; unwrap before asking.
     */
    public function hasQueue(): bool
    {
        $queue = method_exists($this->queue, 'getDriver') ? $this->queue->getDriver() : $this->queue;

        return ! $queue instanceof SyncQueue;
    }

    private function save(?array $state): void
    {
        $state ? $this->settings->set(self::KEY, json_encode($state)) : $this->settings->delete(self::KEY);
    }
}
