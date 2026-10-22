import app from 'flarum/admin/app';
import { registerEditorWidgets } from './widgets';

/*
 * Settings are registered through the `Admin` extender in extend.ts, which
 * Flarum picks up from this bundle's `extend` export — never through
 * `app.extensionData` (see the note in extend.ts). The initializer only offers
 * Cadence's widgets to FoF's layout editor, which needs FoF's modules loaded.
 */
export { default as extend } from './extend';

app.initializers.add('ernestdefoe/cadence', () => registerEditorWidgets());
