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
export default class CadenceBlock extends Component<CadenceBlockAttrs> {
  data: MapData | null = null;
  failed = false;

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

    app
      .request<{ data: MapData }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/cadence/${user.id()}`,
        params: { tz },
      })
      .then((res) => {
        this.data = res.data;
        m.redraw();
      })
      .catch(() => {
        // A profile whose map cannot load should still be a profile.
        this.failed = true;
        m.redraw();
      });
  }

  view(): Mithril.Children {
    if (this.failed) return null;

    if (!this.data) {
      return m('div.CadenceBlock.CadenceBlock--loading', LoadingIndicator.component({ size: 'small' }));
    }

    // Nothing recorded at all reads better as absence than as an empty grid.
    if (!Object.keys(this.data.days || {}).length) return null;

    return m('div.CadenceBlock', CadenceMap.component({ data: this.data }));
  }
}
