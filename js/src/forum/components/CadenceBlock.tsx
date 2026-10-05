import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import CadenceMap, { type MapData } from '../../common/components/CadenceMap';

declare const m: import('mithril').Static;

export interface CadenceBlockAttrs extends ComponentAttrs {
  user: any;
}

/**
 * Fetches one member's map and hands it to CadenceMap.
 *
 * 🚨 The request happens here and nowhere else. The compact sparkline used
 * beside posts and on hover cards reads `cadenceSpark`, which rides along on
 * the user the page had already loaded — because a placement that appears once
 * per post cannot have a request of its own without becoming one request per
 * rendered item, which is how a shared host runs out of database connections
 * and 500s the whole forum.
 */
/**
 * One request per member per page, shared by every copy of the block.
 *
 * 🚨 A component's own fields die with it. Themes and extensions that rebuild
 * the profile card on a redraw recreate this block, and each new copy began
 * with no data, showed the spinner and asked again — so the map could spin
 * forever while the profile sent request after request. Results, failures and
 * requests already in flight now live here, keyed by member and timezone.
 */
const cache = new Map<string, { data?: MapData; failed?: boolean; pending?: Promise<void> }>();

/** A map that hasn't arrived by now never will; hide it rather than spin. */
const TIMEOUT_MS = 15000;

export default class CadenceBlock extends Component<CadenceBlockAttrs> {
  private key = '';

  oninit(vnode: Mithril.Vnode<CadenceBlockAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  private load(): void {
    const user = this.attrs.user;

    if (!user) return;

    /*
     * The viewer's own offset from UTC, in minutes east, which is the sign
     * convention the API expects. Sent rather than assumed because the buckets
     * are stored hourly precisely so that "which day was that?" can be
     * answered in the reader's timezone rather than the server's.
     */
    const tz = -new Date().getTimezoneOffset();
    this.key = `${user.id()}:${tz}`;

    const entry = cache.get(this.key) || {};
    cache.set(this.key, entry);

    if (entry.data || entry.failed || entry.pending) return;

    const timeout = new Promise<never>((_, reject) => window.setTimeout(() => reject(new Error('timeout')), TIMEOUT_MS));

    entry.pending = Promise.race([
      app.request<{ data: MapData }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/cadence/${user.id()}`,
        params: { tz },
      }),
      timeout,
    ])
      .then((res) => {
        entry.data = res.data;
      })
      .catch(() => {
        // A profile whose map cannot load should still be a profile.
        entry.failed = true;
      })
      .finally(() => {
        entry.pending = undefined;
        m.redraw();
      });
  }

  view(): Mithril.Children {
    const entry = cache.get(this.key);

    if (!entry || entry.failed) return null;

    if (!entry.data) {
      return m('div.CadenceBlock.CadenceBlock--loading', LoadingIndicator.component({ size: 'small' }));
    }

    // Nothing recorded at all reads better as absence than as an empty grid.
    if (!Object.keys(entry.data.days || {}).length) return null;

    return m('div.CadenceBlock', CadenceMap.component({ data: entry.data }));
  }
}
