<?php

namespace ErnestDefoe\Cadence\Listener;

use ErnestDefoe\Cadence\Recorder;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Everything that turns forum activity into buckets, in one place.
 *
 * 🚨 A SUBSCRIBER, not ten `->listen()` calls.
 *
 * `Extend\Event::listen()` takes `callable|string` — a class name whose
 * `handle()` runs, or a closure. Its own docblock claims an array callable
 * works; the signature rejects it, and the failure is a TypeError while
 * extenders are being applied, which means a 500 on EVERY route rather than a
 * broken feature. Ten events the array way would have been ten classes; the
 * subscriber is the idiom Flarum inherits from Laravel for exactly this.
 */
class ActivitySubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, [self::class, 'posted']);
        $events->listen(PostDeleted::class, [self::class, 'postDeleted']);
        $events->listen(PostHidden::class, [self::class, 'postHidden']);
        $events->listen(PostRestored::class, [self::class, 'postRestored']);

        /*
         * Optional kinds, each guarded on the event class actually existing.
         *
         * 🚨 Guarded on the CLASS rather than wired through Extend\Conditional
         * because the guard has to hold at the moment the listener is
         * registered. An extension that is installed but disabled leaves its
         * classes loadable and its events unfired, which is harmless; one that
         * is absent entirely would fatal here, and this method runs during
         * boot for every request.
         */
        if (class_exists(\Flarum\Likes\Event\PostWasLiked::class)) {
            $events->listen(\Flarum\Likes\Event\PostWasLiked::class, [self::class, 'liked']);
            $events->listen(\Flarum\Likes\Event\PostWasUnliked::class, [self::class, 'unliked']);
        }

        if (class_exists(\FoF\Reactions\Event\PostWasReacted::class)) {
            $events->listen(\FoF\Reactions\Event\PostWasReacted::class, [self::class, 'reacted']);
            $events->listen(\FoF\Reactions\Event\PostWasUnreacted::class, [self::class, 'unreacted']);
        }

        if (class_exists(\FoF\BestAnswer\Events\BestAnswerSet::class)) {
            $events->listen(\FoF\BestAnswer\Events\BestAnswerSet::class, [self::class, 'bestAnswerSet']);
            $events->listen(\FoF\BestAnswer\Events\BestAnswerUnset::class, [self::class, 'bestAnswerUnset']);
        }
    }

    /**
     * 🚨 A new discussion fires BOTH `Discussion\Event\Started` AND this, for
     * the same first post. Listening to both double-counts every discussion
     * anyone starts — and the map still looks roughly right, so it survives a
     * casual look while being permanently wrong.
     *
     * `Started` is therefore never listened to; post number 1 is what makes a
     * discussion a discussion here.
     */
    public function posted(Posted $event): void
    {
        $this->adjust($event->post, 1);
    }

    public function postDeleted(PostDeleted $event): void
    {
        $this->adjust($event->post, -1);
    }

    /** Hiding is a soft delete; the map should agree with what a reader can see. */
    public function postHidden(PostHidden $event): void
    {
        $this->adjust($event->post, -1);
    }

    public function postRestored(PostRestored $event): void
    {
        $this->adjust($event->post, 1);
    }

    private function adjust(Post $post, int $delta): void
    {
        /*
         * 🚨 `type === 'comment'` and not `instanceof Post`. The entries core
         * writes for renames, locks, stickies and tag changes are Posts too,
         * and counting them makes a moderator tidying up look like the most
         * active member on the forum.
         */
        if ($post->type !== 'comment' || (int) $post->user_id <= 0) {
            return;
        }

        $this->recorder->record(
            (int) $post->user_id,
            $post->created_at,
            ((int) $post->number) === 1 ? Recorder::DISCUSSION : Recorder::REPLY,
            $delta
        );
    }

    // ---- optional kinds ----------------------------------------------------

    /**
     * 🚨 These record what a member DID, never what was done to them. Liking
     * someone else's post is your activity; being liked is theirs. Mixing them
     * makes one popular post outrank showing up every day, which is backwards
     * for a thing called Cadence.
     */
    public function liked($event): void
    {
        $this->recorder->record((int) $event->user->id, $this->now(), Recorder::LIKE, 1);
    }

    public function unliked($event): void
    {
        $this->recorder->record((int) $event->user->id, $this->now(), Recorder::LIKE, -1);
    }

    public function reacted($event): void
    {
        if ($user = ($event->user ?? $event->actor ?? null)) {
            $this->recorder->record((int) $user->id, $this->now(), Recorder::REACTION, 1);
        }
    }

    public function unreacted($event): void
    {
        if ($user = ($event->user ?? $event->actor ?? null)) {
            $this->recorder->record((int) $user->id, $this->now(), Recorder::REACTION, -1);
        }
    }

    /**
     * 🚨 Credited to the ANSWER'S AUTHOR, not the actor who marked it — the one
     * exception, and it earns it. Marking a best answer is clerical; writing
     * the answer is the thing worth seeing on a map. Crediting the marker makes
     * one diligent moderator the forum's best contributor.
     */
    public function bestAnswerSet($event): void
    {
        $this->recorder->record((int) $event->post->user_id, $this->now(), Recorder::BEST_ANSWER, 1);
    }

    public function bestAnswerUnset($event): void
    {
        if ($post = ($event->post ?? null)) {
            $this->recorder->record((int) $post->user_id, $this->now(), Recorder::BEST_ANSWER, -1);
        }
    }

    /** These events carry no timestamp; a like happens when it happens. */
    private function now(): \DateTimeInterface
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
