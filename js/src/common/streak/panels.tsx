import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { lib, lookFor, nextTier, t, tierFor, tierName } from './flame';
import { member, type MiniUser } from './store';
import { flameStyle, miniUser, tierProgress } from './components';

/*
 * 🚨 Everything in this file loads only with a streak page or the opened
 * header menu — never in the bundle every page downloads.
 */

/** The ring: days in the middle, the arc filling toward the next tier. */
export class FlameRing extends Component<{ days: number; size?: number; user?: any }> {
  view() {
    const days = this.attrs.days;
    const size = this.attrs.size || 84;
    const look = lookFor(days, this.attrs.user);
    const tier = tierFor(days);
    const next = nextTier(days);
    const from = tier?.days || 0;
    const share = next ? Math.max(0.04, (days - from) / Math.max(1, next.days - from)) : 1;
    const r = 15.5;
    const c = 2 * Math.PI * r;

    return (
      <div
        className={'FlameRing' + (look.glow && days ? ' FlameRing--glow' : '')}
        style={{ ...flameStyle(look.color), width: size + 'px', height: size + 'px' }}
      >
        <svg viewBox="0 0 36 36" aria-hidden="true">
          <circle className="FlameRing-track" cx="18" cy="18" r={r} />
          {days ? <circle className="FlameRing-arc" cx="18" cy="18" r={r} style={{ strokeDasharray: `${share * c} ${c}` }} /> : null}
        </svg>
        <div className="FlameRing-inner">
          <Icon name={days ? look.icon : 'fas fa-fire'} />
          <strong>{days}</strong>
          <small>{t('days_unit', { count: days })}</small>
        </div>
      </div>
    );
  }
}

const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (y: number, mo: number, d: number) => `${y}-${pad(mo)}-${pad(d)}`;

/** Monday-first weekday names in the viewer's own language. */
function weekdays(): string[] {
  const fmt = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { weekday: 'short' });
  // 2024-01-01 was a Monday.
  return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(2024, 0, 1 + i)));
}

/**
 * A month of the member's flame: fed days filled, days a freeze saved in ice,
 * today outlined. Pages back and forth one month at a time.
 */
export class FlameCalendar extends Component<{ userId: string; today: string; color?: string }> {
  year!: number;
  month!: number;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const [y, mo] = this.attrs.today.split('-').map(Number);
    this.year = y;
    this.month = mo;
  }

  view() {
    const key = `${this.year}-${pad(this.month)}`;
    const entry = member(this.attrs.userId, { month: key });
    const days = entry.data?.days || {};
    const first = new Date(this.year, this.month - 1, 1);
    const lead = (first.getDay() + 6) % 7;
    const count = new Date(this.year, this.month, 0).getDate();
    const isCurrent = key >= this.attrs.today.slice(0, 7);
    const title = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { month: 'long', year: 'numeric' }).format(first);

    const cells: Mithril.Children[] = [];
    for (let i = 0; i < lead; i++) cells.push(<span className="FlameCalendar-day is-blank" />);
    for (let d = 1; d <= count; d++) {
      const date = ymd(this.year, this.month, d);
      const state = days[date];
      const future = date > this.attrs.today;
      const label = state === 2 ? t('calendar.saved', { date }) : state === 1 ? t('calendar.fed', { date }) : date;
      cells.push(
        <span
          className={
            'FlameCalendar-day' +
            (state === 1 ? ' is-fed' : '') +
            (state === 2 ? ' is-saved' : '') +
            (date === this.attrs.today ? ' is-today' : '') +
            (future ? ' is-future' : '')
          }
          title={label}
          aria-label={label}
        >
          {d}
        </span>
      );
    }

    return (
      <div className="FlameCalendar" style={flameStyle(this.attrs.color || '#f97316')}>
        <div className="FlameCalendar-head">
          <button type="button" className="Button Button--icon Button--link" aria-label={t('calendar.previous')} onclick={() => this.move(-1)}>
            <Icon name="fas fa-chevron-left" />
          </button>
          <strong>{title}</strong>
          <button
            type="button"
            className="Button Button--icon Button--link"
            aria-label={t('calendar.next')}
            disabled={isCurrent}
            onclick={() => this.move(1)}
          >
            <Icon name="fas fa-chevron-right" />
          </button>
        </div>
        <div className="FlameCalendar-grid" role="grid" aria-busy={!entry.data && !entry.failed ? 'true' : 'false'}>
          {weekdays().map((w) => (
            <span className="FlameCalendar-dow">{w}</span>
          ))}
          {cells}
        </div>
      </div>
    );
  }

  move(by: number) {
    const d = new Date(this.year, this.month - 1 + by, 1);
    this.year = d.getFullYear();
    this.month = d.getMonth() + 1;
  }
}

