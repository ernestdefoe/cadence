import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import Overview from './streak/Overview';
import TiersEditor from './streak/TiersEditor';

declare const m: import('mithril').Static;

const t = (k: string) => app.translator.trans(`ernestdefoe-cadence.admin.${k}`) as string;
const key = (n: string) => `ernestdefoe-cadence.${n}`;

const bool =
  (name: string, label = name) =>
  () => ({ setting: key(name), label: t(label), help: t(`${label}_help`), type: 'boolean' });
const num = (name: string, min: number, max: number) => () => ({
  setting: key(name),
  label: t(`streak.${name}`),
  help: t(`streak.${name}_help`),
  type: 'number',
  min,
  max,
});
const text =
  (name: string, placeholder = '') =>
  () => ({ setting: key(name), label: t(`streak.${name}`), help: t(`streak.${name}_help`), type: 'text', placeholder });

/**
 * 🚨 The `Admin` extender, NOT `app.extensionData`.
 *
 * `app.extensionData` is the Flarum 1.x API and is absent in Flarum 2 — not
 * deprecated, gone. Calling `.for()` on it throws inside the initializer, core
 * catches that per extension, and the admin is told only "failed to
 * initialize"; the forum side keeps working, so it reads as the extension being
 * broken rather than as one wrong line. It has cost this codebase three
 * separate extensions already.
 *
 * Highest priority first: the streak overview, then its rules and look, then
 * where things show, then the activity map's own placements.
 */
export default [
  new Admin()
    .permission(() => ({ icon: 'fas fa-fire', label: t('streak.permission_view'), permission: 'cadence.viewStreaks', allowGuest: true }), 'view')
    .permission(() => ({ icon: 'fas fa-fire', label: t('streak.permission_earn'), permission: 'cadence.earnStreaks' }), 'reply')

    .customSetting(() => m('h2.CadenceAdmin-heading', t('streak.heading')), 200)
    .setting(bool('streaks', 'streak.enabled'), 199)
    .customSetting(() => m(Overview), 198)
    .setting(
      () => ({
        setting: key('streak_mode'),
        label: t('streak.mode'),
        help: t('streak.mode_help'),
        type: 'select',
        options: { contribute: t('streak.mode_contribute'), visit: t('streak.mode_visit') },
        default: 'contribute',
      }),
      190
    )
    .setting(num('streak_grace', 0, 3), 189)
    .setting(text('streak_milestones', '3,7,14,30,50,100,150,200,250,365,500,730,1000'), 188)
    .setting(num('streak_freeze_per_milestone', 0, 5), 187)
    .setting(num('streak_freeze_bank', 0, 20), 186)
    .setting(num('streak_embers_min', 0, 10000), 185)
    .setting(num('streak_embers_hours', 1, 168), 184)
    .setting(num('streak_embers_share', 1, 100), 183)
    .setting(num('streak_hall_min', 1, 10000), 182)
    .setting(num('streak_risk_min', 1, 10000), 181)
    .setting(num('streak_risk_hour', 0, 23), 180)
    .setting(text('streak_bonfire_levels', '10,25,50,100'), 179)
    .setting(text('streak_timezone', 'UTC'), 178)
    .customSetting(() => m(TiersEditor), 170)

    .customSetting(() => m('h3', t('streak.placements')), 160)
    .setting(bool('streak_in_header', 'streak.in_header'), 159)
    .setting(bool('streak_on_posts', 'streak.on_posts'), 158)
    .setting(bool('streak_on_cards', 'streak.on_cards'), 157)
    .setting(bool('streak_on_profile', 'streak.on_profile'), 156)
    .setting(bool('streak_nav', 'streak.nav'), 155)
    .setting(bool('streak_sidebar', 'streak.sidebar'), 154)

    .customSetting(() => m('h2.CadenceAdmin-heading', t('map_heading')), 100)
    .setting(bool('show_on_profile'), 90)
    .setting(bool('show_on_posts'), 89)
    .setting(bool('show_on_cards'), 88),
];
