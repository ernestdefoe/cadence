import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';

/** What the server sends as `cadenceStreakRules` on the forum. */
export interface Tier {
  key: string;
  name: string;
  days: number;
  color: string;
  icon: string;
  glow: boolean;
}

export interface Season {
  name: string;
  from: string; // MM-DD
  to: string; // MM-DD
  color: string;
  icon: string;
}

export interface StreakRules {
  mode: 'contribute' | 'visit';
  tiers: Tier[];
  seasons: Season[];
  milestones: number[];
  grace: number;
  freezeBank: number;
  embersMin: number;
  embersHours: number;
  embersShare: number;
}

/** The member's own flame, `cadenceFlame` on the session user. */
export interface OwnFlame {
  current: number;
  longest: number;
  total: number;
  startedOn: string | null;
  lastDay: string | null;
  freezes: number;
  embers: number;
  embersUntil: string | null;
  aliveUntil: string | null;
  timezone: string | null;
  celebrate: { type: string; value: number }[];
}

export interface Look {
  color: string;
  icon: string;
  glow: boolean;
  /** A season or a birthday dressing the flame, by name. */
  occasion: string | null;
}

export function t(key: string, args: Record<string, unknown> = {}): string {
  return extractText(app.translator.trans(`ernestdefoe-cadence.forum.streak.${key}`, args));
}

export function rules(): StreakRules | null {
  return (app.forum?.attribute<StreakRules>('cadenceStreakRules') as StreakRules) || null;
}

export function streaksOn(): boolean {
  return !!app.forum?.attribute<boolean>('cadenceStreaks') && !!rules();
}

/** The highest tier a length reaches, or null below the first. */
export function tierFor(days: number): Tier | null {
  let found: Tier | null = null;
  for (const tier of rules()?.tiers || []) if (days >= tier.days) found = tier;
  return found;
}

export function nextTier(days: number): Tier | null {
  return (rules()?.tiers || []).find((tier) => tier.days > days) || null;
}

const DEFAULT_TIERS = ['spark', 'kindled', 'blaze', 'inferno', 'eternal'];

/** Shared with the admin: `lib.*` keys are the ones both frontends load. */
export function lib(key: string, args: Record<string, unknown> = {}): string {
  return extractText(app.translator.trans(`ernestdefoe-cadence.lib.streak.${key}`, args));
}

/** A tier's name: what the admin typed, or the translated default. */
export function tierName(tier: { key: string; name: string } | null): string {
  if (!tier) return '';
  if (tier.name) return tier.name;
  return DEFAULT_TIERS.includes(tier.key) ? lib(`tiers.${tier.key}`) : tier.key;
}

/** Today in the viewer's own calendar, as YYYY-MM-DD. */
export function localToday(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** A season covering today (ranges may wrap the new year: 12-20 → 01-05). */
function seasonToday(): Season | null {
  const md = localToday().slice(5);
  for (const season of rules()?.seasons || []) {
    const inside = season.from <= season.to ? md >= season.from && md <= season.to : md >= season.from || md <= season.to;
    if (inside) return season;
  }
  return null;
}

/**
 * How a flame looks: the tier's colour and icon, dressed by a season, or gold
 * on the member's birthday when a birthday extension tells us when that is.
 */
export function lookFor(days: number, user?: any): Look {
  const tier = tierFor(days);
  const base: Look = { color: tier?.color || '#f97316', icon: tier?.icon || 'fas fa-fire', glow: !!tier?.glow, occasion: null };

  const birthday: string | null = user?.attribute?.('calendarBirthday') || user?.attribute?.('birthday') || null;
  if (birthday && String(birthday).slice(-5) === localToday().slice(5)) {
    return { color: '#eab308', icon: 'fas fa-cake-candles', glow: true, occasion: t('birthday') };
  }

  const season = seasonToday();
  if (season) return { ...base, color: season.color, icon: season.icon, occasion: season.name || null };

  return base;
}

/** The public flame on any serialized user: {current, longest} or null. */
export function flameOf(user: any): { current: number; longest: number } | null {
  const flame = user?.attribute?.('cadenceStreak');
  return flame && typeof flame.current === 'number' ? flame : null;
}

/** The session member's own flame, kept fresh by the visit ping. */
export function ownFlame(): OwnFlame | null {
  return (app.session?.user?.attribute?.('cadenceFlame') as OwnFlame) || null;
}

export function apiUrl(path: string): string {
  return `${app.forum.attribute('apiUrl')}${path}`;
}
