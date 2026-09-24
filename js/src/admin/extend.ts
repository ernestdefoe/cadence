import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';

const t = (k: string) => app.translator.trans(`ernestdefoe-cadence.admin.${k}`) as string;
const key = (n: string) => `ernestdefoe-cadence.${n}`;

/**
 * 🚨 The `Admin` extender, NOT `app.extensionData`.
 *
 * `app.extensionData` is the Flarum 1.x API and is absent in Flarum 2 — not
 * deprecated, gone. Calling `.for()` on it throws inside the initializer, core
 * catches that per extension, and the admin is told only "failed to
 * initialize"; the forum side keeps working, so it reads as the extension being
 * broken rather than as one wrong line. It has cost this codebase three
 * separate extensions already.
 */
export default [
  new Admin()
    .setting(() => ({
      setting: key('show_on_profile'),
      label: t('show_on_profile'),
      help: t('show_on_profile_help'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: key('show_on_posts'),
      label: t('show_on_posts'),
      help: t('show_on_posts_help'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: key('show_on_cards'),
      label: t('show_on_cards'),
      help: t('show_on_cards_help'),
      type: 'boolean',
    })),
];
