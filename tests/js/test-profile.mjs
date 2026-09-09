/**
 * buildProfile — the object that becomes a person's stored profile.
 *
 * Everything downstream reads this: the server ranks teams from it, ministry
 * leaders read it in the dashboard, and the person downloads it. It had no
 * coverage, so any of it could be broken silently.
 */

import { test } from "./bootstrap.mjs";
import { buildProfile, emptyAnswers, optionLabels } from "../../serve-standalone/public/assessment/profile.js";
import { experienceQuestions, personalityPairs } from "../../serve-standalone/public/assessment/shapeContent.js";

/** A person who answered something in every section. */
function answered() {
  const a = emptyAnswers();
  a.profile = { name: "  Aiza Ramos  ", email: " aiza@example.test ", phone: " +971 50 000 0000 " };
  a.gifts = { administration: "likely", mercy: "possible", teaching: "unlikely" };
  a.selections = {
    "heart-roles": ["design-develop", "organize"],
    "heart-people": ["infants-babies"],
    "heart-causes": ["parenting"],
    abilities: ["counting", "welcoming", "linguistic:spanish"],
    "spiritual-experiences": ["baptized"],
    hours: ["3-5-hours"],
    timing: ["weekend", "weekday"],
  };
  a.personality = { energy: "extroverted", team: "cooperative" };
  a.text = { "service-priority": "Sunday mornings" };
  return a;
}

test("a completed assessment becomes the profile that gets submitted", (a) => {
  const p = buildProfile(answered());

  a.same({ likely: ["Administration"], possible: ["Mercy"], unlikely: ["Teaching"] }, p.spiritualGifts, "gifts land in the band they were rated");
  a.same(["DESIGN/DEVELOP", "ORGANIZE"], p.heart.roles, "heart roles are labelled");
  a.same(["Infants/Babies"], p.heart.people, "and people");
  a.same(["Parenting"], p.heart.causes, "and causes");
  a.same("Sunday mornings", p.availability.priority, "the priority is carried through");
  a.same("3-5 hours", p.availability.hours, "hours are a label, not an id");
  a.same(["Weekend", "Weekday"], p.availability.timing, "and so are the times");

  // The next step names the gifts the person was most sure about.
  a.contains("Administration", p.recommendedNextStep, "the next step names their likely gifts");
});

test("contact details are carried into the profile, trimmed", (a) => {
  const p = buildProfile(answered());

  /*
   * These were collected on the first step and then dropped, so the consent
   * page asked for all three again and a mistyped second answer made somebody
   * unreachable while their profile looked complete.
   */
  a.same({ name: "Aiza Ramos", email: "aiza@example.test", phone: "+971 50 000 0000" }, p.contact, "name, email and phone, without the surrounding spaces");
});

test("an 'Other' answer stays with the question it was typed under", (a) => {
  const answers = answered();
  answers.text["heart-roles-other"] = "  Mentoring new arrivals  ";
  answers.text["abilities-other"] = "Conflict mediation";

  const p = buildProfile(answers);

  a.same(["DESIGN/DEVELOP", "ORGANIZE", "Other: Mentoring new arrivals"], p.heart.roles, "trimmed and appended to its own question");
  a.ok(p.abilities.includes("Other: Conflict mediation"), "the abilities free-text is kept too");
  a.not(p.heart.people.includes("Other: Mentoring new arrivals"), "and does not leak into a neighbouring question");
});

test("blank 'Other' text is dropped rather than stored as an empty answer", (a) => {
  const answers = answered();
  answers.text["heart-causes-other"] = "   ";

  const p = buildProfile(answers);

  a.same(["Parenting"], p.heart.causes, "whitespace is not an answer");
  a.lacks("Other:", p.heart.causes.join("|"), "and no empty Other entry is invented");
});

