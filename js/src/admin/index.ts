/*
 * Settings are registered through the `Admin` extender in extend.ts, which
 * Flarum picks up from this bundle's `extend` export. There is deliberately no
 * initializer here — see the note in extend.ts.
 */
export { default as extend } from './extend';
