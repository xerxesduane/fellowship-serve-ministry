/**
 * profileToText — what the person copies, emails, prints and takes away.
 *
 * This is the only part of the assessment that leaves the browser in the
 * person's own hands, so a section quietly missing from it is not recoverable
 * later by looking at the dashboard.
 */

import { test } from "./bootstrap.mjs";
import { buildProfile, emptyAnswers, profileToText } from "../../wordpress-plugin/serve-dashboard/public/assessment/profile.js";

function answered() {
  const a = emptyAnswers();
  a.profile = { name: "Aiza Ramos", email: "aiza@example.test", phone: "+971 50 000 0000" };
  a.gifts = { administration: "likely", mercy: "possible" };
  a.selections = { "heart-roles": ["organize"], abilities: ["counting"], hours: ["3-5-hours"] };
  a.personality = { energy: "extroverted" };
  return a;
}

/*
 * The results page's request state, which is what profileToText now takes.
 *
 * It used to take a bare array, so three different situations — a completed
 * result with no strong match, a request that failed, and one still in flight —
 * all arrived as null or [] and were reported as the last of them.
 */
const READY = {
  status: "ready",
  suggestions: [
    {
      team: "Administration",
      tierLabel: "Strong gift match",
      reasons: ["Administration is a gift they are sure of", "Counting ability", "Organising is what they love", "Weekend availability", "A fifth reason"],
      unmappedLikely: [],
    },
    { team: "Welcome", tierLabel: "Suggested team to explore", reasons: [], coMatch: true },
  ],
};

const state = (status, suggestions = []) => ({ status, suggestions });

test("the profile text carries every section of the assessment", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), READY);

  ["SPIRITUAL GIFTS", "HEART / PASSION", "ABILITIES", "PERSONALITY", "EXPERIENCES", "AVAILABILITY", "RECOMMENDED NEXT STEP"].forEach((heading) => {
    a.contains(heading, text, heading + " is in the text they take away");
  });

  a.contains("Name: Aiza Ramos", text, "and their name");
  a.contains("Email: aiza@example.test", text, "and their email");
  a.contains("Likely: Administration", text, "gifts appear under the band they were rated");
  a.contains("Possible: Mercy", text, "including the possible ones");
});

test("a section with nothing in it says so rather than reading as absent", (a) => {
  const answers = emptyAnswers();
  const text = profileToText(answers, buildProfile(answers), null);

  a.contains("Likely: None selected", text, "an empty gift band is labelled");
  a.contains("PERSONALITY\nNot completed", text, "and an unanswered personality section");
  a.contains("Best times: Not specified", text, "and availability");

  // No name was given, so no blank "Name:" line is emitted.
  a.lacks("Name:", text, "an unanswered contact line is left out entirely");
});

test("the server's recommendations are used when it has answered", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), READY);

  a.contains("WHERE YOUR GIFTS POINT", text, "the section is there");
  a.contains("- Administration (Strong gift match)", text, "with the tier in words rather than a percentage");
  a.contains("- Welcome (Suggested team to explore)", text, "and the second team");
  a.contains("Counting ability", text, "with the reasons behind the match");

  /*
   * Not numbered. Where two teams are separated only by their slug, a 1/2/3
   * list reads as a ranking the evidence does not support, and a co-match says
   * so outright instead.
   */
  a.lacks("1. Administration", text, "the list is not numbered");
  a.contains("Welcome (Suggested team to explore) — equally well supported", text, "and a co-match is labelled as one");

  // Four reasons is the cap; a fifth would turn the takeaway into a printout.
  a.lacks("A fifth reason", text, "and no more than four of them");
});

test("a team with no reasons is still listed, without an empty gap", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), state("ready", [READY.suggestions[1]]));

  a.contains("- Welcome (Suggested team to explore)", text, "the team is named");
  a.lacks("Suggested team to explore) — equally well supported\n   \n", text, "and no blank reason line is printed under it");
});

test("when the ranking is missing the text says so plainly", (a) => {
  /*
   * The suggestions come from the server after the profile is submitted, so
   * the request can fail while everything else on the page is fine. Saying
   * nothing would read as "no team suits you", which is both untrue and the
   * worst possible thing for this document to imply.
   */
  const answers = answered();

  const failed = profileToText(answers, buildProfile(answers), state("error"));
  a.contains("WHERE YOUR GIFTS POINT", failed, "the heading stays, so nothing looks lost");
  a.contains("could not work these out just now", failed, "and it says what happened");
  a.contains("your profile above is complete", failed, "and that their answers are safe");

  // Still in flight, or never asked: not the same claim as a failure.
  [null, undefined, state("idle"), state("loading")].forEach((pending) => {
    const text = profileToText(answers, buildProfile(answers), pending);
    a.contains("WHERE YOUR GIFTS POINT", text, "the heading stays while waiting");
    a.contains("had not finished loading", text, "and says so rather than claiming a failure");
  });
});

test("a completed no-match is reported as a real result, not as a failure", (a) => {
  /*
   * The distinction this file could not previously draw. An empty response is
   * the honest answer for somebody whose gifts do not point anywhere yet, and
   * saying "we could not work these out" about it would be untrue.
   */
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), state("empty"));

  a.contains("do not point clearly to one team yet", text, "it says what was actually found");
  a.contains("That is not a failed result", text, "and does not read as a fault");
  a.lacks("could not work these out", text, "and is not confused with an error");
});

test("likely gifts the ministry table cannot measure are named, not silently dropped", (a) => {
  const answers = answered();
  const text = profileToText(
    answers,
    buildProfile(answers),
    state("ready", [{ team: "Prayer", tierLabel: "Strong gift match", reasons: [], unmappedLikely: ["Pastoring"] }])
  );

  a.contains("Not counted: Pastoring", text, "the unmapped gift is named");
  a.contains("not treated as anything else", text, "and it is clear nothing was substituted for it");
});

test("the text never carries a ranking the browser worked out for itself", (a) => {
  /*
   * There used to be a browser-side ranking printed here under its own
   * heading, computed from spiritual-gift name overlap alone. It is gone, and
   * the only suggestions in this document are the ones passed in from the
   * server.
   */
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), null);

  a.lacks("TOP MINISTRY MATCHES", text, "the old heading is gone");
  a.lacks("Administration (", text, "and no team is named when the server said nothing");
});

test("the text is built from the profile, not re-derived from the answers", (a) => {
  /*
   * A leader editing what a person's profile says should change the document
   * that person can be sent. If this function reached back into the raw
   * answers, the two would disagree with no way to tell which was current.
   */
  const answers = answered();
  const profile = buildProfile(answers);
  profile.spiritualGifts.likely = ["Hospitality"];

  const text = profileToText(answers, profile, null);

  a.contains("Likely: Hospitality", text, "the profile is what gets printed");
  a.lacks("Likely: Administration", text, "not the answers behind it");
});
