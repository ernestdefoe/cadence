import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { lookFor, ownFlame, t, tierFor, tierName } from '../../common/streak/flame';
import { bonfire, FlameBadge, miniUser, tierProgress } from '../../common/streak/components';
import { board } from '../../common/streak/store';
import { EXTENSION, fof, placed, WIDGETS } from '../../common/streak/widgetHosts';

declare const flarum: any;
declare const m: import('mithril').Static;

/** My flame: the member's own, from the session user — no request. */
export class MyFlameWidget extends Component {
  view() {
    const flame = ownFlame();
    if (!app.session.user || !flame || !app.forum.attribute('canEarnStreaks')) return null;

    const days = flame.current;
    const look = lookFor(days, app.session.user);

    return (
      <div className="FlameWidget FlameWidget--mine" style={{ '--flame': look.color } as any}>
        <h4 className="FlameWidget-title">{t('widgets.my_flame')}</h4>
        <div className="FlameWidget-mine">
          <span className="FlamePanelMini-icon">
            <Icon name={look.icon} />
          </span>
          <div>
            <strong>{days ? t('days_count', { count: days }) : t('no_flame')}</strong>
            <div className="FlamePanelMini-tier">{tierName(tierFor(days))}</div>
          </div>
        </div>
        {days ? tierProgress(days) : null}
        <dl className="FlameWidget-figures">
          <dt>{t('longest')}</dt>
          <dd>{flame.longest}</dd>
          <dt>{t('freezes')}</dt>
          <dd>{flame.freezes}</dd>
        </dl>
        <LinkButton className="FlameWidget-link" href={app.route('user.streak', { username: app.session.user.slug() })}>
          {t('your_streak_page')}
        </LinkButton>
      </div>
    );
  }
}

/** Top flames: the five longest alight. One small shared request. */
export class TopFlamesWidget extends Component {
  view() {
    const entry = board('current', 5, 0, { stats: '0', me: '0' });

    return (
      <div className="FlameWidget">
        <h4 className="FlameWidget-title">{t('widgets.top_flames')}</h4>
        {!entry.data ? (
          entry.failed ? null : (
            <LoadingIndicator size="small" />
          )
        ) : entry.data.entries.length ? (
          <ol className="FlameWidget-list">
            {entry.data.entries.map((e) => (
              <li className={e.user.id === app.session.user?.id() ? 'is-me' : ''}>
                <span className="FlameWidget-rank">{e.rank}</span>
                {miniUser(e.user)}
                <FlameBadge days={e.value} />
              </li>
            ))}
          </ol>
        ) : (
          <p className="FlameWidget-empty">{t('board.empty')}</p>
        )}
        <LinkButton className="FlameWidget-link" href={app.route('cadence.streaks')}>
          {t('leaderboard')}
        </LinkButton>
      </div>
    );
  }
}

/** The bonfire: how many flames the forum fed today. */
export class BonfireWidget extends Component {
  view() {
    const entry = board('current', 1, 0, { me: '0' });
    const stats = entry.data?.stats;
    return stats ? <div className="FlameWidget FlameWidget--bonfire">{bonfire(stats.fedToday, stats.bonfire)}</div> : null;
  }
}

const COMPONENTS: Record<string, any> = {
  cadenceMyFlame: MyFlameWidget,
  cadenceTopFlames: TopFlamesWidget,
  cadenceBonfire: BonfireWidget,
};

/**
 * Whether a widget has anything to show this viewer. FoF draws its column for
 * any widget it is given, so one that would render nothing is not given.
 */
const hasContent: Record<string, () => boolean> = {
  cadenceMyFlame: () => !!app.session.user && !!ownFlame() && !!app.forum.attribute('canEarnStreaks'),
  cadenceTopFlames: () => true,
  cadenceBonfire: () => !!board('current', 1, 0, { me: '0' }).data?.stats,
};

export function hasFofWidgets(): boolean {
  return typeof flarum !== 'undefined' && !!flarum?.extensions && 'fof-forum-widgets-core' in flarum.extensions;
}

/**
 * Register with every widget host that is present — Bespoke and
 * fof/forum-widgets-core — resolved at runtime, never imported, so the bundle
 * is the same whether either is installed. Only called while streaks are on.
 */
export function registerWidgets(): void {
  const g = globalThis as any;

  if (g.flarum?.extensions && 'ernestdefoe-bespoke' in g.flarum.extensions) {
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    WIDGETS.forEach((w) =>
      g.BespokeWidgetQueue.push({
        type: w.type,
        // 🚨 A translation KEY: Bespoke resolves it, and calls string methods on it.
        label: `ernestdefoe-cadence.forum.streak.${w.key === 'cadenceBonfire' ? 'side.bonfire' : `widgets.${w.label}`}`,
        icon: w.icon,
        zones: ['sidebar', 'above-list', 'below-list', 'footer'],
        schema: [],
        component: { view: () => m(COMPONENTS[w.key]) },
      })
    );
  }

  if (hasFofWidgets()) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget') as { new (...args: any[]): any } | undefined;
    if (!Widgets || !WidgetBase || !(app as any).widgets) return;

    const title = (w: (typeof WIDGETS)[number]) => t(w.key === 'cadenceBonfire' ? 'side.bonfire' : `widgets.${w.label}`);
    const widgets = new Widgets();

    WIDGETS.forEach((w, position) => {
      class FlameFofWidget extends WidgetBase {
        className() {
          return 'CadenceFofWidget FofWidgets-Widget--flat';
        }
        header() {
          return null;
        }
        icon() {
          return w.icon;
        }
        title() {
          return title(w);
        }
        content() {
          return m(COMPONENTS[w.key]);
        }
      }

      widgets.add({
        key: w.key,
        component: FlameFofWidget,
        title: () => title(w),
        icon: w.icon,
        // Shown only where the admin placed it, and only with something in it.
        isDisabled: () => !placed((app as any).widgets.config, w.key) || !hasContent[w.key](),
        isUnique: true,
        placement: 'end',
        position,
      });
    });
    widgets.extend(app, EXTENSION);

    // FoF drew its sections before this ran; a placed widget appears now.
    m.redraw();
  }
}
