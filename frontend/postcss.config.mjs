/**
 * Tailwind is wired in through the @tailwindcss/vite plugin (see vite.config.ts),
 * so no PostCSS plugins are required here.
 *
 * This file exists only to keep postcss-load-config from walking up past the
 * project root into the parent workspace, where an unrelated postcss.config.mjs
 * references @tailwindcss/postcss (a package this project does not install).
 */
export default {
  plugins: {},
}
