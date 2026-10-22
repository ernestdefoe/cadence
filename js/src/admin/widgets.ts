import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import { EXTENSION, fof, placed, WIDGETS, widgetId } from '../common/streak/widgetHosts';

const streaksOn = () => !['0', 'false', ''].includes(String(app.data.settings['ernestdefoe-cadence.streaks'] ?? '1'));

/**
 * FoF's layout editor lists only widgets registered on the admin side too —
 * which Cadence's never were, so its widgets showed on the forum with no way
 * to take them out (Discuss #14). Registered as the editor opens, from the
 * streaks switch as it is now; never while streaks are off.
 *
 * 🚨 A Cadence widget no saved layout places goes in the editor's list of
 * available widgets, not into a section: FoF would otherwise draw it placed,
 * while the forum (see widgetHosts) rightly shows nothing until it is saved.
 */
export function registerEditorWidgets(): void {
  const Widgets = fof('common/extend/Widgets');
  const WidgetEditor = fof('admin/components/WidgetEditor');
  if (!Widgets || !WidgetEditor) return;

  extend(WidgetEditor.prototype, 'oninit', function (this: any) {
    const manager = (app as any).widgets;
    const ours = (w: any) => w.extension === EXTENSION;

    if (!streaksOn()) {
      manager.widgets = manager.widgets.filter((w: any) => !ours(w));
      return;
    }

    if (!manager.widgets.some(ours)) {
      const widgets = new Widgets();
      WIDGETS.forEach((w, position) =>
        widgets.add({
          key: w.key,
          component: { component: () => null },
          title: () => app.translator.trans(`ernestdefoe-cadence.admin.widgets.${w.label}`),
          icon: w.icon,
          isDisabled: false,
          isUnique: true,
          placement: 'end',
          position,
        })
      );
      widgets.extend(app, EXTENSION);
    }

    const config = this.config || {};
    const disabled: string[] = config.disabled || [];
    const unplaced = WIDGETS.filter((w) => !placed(config, w.key) && !disabled.includes(widgetId(w.key))).map((w) => widgetId(w.key));
    config.disabled = [...disabled, ...unplaced];
  });
}
