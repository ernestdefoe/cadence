import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

export interface CadenceSparkAttrs extends ComponentAttrs {
  user: any;
}

/**
 * Twenty-six weeks of activity in the width of a word.
 *
 * 🚨 This reads `cadenceSpark` off the user the page has ALREADY loaded. It
 * never fetches anything, and it must never start: this component appears once
 * per post, so a request of its own would be thirty requests on an ordinary
 * page — the shape that exhausts a shared host's connection limit and returns
 * 500 for the entire forum rather than for the decoration that caused it.
 */
export default class CadenceSpark extends Component<CadenceSparkAttrs> {
  view(): Mithril.Children {
    const user = this.attrs.user;
    const weeks: number[] | null = user?.attribute?.('cadenceSpark') ?? null;

    /*
     * Absent whenever the server decided not to send it — the placement is off,
     * or the viewer may not see this member. Rendering an empty frame in that
     * case would put a permanently blank box beside every post.
     */
    if (!Array.isArray(weeks) || !weeks.length) return null;

    const total = weeks.reduce((a, b) => a + b, 0);
    if (total === 0) return null;

    const max = Math.max(...weeks);

    const label = extractText(
      app.translator.trans('ernestdefoe-cadence.forum.spark_label', { count: total })
    );

    return m(
      'span.CadenceSpark',
      {
        // One label for the whole thing. Twenty-six individually announced bars
        // is noise, not information, to someone using a screen reader.
        role: 'img',
        'aria-label': label,
        title: label,
      },
      weeks.map((n) =>
        m('span.CadenceSpark-bar', {
          /*
           * A floor of 8% so a quiet week is a visible trough rather than a
           * gap — a bar of zero height is indistinguishable from the component
           * having failed to render.
           */
          style: { height: (n === 0 ? 8 : Math.max(8, Math.round((n / max) * 100))) + '%' },
          'aria-hidden': 'true',
        })
      )
    );
  }
}
