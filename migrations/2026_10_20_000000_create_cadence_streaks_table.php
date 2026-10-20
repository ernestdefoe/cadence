<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per member: their flame.
 *
 * 🚨 The streak is STORED, not derived on read. Deriving it means walking a
 * member's days back from today on every page that shows a flame — and a flame
 * shows beside every post author. Stored, a page full of flames is one indexed
 * lookup, and the leaderboard is an ORDER BY.
 *
 * 🚨 `alive_until` is what makes the stored number safe to trust without a
 * scheduler. It is the instant the flame goes out if nothing feeds it, already
 * widened by grace days and banked freezes. Anything that reads `current` reads
 * it through that, so a forum whose cron never runs still never shows a dead
 * flame as alight — the settle job only tidies up (embers, journal, alerts).
 */
return Migration::createTable('cadence_streaks', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();

    $table->unsignedInteger('current')->default(0);
    $table->unsignedInteger('longest')->default(0);
    $table->unsignedInteger('total_days')->default(0);

    // Calendar dates in the MEMBER's timezone, never the server's.
    $table->date('started_on')->nullable();
    $table->date('last_day')->nullable();

    // The instant the last fed day really ended; see the day_ends_at migration.
    $table->dateTime('day_ends_at')->nullable();

    $table->dateTime('alive_until')->nullable();
    $table->dateTime('fed_at')->nullable();

    $table->unsignedTinyInteger('freezes')->default(0);

    // A long flame that went out leaves embers: its length, and until when.
    $table->unsignedInteger('embers')->default(0);
    $table->dateTime('embers_until')->nullable();

    // IANA name, from the member's own browser.
    $table->string('timezone', 64)->nullable();

    // The local date of the last "your flame is at risk" alert, so one a day.
    $table->date('reminded_on')->nullable();

    // Moments not yet shown to the member as a toast.
    $table->text('celebrate')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

    // The two boards: alight by length, and all-time.
    $table->index(['alive_until', 'current']);
    $table->index('longest');
    $table->index('fed_at');
});
