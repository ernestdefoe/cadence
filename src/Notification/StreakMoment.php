<?php

namespace ErnestDefoe\Cadence\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * A moment in a member's own flame: a milestone, a new tier, a record, a
 * freeze that saved it, a revival. Alert only — none of these is worth an
 * email, and a member can switch them off like any other alert.
 *
 * 🚨 The subject is the member themselves, so it needs no resource of its own,
 * and the date is in the data so the same milestone reached on a later run is
 * a new alert rather than the old one quietly restored.
 */
class StreakMoment implements AlertableInterface, BlueprintInterface
{
    public function __construct(public User $user, public string $event, public int $value, public string $on)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return ['event' => $this->event, 'value' => $this->value, 'on' => $this->on];
    }

    public static function getType(): string
    {
        return 'cadenceStreakMoment';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
