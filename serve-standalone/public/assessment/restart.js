/**
 * Starting the journey over, safely.
 *
 * Extracted from app.js for the same reason handoff.js was: app.js reads
 * `document` at import time, so nothing defined inside it can be tested outside
 * a browser -- and what this decides is which of somebody's answers get thrown
 * away and which local storage keys get cleared. That is not code to leave
 * checked only by eye.
 *
 * Pure functions of their arguments. app.js owns the state; this owns the rules.
 */

import { icon } from "./render.js";

/**
 * The storage keys a restart must clear, and nothing else.
 *
 * `${key}-profile` is the one that was missed. The journey's own key held the
 * answers and was cleared; the finished profile lived beside it and was not,
 * and the consent page reads exactly that key to prefill the name, email and
 * phone it sends to the church. On a shared device -- the church iPad, a family
 * laptop -- somebody who started over and then opened the share link submitted
 * the previous person's contact details attached to their own answers, with
 * nothing on either page showing whose they were.
 *
 * @param {string} storageKey The journey's own key.
 * @returns {string[]}
 */
export function keysClearedOnRestart(storageKey) {
  return [storageKey, `${storageKey}-profile`];
}

/**
 * The "start over" control, in whichever of its three states applies.
 *
 * Idle it is a quiet inline button. Armed it is an explicit question with the
 * destructive answer named for what it does, so nobody confirms "yes" without
 * reading what they are saying yes to. Afterwards it offers the answers back,
 * because the only honest apology for discarding somebody's work is returning
 * it.
 *
 * Deliberately not window.confirm: a native dialog is unstyled, reads badly on
 * a phone, and announces the hostname in a journey that has spent nineteen
 * steps earning trust.
 */
export function restartControl({
  confirming = false,
  hasUndo = false,
  steps = 0,
  label = "Start over",
  buttonClass = "restart-inline",
  iconSize = 14,
} = {}) {
  if (hasUndo) {
    return `<span class="restart-confirm">Answers cleared. <button type="button" data-action="undo-restart" class="restart-undo">${icon("refresh", iconSize)}Undo, put them back</button></span>`;
  }

  if (confirming) {
    return `<span class="restart-confirm">Clear all ${steps} steps and start again? <button type="button" data-action="restart-confirm" class="restart-danger">Yes, clear my answers</button> <button type="button" data-action="restart-cancel" class="restart-inline">Keep them</button></span>`;
  }

  return `<button type="button" data-action="restart" class="${buttonClass}">${icon("refresh", iconSize)}${label}</button>`;
}
