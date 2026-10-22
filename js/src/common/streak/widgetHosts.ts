/**
 * Cadence's widgets as fof/forum-widgets-core sees them, shared by the forum
 * (which renders them) and the admin (whose layout editor places them).
 *
 * 🚨 OPT-IN. FoF shows any registered widget that no saved layout mentions, in
 * its default section — so Cadence's three appeared in a right-hand column on
 * every forum with FoF installed, even one whose layout had no widgets at all,
 * and FoF's editor could not take them out because the admin never registered
 * them (Discuss #14). Now a Cadence widget shows only once the admin has
 * dragged it into a section and saved; until then it waits in the editor's
 * list of available widgets.
 */
export const WIDGETS = [
  { key: 'cadenceMyFlame', type: 'cadence-my-flame', label: 'my_flame', icon: 'fas fa-fire' },
  { key: 'cadenceTopFlames', type: 'cadence-top-flames', label: 'top_flames', icon: 'fas fa-ranking-star' },
  { key: 'cadenceBonfire', type: 'cadence-bonfire', label: 'bonfire', icon: 'fas fa-campground' },
] as const;

export const EXTENSION = 'ernestdefoe-cadence';

export const widgetId = (key: string) => `${EXTENSION}:${key}`;

/** A module of fof/forum-widgets-core, at runtime — never a build-time import. */
export function fof(path: string): any {
  const mod = (globalThis as any).flarum?.reg?.get?.('fof-forum-widgets-core', path);
  return mod?.default ?? mod;
}

/** Whether the saved layout places this widget in a section. */
export function placed(config: any, key: string): boolean {
  return (config?.instances || []).some((i: any) => i?.id === widgetId(key));
}
