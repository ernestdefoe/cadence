import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserCard from 'flarum/forum/components/UserCard';
import PostUser from 'flarum/forum/components/PostUser';
import ItemList from 'flarum/common/utils/ItemList';
import CadenceBlock from './components/CadenceBlock';
import CadenceSpark from '../common/components/CadenceSpark';

export { default as CadenceMap } from '../common/components/CadenceMap';
export { default as CadenceSpark } from '../common/components/CadenceSpark';
export { default as CadenceBlock } from './components/CadenceBlock';

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
});
