<?php

namespace ErnestDefoe\Cadence\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Your flame needs you": it is at risk tonight (`risk`), or it went out and
 * is down to embers you can still revive (`embers`). Alert and email, both of
 * which the member can switch off in their own notification settings.
 */
class StreakWarning implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public User $user, public string $kind, public int $value, public string $on)
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
        return ['kind' => $this->kind, 'value' => $this->value, 'on' => $this->on];
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-cadence::emails.plain.warning', 'html' => 'ernestdefoe-cadence::emails.html.warning'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-cadence.email.'.$this->kind.'.subject', ['count' => $this->value]);
    }

    public static function getType(): string
    {
        return 'cadenceStreakWarning';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
