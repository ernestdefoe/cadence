import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';

declare const m: import('mithril').Static;

const t = (k: string, a: Record<string, unknown> = {}) => extractText(app.translator.trans(`ernestdefoe-cadence.admin.streak.${k}`, a));
const api = (path: string) => `${app.forum.attribute('apiUrl')}${path}`;

/**
 * The whole board at a glance — key figures, flames by tier, the longest
 * alight, recent events — and the flame sheet: find a member, then give
 * freezes, set the length, revive or reset. Every change is journaled.
 */
export default class Overview extends Component {
  data: any = null;
  failed = false;

  query = '';
  results: any[] = [];
  member: any = null;
  memberFlame: any = null;
  busy = false;
  amount = 1;
  length = 0;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    app
      .request<{ data: any }>({ method: 'GET', url: api('/cadence/streaks-overview') })
      .then((res) => (this.data = res.data))
      .catch(() => (this.failed = true))
      .finally(() => m.redraw());
  }

  view() {
    if (this.failed) return null;
    if (!this.data) return <LoadingIndicator />;

    const d = this.data;
    const total = d.tiers.reduce((sum: number, tier: any) => sum + tier.count, 0) || 1;
    const fmt = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { dateStyle: 'medium', timeStyle: 'short' });

    return (
      <div className="CadenceAdmin-section CadenceOverview">
        <h3>{t('overview.title')}</h3>
        <dl className="CadenceOverview-figures">
          {['tracked', 'alight', 'fedToday', 'best', 'freezes', 'embers'].map((key) => (
            <div>
              <dt>{t(`overview.${key}`)}</dt>
              <dd>{d.figures[key]}</dd>
            </div>
          ))}
        </dl>

        <h4>{t('overview.by_tier')}</h4>
        <ul className="CadenceOverview-tiers">
          {d.tiers.map((tier: any) => (
            <li style={{ '--flame': tier.color } as any}>
              <span className="CadenceOverview-tierName">
                <Icon name={tier.icon} /> {tier.name || extractText(app.translator.trans(`ernestdefoe-cadence.lib.streak.tiers.${tier.key}`))}
                <small> {t('overview.from_days', { count: tier.days })}</small>
              </span>
              <span className="CadenceOverview-bar">
                <span style={{ width: Math.round((tier.count / total) * 100) + '%' }} />
              </span>
              <span className="CadenceOverview-count">{tier.count}</span>
            </li>
          ))}
        </ul>

        <div className="CadenceOverview-columns">
          <div>
            <h4>{t('overview.longest')}</h4>
            <ol className="CadenceOverview-list">
              {d.longest.map((e: any) => (
                <li>
                  <a onclick={() => this.pick({ id: e.user.id, displayName: e.user.displayName })}>{e.user.displayName}</a>
                  <span>{t('overview.days', { count: e.value })}</span>
                </li>
              ))}
            </ol>
          </div>
          <div>
            <h4>{t('overview.events')}</h4>
            <ul className="CadenceOverview-list">
              {d.events.map((e: any) =>
                e.user ? (
                  <li>
                    <span>
                      <a onclick={() => this.pick({ id: e.user.id, displayName: e.user.displayName })}>{e.user.displayName}</a>{' '}
                      {extractText(
                        app.translator.trans(`ernestdefoe-cadence.lib.streak.journal.${e.type}`, {
                          count: e.value,
                          tier:
                            e.data?.name ||
                            (e.data?.key ? extractText(app.translator.trans(`ernestdefoe-cadence.lib.streak.tiers.${e.data.key}`)) : ''),
                        })
                      )}
                      {e.actor ? <small> · {t('overview.by', { name: e.actor.displayName })}</small> : null}
                    </span>
                    <time>{fmt.format(new Date(e.at))}</time>
                  </li>
                ) : null
              )}
            </ul>
          </div>
        </div>

        {this.sheet()}
      </div>
    );
  }

  sheet() {
    return (
      <div className="CadenceSheet">
        <h4>{t('sheet.title')}</h4>
        <p className="helpText">{t('sheet.help')}</p>
        <input
          className="FormControl"
          placeholder={t('sheet.search')}
          value={this.query}
          oninput={(e: any) => {
            this.query = e.target.value;
            this.search();
          }}
        />
        {this.results.length ? (
          <ul className="CadenceSheet-results">
            {this.results.map((u: any) => (
              <li>
                <a onclick={() => this.pick({ id: u.id(), displayName: u.displayName() })}>{u.displayName()}</a>
              </li>
            ))}
          </ul>
        ) : null}

        {this.member ? (
          <div className="CadenceSheet-member">
            <h5>{this.member.displayName}</h5>
            {this.memberFlame ? (
              <dl className="CadenceOverview-figures">
                {['current', 'longest', 'total', 'freezes'].map((key) => (
                  <div>
                    <dt>{t(`sheet.${key}`)}</dt>
                    <dd>{this.memberFlame[key]}</dd>
                  </div>
                ))}
              </dl>
            ) : (
              <LoadingIndicator size="small" />
            )}
            <div className="CadenceSheet-actions">
              <span>
                <input
                  className="FormControl CadenceAdmin-num"
                  type="number"
                  min="1"
                  max="20"
                  value={this.amount}
                  oninput={(e: any) => (this.amount = +e.target.value || 1)}
                />
                <Button className="Button" icon="fas fa-snowflake" loading={this.busy} onclick={() => this.act('give', this.amount)}>
                  {t('sheet.give')}
                </Button>
              </span>
              <span>
                <input
                  className="FormControl CadenceAdmin-num"
                  type="number"
                  min="0"
                  value={this.length}
                  oninput={(e: any) => (this.length = Math.max(0, +e.target.value || 0))}
                />
                <Button className="Button" icon="fas fa-pen" loading={this.busy} onclick={() => this.act('edit', this.length)}>
                  {t('sheet.edit')}
                </Button>
              </span>
              <Button className="Button" icon="fas fa-fire-flame-simple" loading={this.busy} onclick={() => this.act('revive', 0)}>
                {t('sheet.revive')}
              </Button>
              <Button
                className="Button Button--danger"
                icon="fas fa-rotate-left"
                loading={this.busy}
                onclick={() => confirm(t('sheet.reset_confirm')) && this.act('reset', 0)}
              >
                {t('sheet.reset')}
              </Button>
            </div>
          </div>
        ) : null}
      </div>
    );
  }

  private timer: any = null;

  search() {
    clearTimeout(this.timer);
    const q = this.query.trim();
    if (q.length < 2) {
      this.results = [];
      return;
    }
    this.timer = setTimeout(() => {
      app.store.find<any>('users', { filter: { q }, page: { limit: 8 } }).then((users: any) => {
        this.results = users;
        m.redraw();
      });
    }, 250);
  }

  pick(user: { id: string; displayName: string }) {
    this.member = user;
    this.memberFlame = null;
    this.results = [];
    app
      .request<{ data: any }>({ method: 'GET', url: api(`/cadence/streaks/${user.id}`) })
      .then((res) => {
        this.memberFlame = res.data;
        this.length = res.data.current;
      })
      .finally(() => m.redraw());
  }

  act(action: string, value: number) {
    this.busy = true;
    app
      .request<{ data: any }>({ method: 'POST', url: api(`/cadence/streaks/${this.member.id}/manage`), body: { action, value } })
      .then((res) => {
        this.memberFlame = {
          ...this.memberFlame,
          current: res.data.current,
          longest: res.data.longest,
          total: res.data.total,
          freezes: res.data.freezes,
        };
        app.alerts.show({ type: 'success' }, t('sheet.done'));
        this.load();
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
