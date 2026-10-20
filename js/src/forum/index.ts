import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserCard from 'flarum/forum/components/UserCard';
import PostUser from 'flarum/forum/components/PostUser';
import ItemList from 'flarum/common/utils/ItemList';
import CadenceBlock from './components/CadenceBlock';
import CadenceSpark from '../common/components/CadenceSpark';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import IndexPage from 'flarum/forum/components/IndexPage';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import FieldSet from 'flarum/common/components/FieldSet';
import Select from 'flarum/common/components/Select';
import { flameOf, streaksOn, t as st } from '../common/streak/flame';
import { FlameBadge } from '../common/streak/components';
import FlameButton from './streak/FlameButton';
import { afterContribution, checkIn } from './streak/celebrate';
import Post from 'flarum/common/models/Post';
import Discussion from 'flarum/common/models/Discussion';
import { hasFofWidgets, MyFlameWidget, registerWidgets, TopFlamesWidget } from './streak/widgets';
import { StreakMomentNotification, StreakWarningNotification } from './streak/notifications';

declare const m: import('mithril').Static;

export { default as CadenceMap } from '../common/components/CadenceMap';
export { default as CadenceSpark } from '../common/components/CadenceSpark';
export { default as CadenceBlock } from './components/CadenceBlock';
export { FlameBadge } from '../common/streak/components';

app.initializers.add('ernestdefoe/cadence', () => {
  /*
   * 🚨 `UserCard.contentItems`, not `UserPage.content`.
   *
   * `UserPage.content()` is empty in the base class and OVERRIDDEN by every
   * subclass — the posts tab, the security tab, and anything an extension
   * adds. Extending the base therefore runs on none of them, and the map would
   * simply never appear while every line of it compiled and tested fine.
   *
   * The card is rendered once for the profile whatever tab is open, and it is
   * already where join date and post count live, so it is where a reader looks
   * for this.
   */
  extend(UserCard.prototype, 'contentItems', function (this: any, items: ItemList<any>) {
    if (!app.forum.attribute<boolean>('cadenceShowOnProfile')) return;

    const user = this.attrs.user;
    if (!user) return;

    // The full map belongs on the profile, not in the hover card that the
    // same component draws beside every avatar (that's the sparkline's job).
    if (String(this.attrs.className || '').includes('UserCard--popover')) return;

    // Low priority so it sits below the identity, badges and info lines.
    items.add('cadence', CadenceBlock.component({ user }), -10);
  });

  /*
   * The compact trace beside each post author.
   *
   * 🚨 `userViewItems`, which only runs for a post that HAS a visible author —
   * `noUserViewItems` is the deleted-account path, and a sparkline for nobody
   * is both meaningless and a null dereference waiting to happen.
   */
  extend(PostUser.prototype, 'userViewItems', function (this: any, items: ItemList<any>, user: any) {
    if (!app.forum.attribute<boolean>('cadenceShowOnPosts')) return;
    if (!user) return;

    items.add('cadence', CadenceSpark.component({ user }), -10);
  });

  /*
   * And on the card itself, for forums that want the summary without the full
   * map. Both placements read the same serialized attribute, so neither costs
   * a request.
   */
  extend(UserCard.prototype, 'infoItems', function (this: any, items: ItemList<any>) {
    if (!app.forum.attribute<boolean>('cadenceShowOnCards')) return;

    const user = this.attrs.user;
    if (!user) return;

    items.add('cadence', CadenceSpark.component({ user }), -10);
  });

  registerStreaks();
});

/**
 * Streaks: the flame in the header, after names, on cards, a profile tab, the
 * leaderboard, widgets, alerts and the once-a-day check-in.
 *
 * Every placement reads the flame off a user the page already loaded. The only
 * requests are the leaderboard, the profile tab, an opened header menu, the
 * Top flames widget — and the check-in, at most once a day.
 */
