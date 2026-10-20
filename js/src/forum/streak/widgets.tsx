import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { lookFor, ownFlame, t, tierFor, tierName } from '../../common/streak/flame';
import { bonfire, FlameBadge, miniUser, tierProgress } from '../../common/streak/components';
import { board } from '../../common/streak/store';

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

const WIDGETS = [
  { key: 'cadenceMyFlame', type: 'cadence-my-flame', label: 'widgets.my_flame', icon: 'fas fa-fire', component: MyFlameWidget },
  { key: 'cadenceTopFlames', type: 'cadence-top-flames', label: 'widgets.top_flames', icon: 'fas fa-ranking-star', component: TopFlamesWidget },
  { key: 'cadenceBonfire', type: 'cadence-bonfire', label: 'side.bonfire', icon: 'fas fa-campground', component: BonfireWidget },
];

export function hasFofWidgets(): boolean {
  return typeof flarum !== 'undefined' && !!flarum?.extensions && 'fof-forum-widgets-core' in flarum.extensions;
}

/**
 * Register with every widget host that is present — Bespoke and
 * fof/forum-widgets-core — resolved at runtime, never imported, so the bundle
 * is the same whether either is installed.
 */
export function registerWidgets(): void {
  const g = globalThis as any;

  if (g.flarum?.extensions && 'ernestdefoe-bespoke' in g.flarum.extensions) {
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    WIDGETS.forEach((w) =>
      g.BespokeWidgetQueue.push({
        type: w.type,
        // 🚨 A translation KEY: Bespoke resolves it, and calls string methods on it.
        label: `ernestdefoe-cadence.forum.streak.${w.label}`,
        icon: w.icon,
        zones: ['sidebar', 'above-list', 'below-list', 'footer'],
        schema: [],
        component: { view: () => m(w.component) },
      })
    );
  }

  if (hasFofWidgets()) {
    const widgetsMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/extend/Widgets');
    const widgetMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/components/Widget');
    const Widgets = widgetsMod?.default ?? widgetsMod;
    const WidgetBase = (widgetMod?.default ?? widgetMod) as { new (...args: any[]): any } | undefined;
    if (!Widgets || !WidgetBase) return;

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
          return t(w.label);
        }
        content() {
          return m(w.component);
        }
      }

      widgets.add({
        key: w.key,
        component: FlameFofWidget,
        title: () => t(w.label),
        icon: w.icon,
        isDisabled: false,
        isUnique: true,
        placement: 'end',
        position,
      });
    });
    widgets.extend(app, 'ernestdefoe-cadence');
  }
}
