<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per member, per HOUR, per kind of activity.
 *
 * 🚨 Hourly, not daily, and that is the load-bearing decision in this
 * extension.
 *
 * A daily table would be smaller, but it bakes a timezone into the data: a post
 * at 23:40 belongs to a different day depending on who is looking, and once it
 * has been summed into a day row that can never be undone. Every forum would be
 * stuck with whatever the server's clock happened to be, and "when is this
 * member around?" — the question this exists to answer that GitHub's graph
 * cannot — would be unanswerable.
 *
 * Hourly buckets make the timezone a READ-time decision. The same rows produce
 * a day grid in any offset, and the hour-of-week rhythm comes out of them for
 * free rather than needing a second table.
 *
 * Cost: only non-empty buckets are stored, so this is proportional to hours a
 * member was actually active, not to elapsed time. A member who posts in 200
 * distinct hours across a year with two kinds of activity is 400 rows.
 */
return Migration::createTable('cadence_activity', function (Blueprint $table) {
    $table->unsignedInteger('user_id');

    /*
     * The start of the hour, in UTC, always. Anything else is a lie that is
     * expensive to discover later.
     */
    $table->dateTime('bucket');

    /*
     * 'discussion' or 'reply' for now. A string rather than an enum or an int
     * so another extension can contribute its own kind without a migration —
     * see Recorder::record().
     */
    $table->string('kind', 32);

    $table->unsignedInteger('count')->default(0);

    /*
     * The primary key IS the query. Every read is "this member, this window",
     * so user_id must lead; bucket second makes the range scan contiguous.
     */
    $table->primary(['user_id', 'bucket', 'kind']);

    /*
     * For the forum-wide roll-up and for pruning, which walk by time across all
     * members and would otherwise scan the whole table.
     */
    $table->index('bucket');
});
