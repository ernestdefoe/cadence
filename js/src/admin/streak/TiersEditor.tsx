import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Switch from 'flarum/common/components/Switch';
import saveSettings from 'flarum/admin/utils/saveSettings';
import extractText from 'flarum/common/utils/extractText';

declare const m: import('mithril').Static;

const t = (k: string, a: Record<string, unknown> = {}) => extractText(app.translator.trans(`ernestdefoe-cadence.admin.streak.${k}`, a));

interface TierRow {
  key: string;
  name: string;
  days: number;
  color: string;
  icon: string;
  glow: boolean;
  enabled: boolean;
}

interface SeasonRow {
  name: string;
  from: string;
  to: string;
  color: string;
  icon: string;
}

const DEFAULT_TIERS: TierRow[] = [
  { key: 'spark', name: '', days: 1, color: '#f97316', icon: 'fas fa-fire', glow: false, enabled: true },
  { key: 'kindled', name: '', days: 7, color: '#ef4444', icon: 'fas fa-fire', glow: false, enabled: true },
  { key: 'blaze', name: '', days: 30, color: '#3b82f6', icon: 'fas fa-fire-flame-curved', glow: false, enabled: true },
  { key: 'inferno', name: '', days: 100, color: '#a855f7', icon: 'fas fa-fire-flame-curved', glow: true, enabled: true },
  { key: 'eternal', name: '', days: 365, color: '#f59e0b', icon: 'fas fa-crown', glow: true, enabled: true },
];

function parse<T>(key: string, fallback: T): T {
  try {
    const value = JSON.parse(app.data.settings[`ernestdefoe-cadence.${key}`] || 'null');
    return Array.isArray(value) && value.length ? (value as T) : fallback;
  } catch {
    return fallback;
  }
}

/**
 * Tiers and seasons, each row with a live preview of the flame it makes.
 * Saved as two JSON settings; the server validates every field again.
 */
