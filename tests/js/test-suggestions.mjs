/**
 * The results page's request state.
 *
 * Three defects lived here and all looked identical from the outside — a page
 * stuck on "Working these out…" forever. A valid empty response was discarded
 * by a truthiness check; a failed request was swallowed by a silent catch; and
 * restart cleared everything except these two variables, so the next person on
 * a shared device inherited the previous person's teams and never triggered a
 * new request.
 */

import { test, Assert } from "./bootstrap.mjs";
import {
  EMPTY,
  ERROR,
  IDLE,
  LOADING,
  READY,
  cleared,
  failed,
  initialState,
  isSettled,
  profileKey,
  received,
  requested,
  shouldRequest,
} from "../../wordpress-plugin/serve-dashboard/public/assessment/suggestions.js";

const giftProfile = (likely) => ({
  spiritualGifts: { likely, possible: [], unlikely: [] },
});

test("a fresh page has asked for nothing and shows nothing", (a) => {
  const state = initialState();

  a.same(IDLE, state.status, "it starts idle");
  a.same(0, state.suggestions.length, "with no suggestions");
  a.same(null, state.key, "and no profile identity");
  a.not(isSettled(state), "and is not a settled answer");
});

test("a valid non-empty response becomes ready and carries its teams", (a) => {
  const key = profileKey(giftProfile(["Faith"]));
  let state = requested(initialState(), key);

  a.same(LOADING, state.status, "the request is in flight");

  state = received(state, key, {
    suggestions: [
      { team: "Prayer", tier: "strong", tierLabel: "Strong gift match", reasons: ["Faith aligns"] },
    ],
  });

  a.same(READY, state.status, "a populated response is ready");
  a.same(1, state.suggestions.length, "the team is kept");
  a.same("Prayer", state.suggestions[0].team, "with its name");
  a.ok(isSettled(state), "and it is a settled answer");
});

test("an empty suggestions array is a completed answer, not a reason to keep waiting", (a) => {
  const key = profileKey(giftProfile([]));
  let state = requested(initialState(), key);

  state = received(state, key, { suggestions: [] });

  a.same(EMPTY, state.status, "an empty array settles as empty");
  a.not(state.status === LOADING, "it does not stay loading");
  a.ok(isSettled(state), "so the page can render a finished result immediately");
});

test("a malformed response is an error rather than an eternal spinner", (a) => {
  const key = profileKey(giftProfile(["Faith"]));

  for (const body of [null, undefined, {}, { suggestions: "nope" }, { suggestions: null }]) {
    const state = received(requested(initialState(), key), key, body);

    a.same(ERROR, state.status, `a response of ${JSON.stringify(body)} is an error`);
  }
});

test("a network failure renders the safe error state", (a) => {
  const key = profileKey(giftProfile(["Faith"]));
  const state = failed(requested(initialState(), key), key);

  a.same(ERROR, state.status, "it settles as an error");
  a.same(0, state.suggestions.length, "with nothing invented to show");
  a.ok(isSettled(state), "and the page stops waiting");
});

test("restart clears every piece of suggestion state", (a) => {
  const key = profileKey(giftProfile(["Faith"]));
  const answered = received(requested(initialState(), key), key, {
    suggestions: [{ team: "Prayer", tier: "strong", reasons: [] }],
  });

  a.same(READY, answered.status, "somebody has a result on screen");

  const after = cleared();

  a.same(IDLE, after.status, "restart returns to idle");
  a.same(0, after.suggestions.length, "the previous person's teams are gone");
  a.same(null, after.key, "the cached profile identity is gone");
  a.same("", after.error, "and any error is gone");
  a.same(JSON.stringify(initialState()), JSON.stringify(after), "it is exactly a fresh state");
});

test("a new profile asks again, where the old flag would have blocked it", (a) => {
  const firstKey = profileKey(giftProfile(["Faith", "Mercy"]));
  const answered = received(requested(initialState(), firstKey), firstKey, {
    suggestions: [{ team: "Prayer", tier: "strong", reasons: [] }],
  });

  // The exact scenario the module-level boolean broke: a second person on the
  // same device, after restart.
  const restarted = cleared();
  const secondKey = profileKey(giftProfile(["Teaching", "Leadership"]));

  a.ok(shouldRequest(restarted, secondKey), "the new profile triggers a request");
  a.ok(shouldRequest(answered, secondKey), "and so would a changed profile without restart");
  a.not(shouldRequest(answered, firstKey), "while the same profile does not ask twice");
});

test("a late response from the previous profile cannot overwrite the new one", (a) => {
  const oldKey = profileKey(giftProfile(["Faith", "Mercy"]));
  const newKey = profileKey(giftProfile(["Teaching"]));

  // Person one's request is in flight when person two starts.
  let state = requested(initialState(), oldKey);
  state = requested(state, newKey);

  a.same(newKey, state.key, "the state now belongs to the new profile" );

  // Person one's response finally lands.
  const afterStale = received(state, oldKey, {
    suggestions: [{ team: "Prayer", tier: "strong", reasons: [] }],
  });

  a.same(LOADING, afterStale.status, "the stale response is ignored");
  a.same(0, afterStale.suggestions.length, "and its teams never appear");

  // A stale failure is ignored too, so it cannot error the new page.
  const afterStaleError = failed(state, oldKey);
  a.same(LOADING, afterStaleError.status, "a stale failure is ignored as well");
});

test("the profile key changes when the answers do, and not otherwise", (a) => {
  const one = profileKey(giftProfile(["Faith"]));
  const same = profileKey(giftProfile(["Faith"]));
  const other = profileKey(giftProfile(["Mercy"]));

  a.same(one, same, "the same answers give the same key");
  a.not(one === other, "different answers give a different key");
  a.not(one === profileKey({ spiritualGifts: { likely: [], possible: [], unlikely: [] } }), "an empty assessment differs from a filled one");

  // Delimited, so two different sets of answers cannot collide into one key.
  a.not(
    profileKey(giftProfile(["Faith", "Mercy"])) === profileKey(giftProfile(["FaithMercy"])),
    "concatenation cannot make two different profiles look identical"
  );

  // A missing or malformed section must not throw on a page that has to render.
  a.same("||", profileKey({}), "a profile with no gifts still has a stable key");
  a.same("||", profileKey({ spiritualGifts: { likely: "nope" } }), "and so does a malformed one");
  a.not(profileKey({}) === one, "and an empty profile differs from a filled one");
});
