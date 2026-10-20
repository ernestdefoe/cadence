import app from 'flarum/forum/app';
import { apiUrl, localToday, ownFlame, rules, t, tierFor, tierName, type OwnFlame } from '../../common/streak/flame';
import { forget } from '../../common/streak/store';

declare const m: import('mithril').Static;

const IMPORTANT = ['milestone', 'tier', 'record', 'saved', 'revived'];

let shown = '';

/**
 * Show the member's unseen moments as toasts, once, then tell the server.
 *
 * How many is the member's own choice: every day ("Day 12 — fed"), only the
 * moments that matter (the default), or never. Moments are queued server-side
 * by whatever fed the flame, so a milestone reached by a post shows the moment
 * the reply's response brings the fresh flame back, on any page.
 */
export function celebrate(flame: OwnFlame | null = ownFlame()): void {
  const queue = flame?.celebrate || [];
  if (!queue.length) return;

  const signature = JSON.stringify(queue);
  if (signature === shown) return;
  shown = signature;

  const pref = app.session.user?.preferences()?.cadenceCelebrate || 'important';

  if (pref !== 'never') {
    queue
      .filter((e) => IMPORTANT.includes(e.type) || (pref === 'all' && e.type === 'day'))
      // A revival or a record already says the number; the day toast would repeat it.
      .filter((e, _, all) => e.type !== 'day' || all.length === 1)
      .slice(-3)
      .forEach((e) => {
        const message = e.type === 'tier' ? t('toast.tier', { tier: tierName(tierFor(e.value)) }) : t(`toast.${e.type}`, { count: e.value });
        app.alerts.show({ type: 'success', icon: 'fas fa-fire', className: 'FlameToast' } as any, message);
      });
  }

  applyOwn({ ...flame!, celebrate: [] });
  app.request({ method: 'POST', url: apiUrl('/cadence/streak/ack') }).catch(() => {});
}

/**
 * The browser checking in, at most once per local day.
 *
 * In contribute mode this only happens when the browser's timezone differs
 * from the one stored — once, ever, for most members. In visit mode it is the
 * day's first page. Every other page load sends nothing.
 */
export function checkIn(force = false): void {
  const user = app.session.user;
  if (!user || !app.forum.attribute('canEarnStreaks') || !rules()) return;

  const flame = ownFlame();
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const needsVisit = rules()!.mode === 'visit' && flame?.lastDay !== localToday();
  const needsZone = !!tz && flame?.timezone !== tz;

  if (!force && !needsVisit && !needsZone) {
    celebrate(flame);
    return;
  }

  app
    .request<{ data: { streak: OwnFlame } }>({ method: 'POST', url: apiUrl('/cadence/streak/visit'), body: { timezone: tz } })
    .then((res) => {
      applyOwn(res.data.streak);
      forget(user.id()!);
      celebrate(res.data.streak);
      m.redraw();
    })
    .catch(() => {});
}

/**
 * After the member saves a post (a reply, a new discussion, a like): if that
 * could have fed the flame — the first of their day, or a post while embers
 * glow — read the fresh flame back so the header and the toast catch up on
 * this page, not the next one.
 *
 * 🚨 Needed because stock Flarum's post responses do not include the author,
 * so nothing else brings the new flame back. Every later save that day sends
 * nothing.
 */
export function afterContribution(): void {
  const flame = ownFlame();

  // The save's own response usually brings the fresh flame back (it includes
  // the author); show its moments now rather than at the next redraw.
  if (flame && flame.lastDay === localToday() && !flame.embers) {
    celebrate(flame);
    m.redraw();
    return;
  }

  checkIn(true);
}

/** Keep both halves of the session member's flame in step. */
export function applyOwn(flame: OwnFlame): void {
  app.session.user?.pushAttributes({ cadenceFlame: flame, cadenceStreak: { current: flame.current, longest: flame.longest } });
}
