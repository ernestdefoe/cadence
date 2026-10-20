<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's flame. See the migration for why it is stored rather than derived.
 *
 * @property int $user_id
 * @property int $current
 * @property int $longest
 * @property int $total_days
 * @property string|null $started_on
 * @property string|null $last_day
 * @property Carbon|null $day_ends_at
 * @property Carbon|null $alive_until
 * @property Carbon|null $fed_at
 * @property int $freezes
 * @property int $embers
 * @property Carbon|null $embers_until
 * @property string|null $timezone
 * @property string|null $reminded_on
 * @property string|null $celebrate
 */
class Streak extends AbstractModel
{
    protected $table = 'cadence_streaks';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'current' => 'integer',
        'longest' => 'integer',
        'total_days' => 'integer',
        'freezes' => 'integer',
        'embers' => 'integer',
        'alive_until' => 'datetime',
        'fed_at' => 'datetime',
        'embers_until' => 'datetime',
        'day_ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 🚨 Read the length through this, never `current` raw. `current` is the
     * length the last time the flame was fed; whether it is still burning is
     * `alive_until`, and a member who stopped a week ago must read as 0 even
     * on a forum whose scheduler has never run.
     */
    public function burning(?Carbon $now = null): int
    {
        return $this->isAlight($now) ? $this->current : 0;
    }

    public function isAlight(?Carbon $now = null): bool
    {
        return $this->current > 0 && $this->alive_until && $this->alive_until->gte($now ?? Carbon::now('UTC'));
    }

    public function hasEmbers(?Carbon $now = null): bool
    {
        return $this->embers > 0 && $this->embers_until && $this->embers_until->gt($now ?? Carbon::now('UTC'));
    }
}
