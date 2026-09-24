import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

/*
 * `m` is a global on a Flarum page, not a module. Importing 'mithril' makes
 * webpack bundle a second copy that shares no redraw cycle with the running one.
 */
declare const m: import('mithril').Static;

export interface MapData {
  start: string;
  end: string;
  joinedAt: string | null;
  days: Record<string, Record<string, number>>;
  hours: number[];
}

export interface CadenceMapAttrs extends ComponentAttrs {
  data: MapData;
}

/** The kinds we render, in the order the switcher offers them. */
const KINDS = ['all', 'discussion', 'reply', 'like', 'reaction', 'best_answer'] as const;
type Kind = (typeof KINDS)[number];

const SHADES = 4;

/**
 * 🚨 `extractText`, not a cast.
 *
 * A translation WITH parameters comes back as an array of vnodes, not a
 * string — casting it puts "[object Object]" in the output, and only on the
 * lines that interpolate anything, so the bare strings all look fine and hide
 * it. These strings go into `title` and `aria-label`, where a vnode is
 * meaningless to both sighted and screen-reader users.
 */
function t(key: string, args: Record<string, unknown> = {}): string {
  return extractText(app.translator.trans(`ernestdefoe-cadence.forum.${key}`, args));
}

function iso(d: Date): string {
  return d.toISOString().slice(0, 10);
}

/**
 * A member's activity, one square per day.
 *
 * 🚨 Everything here is decided against GitHub's graph, which this is meant to
 * beat rather than copy. The differences that matter are commented where they
 * live: an absolute scale, a window that starts when the member joined, a
 * metric you can change, and squares that say what they mean to a screen
 * reader instead of relying on four shades of one colour.
 */
export default class CadenceMap extends Component<CadenceMapAttrs> {
  kind: Kind = 'all';
  selected: string | null = null;

  /** Totals per day for the metric currently chosen. */
  private totals(): Record<string, number> {
    const out: Record<string, number> = {};
    const days = this.attrs.data.days || {};

    for (const date of Object.keys(days)) {
      const byKind = days[date];
      const n =
        this.kind === 'all'
          ? Object.values(byKind).reduce((a, b) => a + b, 0)
          : byKind[this.kind] || 0;

      if (n > 0) out[date] = n;
    }

    return out;
  }

  /**
   * 🚨 An ABSOLUTE scale, and floored at the number of shades.
   *
   * GitHub shades each graph against that person's own busiest day, so a
   * member with two posts all year and one with two hundred produce
   * identical-looking maps. On a forum, where the map is how you size up a
   * stranger, that is not a simplification — it is flattery, and it makes two
   * profiles impossible to compare.
   *
   * The floor matters just as much: without it a single post in the whole
   * window is the darkest square on the board, which reads as "extremely
   * active" when it means the opposite.
   */
  private step(totals: Record<string, number>): number {
    const max = Math.max(SHADES, ...Object.values(totals));
    return Math.max(1, Math.ceil(max / SHADES));
  }

  view(): Mithril.Children {
    const data = this.attrs.data;
    const totals = this.totals();
    const step = this.step(totals);

    const start = new Date(data.start + 'T00:00:00Z');
    const end = new Date(data.end + 'T00:00:00Z');

    /*
     * 🚨 The grid begins on the Sunday on or before the start date so the rows
     * are real weekdays. Without it every column is a different day of the
     * week and the whole thing is unreadable as a calendar.
     */
    const first = new Date(start);
    first.setUTCDate(first.getUTCDate() - first.getUTCDay());

    const weeks: Mithril.Children[] = [];
    const cursor = new Date(first);

    while (cursor <= end) {
      const column: Mithril.Children[] = [];

      for (let d = 0; d < 7; d++) {
        const date = iso(cursor);
        const inWindow = cursor >= start && cursor <= end;
        const n = totals[date] || 0;
        const level = n === 0 ? 0 : Math.min(SHADES, Math.ceil(n / step));

        column.push(
          inWindow
            ? m('button.CadenceMap-day', {
                type: 'button',
                'data-level': level,
                'data-date': date,
                className: this.selected === date ? 'is-selected' : '',
                // Real numbers, not a colour. A screen reader gets the same
                // information a sighted reader gets from the shade.
                'aria-label': this.label(date, n),
                title: this.label(date, n),
                onclick: () => {
                  this.selected = this.selected === date ? null : date;
                },
              })
            : // 🚨 Days outside the window are BLANKS, not zeroes. A member who
              // joined last month should not be shown eleven months of empty
              // squares that read as "inactive" when the truth is "new".
              m('span.CadenceMap-day.CadenceMap-day--blank', { 'aria-hidden': 'true' })
        );

        cursor.setUTCDate(cursor.getUTCDate() + 1);
      }

      weeks.push(m('div.CadenceMap-week', column));
    }

    return m('div.CadenceMap', [
      this.switcher(),
      m('div.CadenceMap-grid', { role: 'group', 'aria-label': t('map_label') }, weeks),
      this.legend(step),
      this.detail(totals),
    ]);
  }

  private label(date: string, n: number): string {
    const when = new Date(date + 'T00:00:00Z').toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      timeZone: 'UTC',
    });

    return n === 0 ? t('nothing_on', { date: when }) : t('count_on', { count: n, date: when });
  }

  private switcher(): Mithril.Children {
    const present = new Set<string>();

    for (const byKind of Object.values(this.attrs.data.days || {})) {
      Object.keys(byKind).forEach((k) => present.add(k));
    }

    /*
     * Only kinds this member actually has are offered. A forum without likes
     * installed should not show a "Likes" tab that can only ever be empty —
     * that is a control that does nothing, dressed as a feature.
     */
    const kinds = KINDS.filter((k) => k === 'all' || present.has(k));

    if (kinds.length <= 2) return null;

    return m(
      'div.CadenceMap-kinds',
      { role: 'tablist', 'aria-label': t('metric_label') },
      kinds.map((k) =>
        m(
          'button.CadenceMap-kind',
          {
            type: 'button',
            role: 'tab',
            'aria-selected': this.kind === k ? 'true' : 'false',
            className: this.kind === k ? 'is-active' : '',
            onclick: () => {
              this.kind = k;
              this.selected = null;
            },
          },
          t('kind_' + k)
        )
      )
    );
  }

  private legend(step: number): Mithril.Children {
    return m('div.CadenceMap-legend', [
      m('span.CadenceMap-legendLabel', t('less')),
      ...Array.from({ length: SHADES + 1 }, (_, i) =>
        m('span.CadenceMap-day.CadenceMap-day--key', {
          'data-level': i,
          // The legend states the real numbers, so two members' maps can be
          // compared rather than just looked at.
          title: i === 0 ? t('nothing') : t('at_least', { count: (i - 1) * step + 1 }),
        })
      ),
      m('span.CadenceMap-legendLabel', t('more')),
    ]);
  }

  private detail(totals: Record<string, number>): Mithril.Children {
    if (!this.selected) return null;

    const byKind = this.attrs.data.days[this.selected] || {};
    const parts = Object.keys(byKind)
      .filter((k) => byKind[k] > 0)
      .map((k) => t('kind_count_' + k, { count: byKind[k] }));

    return m('div.CadenceMap-detail', [
      m('strong', this.label(this.selected, totals[this.selected] || 0)),
      parts.length ? m('span.CadenceMap-detailKinds', parts.join(' · ')) : null,
    ]);
  }
}
