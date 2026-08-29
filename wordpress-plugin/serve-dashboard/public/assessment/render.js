/**
 * The two things every other view is built out of.
 *
 * Pulled out of app.js so they can be imported without a DOM. app.js reads
 * `document` at the top level, which means nothing defined inside it can be
 * exercised outside a browser — and these are pure string functions that had no
 * reason to be trapped in there with it.
 */

export function escapeHtml(value = "") {
  return String(value).replace(/[&<>'"]/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;",
  })[character]);
}

/*
 * An unknown icon name yields an empty <svg> rather than throwing, which is the
 * right behaviour on a live page and the wrong one to leave unchecked: a
 * missing icon is invisible, not loud. Every icon on the dashboard was once
 * drawn at 0x0 for exactly this kind of reason. `iconNames` exists so a test
 * can assert every name the app asks for is a name defined here.
 */
export const ICON_PATHS = {
  arrowLeft: '<path d="m15 18-6-6 6-6"/><path d="M9 12h12"/>',
  arrowRight: '<path d="M9 18l6-6-6-6"/><path d="M3 12h12"/>',
  alert: '<circle cx="12" cy="12" r="10"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
  check: '<path d="m5 12 4 4L19 6"/>',
  chevron: '<path d="m6 9 6 6 6-6"/>',
  clipboard: '<rect width="14" height="16" x="5" y="4" rx="2"/><path d="M9 4V2h6v2"/>',
  compass: '<circle cx="12" cy="12" r="10"/><path d="m16 8-3 5-5 3 3-5 5-3Z"/>',
  external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  lock: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-10 6L2 7"/>',
  printer: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
  refresh: '<path d="M20 11a8 8 0 1 0-2.34 5.66"/><path d="M20 4v7h-7"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  device: '<rect x="4" y="3" width="11" height="18" rx="2"/><path d="M17 8h3v13h-8"/>',
};

export const iconNames = () => Object.keys(ICON_PATHS);

export function icon(name, size = 18) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON_PATHS[name] || ""}</svg>`;
}
