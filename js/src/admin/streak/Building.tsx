import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';

declare const m: import('mithril').Static;

const t = (k: string, a: Record<string, unknown> = {}) => extractText(app.translator.trans(`ernestdefoe-cadence.admin.backfill.${k}`, a));

type Status = { pending: boolean; stage: string | null; percent: number | null };

/**
 * "Building streaks: 42%" while the history rebuild runs in the background.
 *
 * The first state comes from the saved setting, so a forum with nothing
 * pending shows nothing and makes no request. While one is pending, it polls
 * every few seconds — and on a forum with no queue and no cron, each poll is
 * also what moves the rebuild along (see BackfillController).
 */
export default class Building extends Component {
  status: Status | null = null;
  timer: number | undefined;

  oninit(vnode: any) {
    super.oninit(vnode);

    let saved: any = null;
    try {
      saved = JSON.parse(String(app.data.settings['ernestdefoe-cadence.backfill'] || 'null'));
    } catch (e) {}

    if (saved?.stages?.length) {
      this.status = { pending: true, stage: saved.stages[0], percent: null };
      this.poll();
    }
  }

  onremove(vnode: any) {
    super.onremove(vnode);
    window.clearTimeout(this.timer);
  }

  poll() {
    app
      .request<Status>({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/cadence/backfill` })
      .then((status) => {
        this.status = status;
        if (status.pending) this.timer = window.setTimeout(() => this.poll(), 3000);
      })
      .catch(() => (this.timer = window.setTimeout(() => this.poll(), 10000)))
      .finally(() => m.redraw());
  }

  view() {
    const s = this.status;
    if (!s) return null;

    if (!s.pending) {
      return (
        <p className="CadenceAdmin-building is-done">
          <Icon name="fas fa-check" /> {t('done')}
        </p>
      );
    }

    return (
      <div className="CadenceAdmin-building">
        <p>
          <Icon name="fas fa-spinner fa-spin" /> {t(s.stage === 'activity' ? 'activity' : 'streaks', { percent: s.percent ?? 0 })}
        </p>
        <div className="CadenceAdmin-buildingBar">
          <span style={{ width: `${s.percent ?? 0}%` }} />
        </div>
        <p className="helpText">{t('help')}</p>
      </div>
    );
  }
}
