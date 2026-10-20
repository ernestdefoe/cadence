import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import stringToColor from 'flarum/common/utils/stringToColor';
import { lookFor, nextTier, t, tierFor, tierName } from './flame';
import type { MiniUser } from './store';

declare const m: import('mithril').Static;

/** A flame's colour as a CSS custom property, so the stylesheet stays theme-led. */
export function flameStyle(color: string) {
  return { '--flame': color } as any;
}

/**
 * The flame after a name: icon and number, in the tier's colour.
 *
 * Reads only what it is handed. It appears beside every post author, so it
 * never fetches anything.
 */
export class FlameBadge extends Component<{ days: number; user?: any; className?: string }> {
  view() {
    const days = this.attrs.days;
    if (!days) return null;

    const look = lookFor(days, this.attrs.user);
    const tier = tierFor(days);
    const label = t('badge_label', { count: days, tier: tierName(tier) }) + (look.occasion ? ` · ${look.occasion}` : '');

    return (
      <span
        className={'FlameBadge' + (look.glow ? ' FlameBadge--glow' : '') + ' ' + (this.attrs.className || '')}
        style={flameStyle(look.color)}
        title={label}
        aria-label={label}
        role="img"
      >
        <Icon name={look.icon} />
        <span className="FlameBadge-count" aria-hidden="true">
          {days}
        </span>
      </span>
    );
  }
}

/** A bar toward the next tier, with how many days are left. */
export function tierProgress(days: number) {
  const next = nextTier(days);
  const from = tierFor(days)?.days || 0;
  if (!next) return <div className="FlameProgress is-top">{t('top_tier')}</div>;

  const share = Math.min(1, Math.max(0, (days - from) / Math.max(1, next.days - from)));

  return (
    <div className="FlameProgress" style={flameStyle(next.color)}>
      <div className="FlameProgress-bar">
        <span style={{ width: Math.round(share * 100) + '%' }} />
      </div>
      <div className="FlameProgress-label">{t('days_to_tier', { count: next.days - days, tier: tierName(next) })}</div>
    </div>
  );
}

/** A small member line: avatar and name, linked to their profile. */
export function miniUser(user: MiniUser) {
  const href = app.route('user', { username: user.slug });
  // Avatar reads only these three; the board sends a slim user, not a model.
  const stub = {
    avatarUrl: () => user.avatarUrl,
    displayName: () => user.displayName,
    username: () => user.username,
    color: () => '#' + stringToColor(user.displayName),
  };

  return (
    <Link className="FlameUser" href={href}>
      <Avatar user={stub as any} />
      <span className="FlameUser-name">{user.displayName}</span>
    </Link>
  );
}
/** The community bonfire: every flame fed today feeds it. */
export function bonfire(fed: number, b: { level: number; levels: number; toNext: number; progress: number }) {
  return (
    <section className="StreaksSide StreaksBonfire">
      <h4>{t('side.bonfire')}</h4>
      <div className="StreaksBonfire-body">
        <div className={'StreaksBonfire-fire is-level-' + Math.min(4, b.level)} aria-hidden="true">
          <Icon name="fas fa-fire" />
        </div>
        <div>
          <strong className="StreaksBonfire-count">{fed}</strong>
          <div>{t('bonfire.fed', { count: fed })}</div>
          <div className="FlameProgress-bar">
            <span style={{ width: Math.round(b.progress * 100) + '%' }} />
          </div>
          <small>{b.toNext > 0 ? t('bonfire.to_next', { count: b.toNext, level: b.level + 1 }) : t('bonfire.max')}</small>
        </div>
      </div>
    </section>
  );
}