/**
 * A year of the flame, week columns like the activity map: fed days in the
 * tier's colour, saved days in ice. A tab per year since the member joined.
 */
export class FlameYear extends Component<{ userId: string; today: string; firstYear: number; color?: string }> {
  year!: number;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.year = Number(this.attrs.today.slice(0, 4));
  }

  view() {
    const thisYear = Number(this.attrs.today.slice(0, 4));
    const years: number[] = [];
    for (let y = thisYear; y >= Math.max(this.attrs.firstYear, thisYear - 9); y--) years.push(y);

    const entry = member(this.attrs.userId, { year: this.year });
    const days = entry.data?.days || {};
    const fed = Object.values(days).filter((v) => v === 1).length;

    const start = new Date(this.year, 0, 1);
    const lead = (start.getDay() + 6) % 7;
    const weeks: Mithril.Children[] = [];
    let column: Mithril.Children[] = [];
    for (let i = 0; i < lead; i++) column.push(<span className="FlameYear-day is-blank" />);

    for (let d = new Date(start); d.getFullYear() === this.year; d.setDate(d.getDate() + 1)) {
      const date = ymd(d.getFullYear(), d.getMonth() + 1, d.getDate());
      const state = days[date];
      column.push(
        <span
          className={
            'FlameYear-day' + (state === 1 ? ' is-fed' : '') + (state === 2 ? ' is-saved' : '') + (date > this.attrs.today ? ' is-future' : '')
          }
          title={state === 2 ? t('calendar.saved', { date }) : state === 1 ? t('calendar.fed', { date }) : date}
        />
      );
      if (column.length === 7) {
        weeks.push(<div className="FlameYear-week">{column}</div>);
        column = [];
      }
    }
    if (column.length) weeks.push(<div className="FlameYear-week">{column}</div>);

    return (
      <div className="FlameYear" style={flameStyle(this.attrs.color || '#f97316')}>
        <div className="FlameYear-head">
          <span className="FlameYear-count">{entry.data ? t('active_days', { count: fed }) : ''}</span>
          {years.length > 1 ? (
            <div className="FlameYear-years" role="tablist">
              {years.map((y) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={y === this.year ? 'true' : 'false'}
                  className={'FlameYear-tab' + (y === this.year ? ' is-active' : '')}
                  onclick={() => (this.year = y)}
                >
                  {y}
                </button>
              ))}
            </div>
          ) : null}
        </div>
        <div className="FlameYear-grid">{entry.data ? weeks : <LoadingIndicator size="small" />}</div>
        <div className="FlameYear-legend">
          <span className="FlameYear-day is-fed" /> {t('legend_fed')}
          <span className="FlameYear-day is-saved" /> {t('legend_saved')}
        </div>
      </div>
    );
  }
}

const JOURNAL_ICONS: Record<string, string> = {
  milestone: 'fas fa-trophy',
  tier: 'fas fa-arrow-up',
  record: 'fas fa-medal',
  saved: 'fas fa-snowflake',
  ended: 'fas fa-wind',
  revived: 'fas fa-fire-flame-simple',
  given: 'fas fa-gift',
  edited: 'fas fa-pen',
  reset: 'fas fa-rotate-left',
};

/** The flame's journal: every milestone, tier, record, freeze and revival. */
export function flameJournal(entries: { type: string; value: number; data: any; at: string }[]) {
  if (!entries.length) return <p className="FlameJournal-empty">{lib('journal.empty')}</p>;

  const fmt = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { dateStyle: 'medium' });

  return (
    <ul className="FlameJournal">
      {entries.map((e) => (
        <li className={'FlameJournal-item FlameJournal-item--' + e.type}>
          <Icon name={JOURNAL_ICONS[e.type] || 'fas fa-fire'} />
          <div>
            <div className="FlameJournal-text">
              {lib(`journal.${e.type}`, { count: e.value, tier: e.data ? tierName({ key: e.data.key || '', name: e.data.name || '' }) : '' })}
            </div>
            <time dateTime={e.at}>{fmt.format(new Date(e.at))}</time>
          </div>
        </li>
      ))}
    </ul>
  );
}

export interface CardData {
  current: number;
  longest: number;
  total: number;
  freezes: number;
  startedOn: string | null;
  standing?: { rank: number | null; rival: { user: MiniUser; ahead: number } | null };
  embers?: number;
  embersUntil?: string | null;
  own?: boolean;
}