export default class TiersEditor extends Component {
  tiers: TierRow[] = [];
  seasons: SeasonRow[] = [];
  saving = false;
  dirty = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.tiers = parse('streak_tiers', DEFAULT_TIERS).map((x: TierRow) => ({ ...x }));
    this.seasons = parse<SeasonRow[]>('streak_seasons', []).map((x) => ({ ...x }));
  }

  view() {
    return (
      <div className="CadenceAdmin-section">
        <h3>{t('tiers.title')}</h3>
        <p className="helpText">{t('tiers.help')}</p>
        <table className="CadenceAdmin-table">
          <thead>
            <tr>
              <th>{t('tiers.preview')}</th>
              <th>{t('tiers.name')}</th>
              <th>{t('tiers.days')}</th>
              <th>{t('tiers.color')}</th>
              <th>{t('tiers.icon')}</th>
              <th>{t('tiers.glow')}</th>
              <th>{t('tiers.enabled')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {this.tiers.map((tier, i) => (
              <tr>
                <td>{this.preview(tier.color, tier.icon, tier.glow, tier.days)}</td>
                <td>
                  <input
                    className="FormControl"
                    value={tier.name}
                    placeholder={extractText(app.translator.trans(`ernestdefoe-cadence.lib.streak.tiers.${tier.key}`)) || ''}
                    maxlength={40}
                    oninput={(e: any) => this.set(() => (tier.name = e.target.value))}
                  />
                </td>
                <td>
                  <input
                    className="FormControl CadenceAdmin-num"
                    type="number"
                    min="1"
                    value={tier.days}
                    oninput={(e: any) => this.set(() => (tier.days = Math.max(1, +e.target.value || 1)))}
                  />
                </td>
                <td>
                  <input
                    className="CadenceAdmin-color"
                    type="color"
                    value={tier.color}
                    oninput={(e: any) => this.set(() => (tier.color = e.target.value))}
                  />
                </td>
                <td>
                  <input className="FormControl" value={tier.icon} oninput={(e: any) => this.set(() => (tier.icon = e.target.value))} />
                </td>
                <td>{Switch.component({ state: tier.glow, onchange: (v: boolean) => this.set(() => (tier.glow = v)) })}</td>
                <td>{Switch.component({ state: tier.enabled, onchange: (v: boolean) => this.set(() => (tier.enabled = v)) })}</td>
                <td>
                  <Button
                    className="Button Button--icon Button--link"
                    icon="fas fa-trash"
                    aria-label={t('remove')}
                    onclick={() => this.set(() => this.tiers.splice(i, 1))}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <Button
          className="Button"
          icon="fas fa-plus"
          onclick={() =>
            this.set(() =>
              this.tiers.push({
                key: 'tier' + Date.now().toString(36),
                name: '',
                days: (this.tiers[this.tiers.length - 1]?.days || 0) + 30,
                color: '#f97316',
                icon: 'fas fa-fire',
                glow: false,
                enabled: true,
              })
            )
          }
        >
          {t('tiers.add')}
        </Button>

        <h3>{t('seasons.title')}</h3>
        <p className="helpText">{t('seasons.help')}</p>
        {this.seasons.length ? (
          <table className="CadenceAdmin-table">
            <thead>
              <tr>
                <th>{t('tiers.preview')}</th>
                <th>{t('tiers.name')}</th>
                <th>{t('seasons.from')}</th>
                <th>{t('seasons.to')}</th>
                <th>{t('tiers.color')}</th>
                <th>{t('tiers.icon')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {this.seasons.map((season, i) => (
                <tr>
                  <td>{this.preview(season.color, season.icon, true, 12)}</td>
                  <td>
                    <input
                      className="FormControl"
                      value={season.name}
                      maxlength={40}
                      oninput={(e: any) => this.set(() => (season.name = e.target.value))}
                    />
                  </td>
                  <td>
                    <input
                      className="FormControl CadenceAdmin-md"
                      value={season.from}
                      placeholder="12-01"
                      pattern="\d\d-\d\d"
                      oninput={(e: any) => this.set(() => (season.from = e.target.value))}
                    />
                  </td>
                  <td>
                    <input
                      className="FormControl CadenceAdmin-md"
                      value={season.to}
                      placeholder="12-31"
                      pattern="\d\d-\d\d"
                      oninput={(e: any) => this.set(() => (season.to = e.target.value))}
                    />
                  </td>
                  <td>
                    <input
                      className="CadenceAdmin-color"
                      type="color"
                      value={season.color}
                      oninput={(e: any) => this.set(() => (season.color = e.target.value))}
                    />
                  </td>
                  <td>
                    <input className="FormControl" value={season.icon} oninput={(e: any) => this.set(() => (season.icon = e.target.value))} />
                  </td>
                  <td>
                    <Button
                      className="Button Button--icon Button--link"
                      icon="fas fa-trash"
                      aria-label={t('remove')}
                      onclick={() => this.set(() => this.seasons.splice(i, 1))}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
        <Button
          className="Button"
          icon="fas fa-plus"
          onclick={() => this.set(() => this.seasons.push({ name: '', from: '12-01', to: '12-31', color: '#22c55e', icon: 'fas fa-snowflake' }))}
        >
          {t('seasons.add')}
        </Button>

        <div className="CadenceAdmin-save">
          <Button className="Button Button--primary" loading={this.saving} disabled={!this.dirty} onclick={() => this.save()}>
            {t('save_tiers')}
          </Button>
        </div>
      </div>
    );
  }

  preview(color: string, icon: string, glow: boolean, days: number) {
    return (
      <span className={'FlameBadge' + (glow ? ' FlameBadge--glow' : '')} style={{ '--flame': color } as any}>
        <Icon name={icon || 'fas fa-fire'} /> {days}
      </span>
    );
  }

  set(fn: () => unknown) {
    fn();
    this.dirty = true;
  }

  save() {
    this.saving = true;
    const tiers = [...this.tiers].sort((a, b) => a.days - b.days);
    saveSettings({
      'ernestdefoe-cadence.streak_tiers': JSON.stringify(tiers),
      'ernestdefoe-cadence.streak_seasons': JSON.stringify(this.seasons.filter((s) => /^\d\d-\d\d$/.test(s.from) && /^\d\d-\d\d$/.test(s.to))),
    })
      .then(() => {
        this.tiers = tiers;
        this.dirty = false;
        app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message'));
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }
}
