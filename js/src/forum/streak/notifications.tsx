import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t, tierFor, tierName } from '../../common/streak/flame';

/** "Milestone reached: 30 days of fire", "You reached Blaze", … */
export class StreakMomentNotification extends Notification {
  icon() {
    return 'fas fa-fire';
  }
  href() {
    const user = app.session.user;
    return user ? app.route('user.streak', { username: user.slug() }) : app.route('cadence.streaks');
  }
  content() {
    const data = this.attrs.notification.content() as any;
    return data?.event === 'tier' ? t('toast.tier', { tier: tierName(tierFor(data.value)) }) : t(`toast.${data?.event}`, { count: data?.value });
  }
  excerpt() {
    return null;
  }
}

/** "Your 12-day flame goes out tonight" / "… is down to embers". */
export class StreakWarningNotification extends Notification {
  icon() {
    return 'fas fa-fire-flame-simple';
  }
  href() {
    return app.route('cadence.streaks');
  }
  content() {
    const data = this.attrs.notification.content() as any;
    return t(`warning.${data?.kind}`, { count: data?.value });
  }
  excerpt() {
    return null;
  }
}
