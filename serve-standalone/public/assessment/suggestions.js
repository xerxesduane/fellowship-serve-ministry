/**
 * The results page's request state, as a value rather than three loose globals.
 *
 * app.js held `serverSuggestions` (null | array) and `suggestionsAsked` (bool)
 * at module scope and read truthiness off them, which produced three separate
 * defects that all looked the same from the outside — a page stuck on "Working
 * these out…" forever:
 *
 * 1. A valid `{ suggestions: [] }` — the correct, complete answer for somebody
 *    with no strong match — was discarded by `!data.suggestions.length`, so the
 *    honest empty state never rendered.
 * 2. A network or parse failure was swallowed by a deliberately silent catch,
 *    on the reasoning that a working results page beat an error. That was true
 *    when the browser still had its own ranking to fall back on. It has not had
 *    one since ranking moved to the server, so the fallback is now a spinner.
 * 3. `restart` cleared answers, step, searches and localStorage but neither of
 *    these. Because `suggestionsAsked` stayed true, the next person on a shared
 *    device saw the previous person's teams and never triggered a new request.
 *
 * So: an explicit state, one place that decides transitions, and a profile key
 * so a response that arrives after a restart is recognised as stale and
 * dropped rather than painted over whoever is on screen now.
 *
 * Pure and DOM-free on purpose. The README's own argument for extracting
 * handoff.js was that logic trapped in app.js is logic no test can reach, and
 * a source-scanning test written in its place passed against a version that
 * behaved wrongly.
 */

/** @typedef {"idle"|"loading"|"ready"|"empty"|"error"} RequestState */

export const IDLE = "idle";
export const LOADING = "loading";
export const READY = "ready";
export const EMPTY = "empty";
export const ERROR = "error";

export function initialState() {
  return { status: IDLE, suggestions: [], personality: [], key: null, error: "" };
}

/**
 * Identity of the profile a request belongs to.
 *
 * The gift answers alone: they are what the request actually sends, so two
 * profiles with the same key would receive the same recommendations anyway.
 * Restarting produces an empty assessment and therefore a different key, which
 * is what makes a late response from the previous person recognisable.
 */
export function profileKey(profile) {
  const gifts = (profile && profile.spiritualGifts) || {};
  const part = (name) => (Array.isArray(gifts[name]) ? gifts[name] : []).join(",");

  /*
   * Delimited, not concatenated. Joining on an empty string would make
   * ["Faith","Mercy"] and ["FaithMercy"] the same key, and a key that collides
   * is worse than no key at all: it is how a stale response gets mistaken for a
   * current one. No gift label contains a comma or a pipe.
   */
  return [part("likely"), part("possible"), part("unlikely")].join("|");
}

/**
 * Whether a request should be made for this profile right now.
 *
 * True when nothing has been asked yet, and true again when the profile has
 * changed since whatever was asked — which is how restart re-arms without
 * anybody having to remember to clear a flag.
 */
export function shouldRequest(state, key) {
  if (state.status === LOADING) {
    return state.key !== key;
  }

  if (state.status === IDLE) {
    return true;
  }

  return state.key !== key;
}

export function requested(state, key) {
  return { status: LOADING, suggestions: [], personality: [], key, error: "" };
}

/**
 * Fold a successful response in.
 *
 * An empty `suggestions` array is a complete, successful answer and becomes
 * EMPTY — not a reason to keep waiting. A response whose key no longer matches
 * belongs to a profile that has since been restarted or edited, and is dropped.
 */
export function received(state, key, data) {
  if (state.key !== key) {
    return state;
  }

  const suggestions = data && Array.isArray(data.suggestions) ? data.suggestions : null;

  if (suggestions === null) {
    return failed(state, key, "unreadable");
  }

  const personality = data && Array.isArray(data.personality) ? data.personality : [];

  return {
    status: suggestions.length ? READY : EMPTY,
    suggestions,
    personality,
    key,
    error: "",
  };
}

/**
 * Fold a failure in.
 *
 * Also key-checked: a request that fails after the person has restarted must
 * not put an error on the new profile's page.
 */
export function failed(state, key, reason = "network") {
  if (state.key !== key) {
    return state;
  }

  return { status: ERROR, suggestions: [], personality: [], key, error: reason };
}

/**
 * Everything cleared, for restart.
 *
 * Deliberately identical to initialState(): a new profile must inherit no
 * recommendations, no error, no in-flight marker and no cached identity.
 */
export function cleared() {
  return initialState();
}

/** Whether the page has a settled answer to render. */
export function isSettled(state) {
  return state.status === READY || state.status === EMPTY || state.status === ERROR;
}