test("work 'Other' entries land under the work question and no other", (a) => {
  /*
   * buildProfile reaches for experienceQuestions[3] by index to attach the
   * eleven per-industry free-text boxes. Reorder that array and the entries
   * silently attach to whichever question now sits at position three — a wrong
   * answer under the wrong prompt, with nothing to make it visible.
   */
  const work = experienceQuestions[3];
  a.same("work-experiences", work.id, "position three is still the work question");

  const answers = answered();
  answers.text["work-other-medical"] = "Paediatric nurse";
  answers.text["work-other-education-field"] = "Primary teacher";
  answers.text["work-other-military"] = "   ";

  const p = buildProfile(answers);

  // In the industry order the form asks them in, not the order they were typed.
  a.same(["Other: Primary teacher", "Other: Paediatric nurse"], p.experiences[work.prompt], "both industries, and not the blank one");

  const elsewhere = Object.entries(p.experiences)
    .filter(([prompt]) => prompt !== work.prompt)
    .flatMap(([, values]) => values);
  a.same([], elsewhere.filter((v) => v.startsWith("Other: Paediatric")), "and nowhere else");
});

test("every experience question appears, answered or not", (a) => {
  const p = buildProfile(emptyAnswers());

  a.same(experienceQuestions.length, Object.keys(p.experiences).length, "one entry per question");
  experienceQuestions.forEach((q) => {
    a.same([], p.experiences[q.prompt], q.id + " is present and empty");
  });
});

test("a nested ability is labelled with the parent it sits under", (a) => {
  const p = buildProfile(answered());

  // "Spanish" on its own says nothing; the parent is what makes it an ability.
  a.ok(p.abilities.includes("Linguistic ability: Spanish"), "the child carries its parent's label");
  a.not(p.abilities.includes("Spanish"), "and is not stored bare");
});

test("an unrecognised option id is kept rather than dropped", (a) => {
  /*
   * If the questionnaire drops an option a saved draft still refers to, the
   * honest failure is to show the raw id — losing the answer silently would
   * leave the person's profile quietly shorter than what they filled in.
   */
  a.same(["retired-option"], optionLabels([], ["retired-option"]), "the id survives as its own label");
});

test("personality uses the workbook's own labels and skips unanswered pairs", (a) => {
  const p = buildProfile(answered());

  a.same(["Be Extroverted", "Be Cooperative"], p.personality, "the two answered pairs, in questionnaire order");

  const known = personalityPairs.flatMap((pair) => [pair.left.label, pair.right.label]);
  a.ok(p.personality.every((label) => known.includes(label)), "every label comes from the questionnaire itself");
});

test("an untouched assessment still produces a complete profile", (a) => {
  const p = buildProfile(emptyAnswers());

  a.same({ likely: [], possible: [], unlikely: [] }, p.spiritualGifts, "the gift bands are present and empty");
  a.same([], p.personality, "personality is empty rather than missing");
  a.same("Not recorded", p.availability.priority, "and availability says so plainly");
  a.same("Not specified", p.availability.hours, "including the hours");

  // Not the gift-listing sentence, which would name nothing.
  a.contains("notice where your gifts become clearer", p.recommendedNextStep, "the next step falls back to something that reads as advice");
});

test("the browser does not decide which ministries a person is suggested", (a) => {
  /*
   * `suggested_teams` used to be built from a ranking computed here, in the
   * visitor's browser, against a hardcoded copy of the team list — and those
   * rows decide which ministry leaders may open the profile. The server ranks
   * it now. Asserted on the built profile rather than the source, because what
   * matters is that nothing of the kind is in what gets posted.
   */
  const p = buildProfile(answered());

  a.not("recommendedMinistries" in p, "no ministry ranking is submitted");
  a.not("rankedTeams" in p, "nor any other name for one");
  a.not("suggestedTeams" in p, "nor a third");
});

test("emptyAnswers hands out a fresh object every time", (a) => {
  const first = emptyAnswers();
  first.gifts.mercy = "likely";
  first.profile.name = "Someone";

  const second = emptyAnswers();

  a.same({}, second.gifts, "one person's answers do not appear in the next");
  a.same("", second.profile.name, "including their name");
});

test("building a profile twice from the same answers gives the same profile", (a) => {
  /*
   * buildProfile appends the work free-text onto an experiences array. If that
   * array were ever one of the questionnaire's own, the second call would come
   * back with the first call's answers still on it — and the questionnaire
   * would be wrong for everybody after.
   */
  const answers = answered();
  answers.text["work-other-medical"] = "Paediatric nurse";

  const first = buildProfile(answers);
  const second = buildProfile(answers);

  a.same(first, second, "nothing accumulated between the two");
});
