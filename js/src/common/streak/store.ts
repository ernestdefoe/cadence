import app from 'flarum/common/app';
import { apiUrl } from './flame';

declare const m: import('mithril').Static;

export interface MiniUser {
  id: string;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  slug: string;
}

export interface Standing {
  rank: number | null;
  rival: { user: MiniUser; ahead: number } | null;
}

export interface MemberFlame {
  userId: string;
  current: number;
  longest: number;
  total: number;
  startedOn: string | null;
  freezes: number;
  today: string;
  firstYear: number;
  standing: Standing;
  days: Record<string, 1 | 2>;
  embers?: number;
  embersUntil?: string | null;
  journal?: { type: string; value: number; data: any; at: string }[];
}

export interface BoardEntry {
  rank: number;
  user: MiniUser;
  value: number;
  current: number;
  longest: number;
  when?: string;
}

export interface Bonfire {
  level: number;
  levels: number;
  toNext: number;
  progress: number;
}

export interface Board {
  board: string;
  entries: BoardEntry[];
  stats?: { alight: number; fedToday: number; best: number; bonfire: Bonfire };
  me?: Standing;
}

/**
 * One request per distinct query, shared by every component that asks.
 *
 * 🚨 Module-level, not component fields: Bespoke and other hosts rebuild their
 * widgets on a redraw, and a component that keeps its own data starts empty
 * each time — spinning and re-requesting for as long as the page is open.
 */
const cache = new Map<string, { data?: any; failed?: boolean; pending?: Promise<any> }>();

function load<T>(key: string, url: string, params: Record<string, unknown>): { data?: T; failed?: boolean } {
  const entry = cache.get(key) || {};
  cache.set(key, entry);

  if (!entry.data && !entry.failed && !entry.pending) {
    entry.pending = app
      .request<{ data: T }>({ method: 'GET', url, params })
      .then((res) => {
        entry.data = res.data;
      })
      .catch(() => {
        entry.failed = true;
      })
      .finally(() => {
        entry.pending = undefined;
        m.redraw();
      });
  }

  return entry;
}

export function member(userId: string, query: { year?: number; month?: string }) {
  return load<MemberFlame>(`member:${userId}:${query.year || ''}:${query.month || ''}`, apiUrl(`/cadence/streaks/${userId}`), query);
}

export function board(name: string, limit = 50, offset = 0, extras: Record<string, string> = {}) {
  const params = { board: name, limit, offset, ...extras };
  return load<Board>(`board:${JSON.stringify(params)}`, apiUrl('/cadence/streaks'), params);
}

/** Forget everything about a member (after a feed or a staff change). */
export function forget(userId?: string): void {
  for (const key of [...cache.keys()]) {
    if (!userId || key.startsWith(`member:${userId}:`) || key.startsWith('board:')) cache.delete(key);
  }
}
