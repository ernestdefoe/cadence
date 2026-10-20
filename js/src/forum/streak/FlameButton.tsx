import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { localToday, lookFor, ownFlame, t, tierFor, tierName } from '../../common/streak/flame';
import { celebrate } from './celebrate';

declare const m: import('mithril').Static;

/** The menu's contents, fetched as their own chunk the first time it opens. */
let Menu: any = null;

/**
 * The flame in the header, beside notifications: the count at a glance, and
 * one tap for the streak, the next tier and the month.
 *
 * 🚨 `lazyDraw`: the menu — and the month it fetches — exists only once it is
 * opened. Closed, this is an icon and a number read off the session user.
 */
export default class FlameButton extends Component {
  view() {
    const flame = ownFlame();
    const days = flame?.current || 0;
    const look = lookFor(days, app.session.user);
    const atRisk = days > 0 && flame?.lastDay !== localToday();
    const label = days ? t('badge_label', { count: days, tier: tierName(tierFor(days)) }) : t('no_flame');

    return (
      <Dropdown
        className={'FlameDropdown' + (days ? '' : ' is-out') + (atRisk ? ' is-at-risk' : '')}
        buttonClassName="Button Button--flat FlameDropdown-button"
        menuClassName="Dropdown-menu--right FlameDropdown-menu"
        accessibleToggleLabel={label}
        lazyDraw
        onshow={() => {
          if (!Menu) {
            import('./FlameMenu').then((mod) => {
              Menu = mod.default;
              m.redraw();
            });
          }
        }}
        label={
          <span className="FlameDropdown-label" style={{ '--flame': look.color } as any} title={label}>
            <Icon name={look.icon} />
            <span className="FlameDropdown-count">{days}</span>
          </span>
        }
      >
        <li className="FlameDropdown-panel">{Menu ? <Menu days={days} color={look.color} /> : <LoadingIndicator size="small" />}</li>
      </Dropdown>
    );
  }

  oncreate(vnode: any) {
    super.oncreate(vnode);
    celebrate();
  }

  // A post that reaches a milestone brings the fresh flame back in its
  // response; the header is the one thing on every page to notice.
  onupdate() {
    celebrate();
  }
}
