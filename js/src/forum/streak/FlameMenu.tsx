import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import { localToday, lookFor, ownFlame, t, tierFor, tierName } from '../../common/streak/flame';
import { tierProgress } from '../../common/streak/components';
import { FlameCalendar } from '../../common/streak/panels';

/** The header flame's menu: the streak, the next tier, the month, two links. */
export default class FlameMenu extends Component<{ days: number; color: string }> {
  view() {
    const { days, color } = this.attrs;
    const flame = ownFlame();
    const user = app.session.user!;

    return (
      <div className="FlamePanelMini" style={{ '--flame': color } as any}>
        <div className="FlamePanelMini-head">{t('your_daily_streak')}</div>
        <div className="FlamePanelMini-summary">
          <span className="FlamePanelMini-icon">
            <Icon name={lookFor(days, user).icon} />
          </span>
          <div>
            <strong>{days ? t('day_flame', { count: days }) : t('no_flame')}</strong>
            <div className="FlamePanelMini-tier">{tierName(tierFor(days))}</div>
          </div>
          <div className="FlamePanelMini-side">
            <span title={t('longest')}>
              <Icon name="fas fa-trophy" /> {flame?.longest || 0}
            </span>
            <span title={t('freezes')}>
              <Icon name="fas fa-snowflake" /> {flame?.freezes || 0}
            </span>
          </div>
        </div>
        {days ? tierProgress(days) : null}
        {days && flame?.lastDay !== localToday() ? <div className="FlamePanelMini-risk">{t('feed_today')}</div> : null}
        <FlameCalendar userId={user.id()!} today={localToday()} color={color} />
        <div className="FlamePanelMini-links">
          <LinkButton icon="fas fa-fire" href={app.route('user.streak', { username: user.slug() })}>
            {t('your_streak_page')}
          </LinkButton>
          <LinkButton icon="fas fa-ranking-star" href={app.route('cadence.streaks')}>
            {t('leaderboard')}
          </LinkButton>
        </div>
      </div>
    );
  }
}
