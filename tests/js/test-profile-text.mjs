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

const RANKED = [
  {
    team: "Administration",
    strengthLabel: "Strong match",
    reasons: ["Administration is a gift they are sure of", "Counting ability", "Organising is what they love", "Weekend availability", "A fifth reason"],
  },
  { team: "Welcome", strengthLabel: "Possible match", reasons: [] },
];

test("the profile text carries every section of the assessment", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), RANKED);

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

test("the server's ranking is used when it has answered", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), RANKED);

  a.contains("WHERE YOUR S.H.A.P.E. POINTS", text, "the section is there");
  a.contains("1. Administration (Strong match)", text, "numbered, with the strength in words rather than a percentage");
  a.contains("2. Welcome (Possible match)", text, "and the second team");
  a.contains("Counting ability", text, "with the reasons behind the match");

  // Four reasons is the cap; a fifth would turn the takeaway into a printout.
  a.lacks("A fifth reason", text, "and no more than four of them");
});

test("a team with no reasons is still listed, without an empty gap", (a) => {
  const answers = answered();
  const text = profileToText(answers, buildProfile(answers), [RANKED[1]]);

  a.contains("1. Welcome (Possible match)", text, "the team is named");
  a.lacks("Welcome (Possible match)\n   \n", text, "and no blank reason line is printed under it");
});

test("when the ranking is missing the text says so plainly", (a) => {
  /*
   * The suggestions come from the server after the profile is submitted, so
   * the request can fail while everything else on the page is fine. Saying
   * nothing would read as "no team suits you", which is both untrue and the
   * worst possible thing for this document to imply.
   */
  const answers = answered();

  [null, [], undefined].forEach((ranked) => {
    const text = profileToText(answers, buildProfile(answers), ranked);
    a.contains("WHERE YOUR S.H.A.P.E. POINTS", text, "the heading stays, so nothing looks lost");
    a.contains("could not work these out just now", text, "and it says what happened");
    a.contains("a ministry leader will have them", text, "and that their answers are safe");
  });
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