function registerStreaks() {
  // 🚨 Both pages are their own chunks: nothing in them loads on other pages.
  app.routes['cadence.streaks'] = { path: '/streaks', component: () => import('./streak/StreaksPage') };
  app.routes['user.streak'] = { path: '/u/:username/streak', component: () => import('./streak/StreakUserPage') };

  app.notificationComponents.cadenceStreakMoment = StreakMomentNotification;
  app.notificationComponents.cadenceStreakWarning = StreakWarningNotification;

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!streaksOn()) return;
    items.add('cadenceStreakMoment', { name: 'cadenceStreakMoment', icon: 'fas fa-fire', label: st('notify_moments') });
    items.add('cadenceStreakWarning', { name: 'cadenceStreakWarning', icon: 'fas fa-fire-flame-simple', label: st('notify_warnings') });
  });

  // How loudly the first page of the day celebrates. SettingsPage is a lazy
  // chunk in Flarum 2, so it is extended by path.
  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (this: any, items: ItemList<any>) {
    if (!streaksOn() || !app.forum.attribute('canEarnStreaks')) return;
    const user = app.session.user!;

    items.add(
      'cadenceStreaks',
      FieldSet.component({ className: 'Settings-cadenceStreaks', label: st('settings.title') }, [
        m('p.helpText', st('settings.celebrate_help')),
        Select.component({
          value: user.preferences()?.cadenceCelebrate || 'important',
          options: { all: st('settings.celebrate_all'), important: st('settings.celebrate_important'), never: st('settings.celebrate_never') },
          onchange: (value: string) => user.savePreferences({ cadenceCelebrate: value }).then(() => m.redraw()),
        }),
      ]),
      -5
    );
  });

  extend(HeaderSecondary.prototype, 'items', function (items: ItemList<any>) {
    if (!streaksOn() || !app.session.user || !app.forum.attribute('canEarnStreaks') || !app.forum.attribute('cadenceStreakInHeader')) return;
    items.add('cadenceFlame', FlameButton.component(), 15);
  });

  // After the name in posts — inside the name's own link, so it reads as part of it.
  extend(PostUser.prototype, 'linkChildren', function (items: ItemList<any>, user: any) {
    if (!streaksOn() || !app.forum.attribute('cadenceStreakOnPosts')) return;
    const flame = flameOf(user);
    if (flame?.current) items.add('cadenceFlame', FlameBadge.component({ days: flame.current, user }), 70);
  });

  // A streak line on the card (and the hover card).
  extend(UserCard.prototype, 'infoItems', function (this: any, items: ItemList<any>) {
    if (!streaksOn() || !app.forum.attribute('cadenceStreakOnCards')) return;
    const flame = flameOf(this.attrs.user);
    if (!flame?.longest) return;

    items.add(
      'cadenceStreak',
      m('span.UserCard-cadenceStreak', [
        flame.current ? FlameBadge.component({ days: flame.current, user: this.attrs.user }) : m('span.FlameBadge.is-out', st('flame_out_short')),
        ' ',
        m('span.UserCard-cadenceStreakBest', st('best_short', { count: flame.longest })),
      ]),
      -11
    );
  });

  extend(UserPage.prototype, 'navItems', function (this: any, items: ItemList<any>) {
    if (!streaksOn() || !this.user || !app.forum.attribute('cadenceStreakOnProfile')) return;
    items.add(
      'streak',
      LinkButton.component({ href: app.route('user.streak', { username: this.user.slug() }), icon: 'fas fa-fire' }, st('profile_tab')),
      75
    );
  });

  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<any>) {
    if (!streaksOn() || !app.forum.attribute('cadenceStreakNav')) return;
    items.add('cadenceStreaks', LinkButton.component({ href: app.route('cadence.streaks'), icon: 'fas fa-fire' }, st('page_title')), 6);
  });

  /*
   * Stock-theme fallback, off unless the admin turns it on: the widgets as
   * sidebar blocks on the index only (other pages lay this list out as a
   * horizontal nav). With fof/forum-widgets-core, the admin places them there.
   */
  extend(IndexSidebar.prototype, 'items', function (items: ItemList<any>) {
    if (!streaksOn() || !app.forum.attribute('cadenceStreakSidebar') || hasFofWidgets()) return;
    if (!app.current || !app.current.matches(IndexPage)) return;
    items.add('cadenceMyFlame', m(MyFlameWidget), -20);
    items.add('cadenceTopFlames', m(TopFlamesWidget), -21);
  });

  extend(app, 'mount', function () {
    if (streaksOn()) checkIn();
  });

  // A reply, a new discussion or a like may have fed the flame just now.
  const fed = function (this: any, saving: Promise<unknown>) {
    if (!streaksOn() || !app.session.user) return;
    saving?.then?.(() => afterContribution()).catch?.(() => {});
  };
  extend(Post.prototype, 'save', fed);
  extend(Discussion.prototype, 'save', fed);

  // After sibling extensions (Bespoke) have set up their registries.
  setTimeout(() => {
    if (streaksOn()) registerWidgets();
  }, 0);
}