/** Hours and minutes left until an instant, for the embers countdown. */
function timeLeft(until: string): string {
  const ms = Math.max(0, new Date(until).getTime() - Date.now());
  const h = Math.floor(ms / 3600000);
  return t('time_left', { hours: h, minutes: Math.floor((ms % 3600000) / 60000) });
}

/**
 * The flame card: the ring, the tier, rank and start date, the road to the
 * next tier, who is just ahead, and the three numbers that matter.
 */
export class FlameCard extends Component<{ data: CardData; user?: any; title?: string }> {
  view() {
    const d = this.attrs.data;
    const tier = tierFor(d.current);
    const look = lookFor(d.current, this.attrs.user);
    const fmt = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { dateStyle: 'medium' });
    const meta: string[] = [t('days_count', { count: d.current })];
    if (d.standing?.rank) meta.push(t('rank', { rank: d.standing.rank }));
    if (d.startedOn) meta.push(t('started', { date: fmt.format(new Date(d.startedOn + 'T12:00:00')) }));

    // The rival is a nudge for the member themselves; on anyone else's card
    // "X is 284 days ahead of you" would be about the wrong person.
    const rival = d.own ? d.standing?.rival : null;

    return (
      <div className="FlameCard" style={flameStyle(look.color)}>
        <div className="FlameCard-main">
          <FlameRing days={d.current} user={this.attrs.user} />
          <div className="FlameCard-body">
            <div className="FlameCard-kicker">{this.attrs.title || t('your_flame')}</div>
            <h3 className="FlameCard-tier">{d.current ? tierName(tier) : t('no_flame')}</h3>
            {look.occasion && d.current ? <div className="FlameCard-occasion">{look.occasion}</div> : null}
            <div className="FlameCard-meta">{meta.join(' · ')}</div>
            {d.current ? tierProgress(d.current) : null}
            {rival ? (
              <div className="FlameCard-rival">
                <Icon name="fas fa-flag" />{' '}
                {rival.ahead > 0
                  ? app.translator.trans('ernestdefoe-cadence.forum.streak.rival_ahead', { member: miniUser(rival.user), count: rival.ahead })
                  : app.translator.trans('ernestdefoe-cadence.forum.streak.rival_level', { member: miniUser(rival.user) })}
              </div>
            ) : null}
          </div>
        </div>
        {d.own && d.embers && d.embersUntil ? (
          <div className="FlameCard-embers" role="status">
            <Icon name="fas fa-fire-flame-simple" />
            <div>
              <strong>{t('embers_title', { count: d.embers })}</strong>
              <div>
                {t('embers_body', {
                  share: (app.forum.attribute<any>('cadenceStreakRules')?.embersShare ?? 50) + '%',
                  left: timeLeft(d.embersUntil),
                })}
              </div>
            </div>
          </div>
        ) : null}
        <dl className="FlameCard-stats">
          <div>
            <dt>{t('longest')}</dt>
            <dd>{d.longest}</dd>
          </div>
          <div>
            <dt>{t('total_days')}</dt>
            <dd>{d.total}</dd>
          </div>
          <div>
            <dt>{t('freezes')}</dt>
            <dd>
              <Icon name="fas fa-snowflake" /> {d.freezes}
            </dd>
          </div>
        </dl>
      </div>
    );
  }
}

/**
 * A member's whole flame: the card, the year, the month and — to its owner
 * and to admins — the journal. Used by the profile's Streak tab.
 */
export class FlamePanel extends Component<{ user: any }> {
  view() {
    const user = this.attrs.user;
    const today = new Date();
    const entry = member(user.id(), { month: `${today.getFullYear()}-${pad(today.getMonth() + 1)}` });

    if (entry.failed) return null;
    if (!entry.data) return <LoadingIndicator />;

    const d = entry.data;
    const own = app.session.user?.id() === user.id();
    const color = lookFor(d.current, user).color;

    return (
      <div className="FlamePanel">
        <FlameCard data={{ ...d, own }} user={user} title={own ? t('your_flame') : t('their_flame', { name: user.displayName() })} />
        <section className="FlamePanel-section">
          <h4>{t('activity_log')}</h4>
          <FlameYear userId={user.id()} today={d.today} firstYear={d.firstYear} color={color} />
        </section>
        <div className="FlamePanel-columns">
          <section className="FlamePanel-section">
            <h4>{t('calendar_title')}</h4>
            <FlameCalendar userId={user.id()} today={d.today} color={color} />
          </section>
          {d.journal ? (
            <section className="FlamePanel-section">
              <h4>{t('journal_title')}</h4>
              {flameJournal(d.journal)}
            </section>
          ) : null}
        </div>
      </div>
    );
  }
}
