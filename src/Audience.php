<?php

namespace ErnestDefoe\Cadence;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who the map is drawn for: the least privileged person who can open a profile.
 *
 * 🚨 The map is ONE set of counts shown to everyone, so it may only contain
 * activity everyone who can see it could also see. A post in a staff-only tag,
 * a private discussion or a direct message is still a timestamp and a count,
 * and a guest reading "three posts at 2am on Tuesday" off a moderator's map has
 * learned something the forum's permissions were set up to keep from them.
 *
 * That person is a guest when guests can view the forum at all, and otherwise
 * a plain member with no groups beyond Member — on a members-only forum guests
 * cannot open a profile, and measuring against them would leave every map
 * empty.
 *
 * Asked through Flarum's own visibility scopes rather than re-implemented, so
 * tags, approval, private discussions and any other extension's rules all
 * count without Cadence knowing they exist.
 */
class Audience
{
    public static function viewer(): User
    {
        $guest = new Guest();

        if ($guest->hasPermission('viewForum')) {
            return $guest;
        }

        /*
         * 🚨 id 0, not null. Visibility scopes compare columns against
         * `$actor->id`, and the query builder turns `where(col, null)` into
         * `whereNull(col)` — which would make every post by a deleted account
         * "the actor's own".
         */
        $member = new User();
        $member->id = 0;
        $member->is_email_confirmed = true;
        $member->setRelation('groups', new Collection());

        return $member;
    }

    /**
     * Could the audience see this post?
     *
     * One indexed query for the discussion; the post's own flags are read off
     * the model, so this also works for a post that has just been deleted.
     *
     * `$ignoreHidden` asks "was it visible before it was hidden?", which is
     * what a hide has to know to undo exactly what was recorded.
     */
    public static function canSee(Post $post, bool $ignoreHidden = false): bool
    {
        if ($post->type !== 'comment' || (int) $post->user_id <= 0) {
            return false;
        }

        if ($post->is_private || (! $ignoreHidden && $post->hidden_at !== null)) {
            return false;
        }

        // Only present with flarum/approval; null means there is no queue.
        if ($post->getAttribute('is_approved') !== null && ! $post->is_approved) {
            return false;
        }

        return Discussion::query()
            ->whereVisibleTo(self::viewer())
            ->whereKey($post->discussion_id)
            ->exists();
    }
}
