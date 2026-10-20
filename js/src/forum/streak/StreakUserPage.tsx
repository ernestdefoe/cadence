import UserPage from 'flarum/forum/components/UserPage';
import { FlamePanel } from '../../common/streak/panels';

declare const m: import('mithril').Static;

/** Profile → Streak: the member's whole flame. Its own chunk. */
export default class StreakUserPage extends UserPage {
  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  content() {
    return this.user ? <FlamePanel user={this.user} /> : null;
  }
}
