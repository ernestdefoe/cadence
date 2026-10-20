<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Every moment in a flame's life: milestones, tiers, records, freezes melting,
 * a flame going out, a revival, and anything staff changed by hand.
 *
 * It is also the Hall of Flames. A run that ended is an `ended` row whose value
 * is its length, so "the great flames that burned before" is one indexed sort
 * and a member who had three great runs appears three times, which is the
 * point of a hall rather than a second all-time board.
 */
return Migration::createTable('cadence_streak_journal', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('type', 24);
    $table->unsignedInteger('value')->default(0);
    $table->text('data')->nullable();
    $table->unsignedInteger('actor_id')->nullable();
    $table->dateTime('created_at');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

    $table->index(['user_id', 'created_at']);
    $table->index(['type', 'value']);
    $table->index('created_at');
});
