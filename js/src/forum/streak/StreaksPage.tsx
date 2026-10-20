import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Icon from 'flarum/common/components/Icon';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { lookFor, ownFlame, rules, t, tierFor, tierName } from '../../common/streak/flame';
import { bonfire, FlameBadge, miniUser } from '../../common/streak/components';
import { FlameCard } from '../../common/streak/panels';
import { board, type BoardEntry } from '../../common/streak/store';

declare const m: import('mithril').Static;

const BOARDS = ['current', 'alltime', 'hall'] as const;
const PAGE = 50;

/**
 * /streaks — the leaderboard: your flame and the rival just ahead, three
 * boards, and the forum's figures and bonfire beside them.
 *
 * Its own chunk: nothing here is in the bundle every page loads.
 */
export default class StreaksPage extends Page {
  tab: (typeof BOARDS)[number] = 'current';
  pages = 1;

  oninit(vnode: any) {
    super.oninit(vnode);
    const requested = m.route.param('board');
    if ((BOARDS as readonly string[]).includes(requested)) this.tab = requested as (typeof BOARDS)[number];
    app.setTitle(t('page_title'));
    app.setTitleCount(0);
  }

  view() {
    const first = board(this.tab, PAGE, 0);
    const own = ownFlame();
    const me = first.data?.me;

    return (
      <div className="StreaksPage">
        <div className="container">
          <header className="StreaksPage-hero">
            <h2>
              <Icon name="fas fa-fire" /> {t('page_title')}
            </h2>
            <p>{rules()?.mode === 'visit' ? t('page_intro_visit') : t('page_intro_contribute')}</p>
          </header>

          <div className="StreaksPage-layout">
            <main className="StreaksPage-main">
              {own && app.forum.attribute('canEarnStreaks') ? <FlameCard data={{ ...own, standing: me, own: true }} user={app.session.user} /> : null}

              <div className="StreaksPage-tabs" role="tablist">
                {BOARDS.map((name) => (
                  <button
                    type="button"
                    role="tab"
                    aria-selected={this.tab === name ? 'true' : 'false'}
                    className={'StreaksPage-tab' + (this.tab === name ? ' is-active' : '')}
                    onclick={() => {
                      this.tab = name;
                      this.pages = 1;
                    }}
                  >
                    {t(`board.${name}`)}
                  </button>
                ))}
              </div>

              {this.tab === 'hall' ? <p className="StreaksPage-note">{t('board.hall_intro')}</p> : null}

              {this.list()}
            </main>

            <aside className="StreaksPage-side">{this.sidebar(first.data?.stats)}</aside>
          </div>
        </div>
      </div>
    );
  }

  list() {
    const entries: BoardEntry[] = [];
    let loading = false;
    let more = false;

    for (let p = 0; p < this.pages; p++) {
      const page = board(this.tab, PAGE, p * PAGE);
      if (!page.data) {
        loading = !page.failed;
        break;
      }
      entries.push(...page.data.entries);
      more = page.data.entries.length === PAGE;
    }

    if (!entries.length && loading) return <LoadingIndicator />;
    if (!entries.length) return <p className="StreaksPage-empty">{t('board.empty')}</p>;

    const meId = app.session.user?.id();
    const fmt = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { dateStyle: 'medium' });

    return [
      <ol className="StreaksBoard">
        {entries.map((e) => {
          const look = lookFor(e.value);
          const status =
            this.tab === 'hall'
              ? e.current
                ? t('board.alight_now', { count: e.current })
                : t('board.flame_out')
              : e.current
                ? tierName(tierFor(e.current))
                : t('board.flame_out');

          return (
            <li className={'StreaksBoard-row' + (e.user.id === meId ? ' is-me' : '') + (e.rank <= 3 ? ` is-top is-top-${e.rank}` : '')}>
              <span className="StreaksBoard-rank">{e.rank}</span>
              <div className="StreaksBoard-who">
                {miniUser(e.user)}
                <span className="StreaksBoard-status" style={{ '--flame': look.color } as any}>
                  {status}
                  {e.when && this.tab === 'hall' ? ` · ${fmt.format(new Date(e.when))}` : ''}
                </span>
              </div>
              <span className="StreaksBoard-value">
                <FlameBadge days={e.value} />
                <small>{t('days_unit', { count: e.value })}</small>
              </span>
            </li>
          );
        })}
      </ol>,
      more ? (
        <div className="StreaksPage-more">
          <Button className="Button" loading={loading} onclick={() => this.pages++}>
            {t('board.load_more')}
          </Button>
        </div>
      ) : null,
    ];
  }

  sidebar(stats?: { alight: number; fedToday: number; best: number; bonfire: any }) {
    const r = rules();

    return [
      stats ? (
        <section className="StreaksSide">
          <h4>
            <Icon name="fas fa-fire" /> {t('side.title')}
          </h4>
          <dl className="StreaksSide-figures">
            <div>
              <dt>{t('side.alight')}</dt>
              <dd>{stats.alight}</dd>
            </div>
            <div>
              <dt>{t('side.fed_today')}</dt>
              <dd>{stats.fedToday}</dd>
            </div>
            <div>
              <dt>{t('side.best')}</dt>
              <dd>{stats.best}</dd>
            </div>
          </dl>
        </section>
      ) : null,
      stats ? bonfire(stats.fedToday, stats.bonfire) : null,
      <section className="StreaksSide">
        <h4>{t('side.tiers')}</h4>
        <ul className="StreaksTiers">
          {(r?.tiers || []).map((tier) => (
            <li style={{ '--flame': tier.color } as any} className={tier.glow ? 'has-glow' : ''}>
              <Icon name={tier.icon} />
              <span>{tierName(tier)}</span>
              <small>{t('side.from_days', { count: tier.days })}</small>
            </li>
          ))}
        </ul>
      </section>,
      <section className="StreaksSide">
        <h4>{t('side.how')}</h4>
        <ul className="StreaksHow">
          <li>{r?.mode === 'visit' ? t('how.visit') : t('how.contribute')}</li>
          {r?.grace ? <li>{t('how.grace', { count: r.grace })}</li> : null}
          {r?.freezeBank ? <li>{t('how.freezes', { count: r.freezeBank })}</li> : null}
          {r?.embersMin ? <li>{t('how.embers', { count: r.embersMin, hours: r.embersHours, share: r.embersShare + '%' })}</li> : null}
          <li>{t('how.timezone')}</li>
        </ul>
      </section>,
    ];
  }
}
