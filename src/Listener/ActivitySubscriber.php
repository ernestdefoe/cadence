<?php

namespace ErnestDefoe\Cadence\Listener;

use ErnestDefoe\Cadence\Audience;
use ErnestDefoe\Cadence\Recorder;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Post;
use FoF\BestAnswer\Events\BestAnswerSet;
use FoF\BestAnswer\Events\BestAnswerUnset;
use FoF\Reactions\Event\PostWasReacted;
use FoF\Reactions\Event\PostWasUnreacted;
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

        // A post held for approval is not recorded when posted; it is when let through.
        if (class_exists(\Flarum\Approval\Event\PostWasApproved::class)) {
            $events->listen(\Flarum\Approval\Event\PostWasApproved::class, [self::class, 'approved']);
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

    /**
     * 🚨 Only if it was counted: a post that was already hidden was taken off
     * when it was hidden, and taking it off again would eat a neighbour's count
     * in the same hour.
     */
    public function postDeleted(PostDeleted $event): void
    {
        $this->adjust($event->post, -1);
    }

    /** Hiding is a soft delete; the map should agree with what a reader can see. */
    public function postHidden(PostHidden $event): void
    {
        $this->adjust($event->post, -1, ignoreHidden: true);
    }

    public function postRestored(PostRestored $event): void
    {
        $this->adjust($event->post, 1);
    }

    public function approved(PostWasApproved $event): void
    {
        $this->adjust($event->post, 1);
    }

    private function adjust(Post $post, int $delta, bool $ignoreHidden = false): void
    {
        /*
         * 🚨 Only what the map's audience could see — see Audience. Without
         * this, a post in a staff-only tag, a private discussion or a direct
         * message lands on the member's public map, and a guest can read when
         * and how often they were active somewhere they are not allowed to be.
         *
         * Audience also requires `type === 'comment'` and not `instanceof Post`:
         * the entries core writes for renames, locks, stickies and tag changes
         * are Posts too, and counting them makes a moderator tidying up look
         * like the most active member on the forum.
         */
        if (! Audience::canSee($post, $ignoreHidden)) {
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
    public function liked(PostWasLiked $event): void
    {
        $this->onVisiblePost($event, Recorder::LIKE, 1);
    }

    public function unliked(PostWasUnliked $event): void
    {
        $this->onVisiblePost($event, Recorder::LIKE, -1);
    }

    public function reacted(PostWasReacted $event): void
    {
        // 🚨 Swapping one reaction for another fires this again with no
        // unreacted first: the same row, its reaction_id changed. That is not
        // a second reaction.
        if (($event->postReaction->getPrevious()['reaction_id'] ?? null) !== null) {
            return;
        }

        $this->onVisiblePost($event, Recorder::REACTION, 1);
    }

    public function unreacted(PostWasUnreacted $event): void
    {
        $this->onVisiblePost($event, Recorder::REACTION, -1);
    }

    /**
     * 🚨 Liking a post in a staff-only tag is activity in a staff-only tag, so
     * the same audience rule applies. An event without a post cannot be
     * checked, and is not recorded.
     */
    private function onVisiblePost(PostWasLiked|PostWasUnliked|PostWasReacted|PostWasUnreacted $event, string $kind, int $delta): void
    {
        $user = $event->user ?? $event->actor ?? null;
        $post = $event->post ?? null;

        if ($user && $post instanceof Post && Audience::canSee($post)) {
            $this->recorder->record((int) $user->id, $this->now(), $kind, $delta);
        }
    }

    /**
     * 🚨 Credited to the ANSWER'S AUTHOR, not the actor who marked it — the one
     * exception, and it earns it. Marking a best answer is clerical; writing
     * the answer is the thing worth seeing on a map. Crediting the marker makes
     * one diligent moderator the forum's best contributor.
     */
    public function bestAnswerSet(BestAnswerSet $event): void
    {
        $this->bestAnswer($event, 1);
    }

    public function bestAnswerUnset(BestAnswerUnset $event): void
    {
        $this->bestAnswer($event, -1);
    }

    private function bestAnswer(BestAnswerSet|BestAnswerUnset $event, int $delta): void
    {
        $post = $event->post ?? null;

        if ($post instanceof Post && Audience::canSee($post)) {
            $this->recorder->record((int) $post->user_id, $this->now(), Recorder::BEST_ANSWER, $delta);
        }
    }

    /** These events carry no timestamp; a like happens when it happens. */
    private function now(): \DateTimeInterface
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
