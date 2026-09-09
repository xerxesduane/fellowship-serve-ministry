/**
 * Starting the journey over.
 *
 * Nineteen steps of somebody's answers, including the Experiences section the
 * app itself warns is painful to fill in, and until recently one tap discarded
 * all of it with no confirmation and nothing to undo.
 */

import { test } from "./bootstrap.mjs";
import { keysClearedOnRestart, restartControl } from "../../serve-standalone/public/assessment/restart.js";

const KEY = "fellowship-dubai-shape-v2";

test("restarting clears the finished profile as well as the answers", (a) => {
  /*
   * The bug this exists to prevent. The journey's key was cleared and the
   * `-profile` key beside it was not, and the consent page reads exactly that
   * key to prefill the name, email and phone it sends to the church. On a
   * shared device somebody who started over then submitted the previous
   * person's contact details attached to their own answers.
   */
  const keys = keysClearedOnRestart(KEY);

  a.ok(keys.includes(KEY), "the answers go");
  a.ok(keys.includes(`${KEY}-profile`), "and the finished profile goes with them");
  a.same(2, keys.length, "and nothing else is touched");
});

test("the profile key is derived from the answers key, not written out twice", (a) => {
  // A second hardcoded string is how the two drift apart again.
  a.same(["x", "x-profile"], keysClearedOnRestart("x"), "one key, one suffix");
});

test("one tap asks rather than destroying", (a) => {
  const idle = restartControl({ steps: 19 });

  a.contains('data-action="restart"', idle, "the idle control only arms the question");
  a.lacks('data-action="restart-confirm"', idle, "and does not offer the destructive answer yet");
});

test("the armed state names what will be lost and how much", (a) => {
  const armed = restartControl({ confirming: true, steps: 19 });

  a.contains("Clear all 19 steps", armed, "it says how many steps go");
  a.contains("Yes, clear my answers", armed, "the destructive answer says what it does");
  a.contains('data-action="restart-cancel"', armed, "and there is a way out");
  a.contains("Keep them", armed, "phrased as keeping rather than cancelling");
});

test("the step count comes from the journey rather than being hardcoded", (a) => {
  // A journey that gains or loses a step must not make this sentence lie.
  a.contains("Clear all 7 steps", restartControl({ confirming: true, steps: 7 }), "seven steps");
  a.contains("Clear all 23 steps", restartControl({ confirming: true, steps: 23 }), "twenty-three steps");
});

test("after clearing, the answers are offered back", (a) => {
  const cleared = restartControl({ hasUndo: true, steps: 19 });

  a.contains('data-action="undo-restart"', cleared, "an undo is offered");
  a.contains("put them back", cleared, "in words that say what it does");
  a.lacks('data-action="restart"', cleared, "and starting over again is not the only option on offer");
});

test("the undo offer wins over the confirmation prompt", (a) => {
  /*
   * If both flags were somehow set, showing the question again would invite
   * somebody to clear answers that are already cleared, and lose the undo.
   */
  const both = restartControl({ confirming: true, hasUndo: true, steps: 19 });

  a.contains("put them back", both, "the undo is what is shown");
  a.lacks("Yes, clear my answers", both, "not a second chance to destroy them");
});

test("the results page shares the same confirmation", (a) => {
  /*
   * "Start a new profile" on the results page is the same destructive action.
   * It used to be a bare button, and when the confirmation was added to the
   * journey footer alone this one became a control that armed a question
   * nothing rendered — a button that appeared to do nothing.
   */
  const idle = restartControl({ steps: 19, label: "Start a new profile", buttonClass: "back-button", iconSize: 17 });

  a.contains("Start a new profile", idle, "it keeps its own wording");
  a.contains('class="back-button"', idle, "and its own styling");
  a.contains('data-action="restart"', idle, "but arms the same confirmation");

  a.contains("Clear all 19 steps", restartControl({ confirming: true, steps: 19, label: "Start a new profile" }), "which then asks");
});

test("no state produces a control with nothing to press", (a) => {
  [{}, { confirming: true }, { hasUndo: true }, { confirming: true, hasUndo: true }].forEach((state) => {
    a.contains("<button", restartControl({ ...state, steps: 19 }), "every state offers at least one control");
  });
});
