import {
  abilities,
  experienceQuestions,
  gifts,
  heartQuestions,
  personalityPairs,
} from "./shapeContent.js";

/*
 * The browser used to rank ministries here, by spiritual-gift name overlap
 * against a hardcoded copy of the team list, and what it returned became
 * `suggested_teams` on the server — so a function in the visitor's browser,
 * reading a table that could not see a team renamed or retired on the Teams
 * screen, decided which ministry leaders could open their profile.
 *
 * Ranked on the server now, across all five S.H.A.P.E. dimensions, against
 * the teams as they actually are. Nothing here computes a suggestion.
 */

export const emptyAnswers = () => ({
  profile: { name: "", email: "", phone: "" },
  gifts: {},
  selections: {},
  text: {},
  personality: {},
});

export function optionLabels(options, ids) {
  const entries = options.flatMap((option) => [
    [option.id, option.label],
    ...(option.children || []).map((child) => [
      `${option.id}:${child.id}`,
      `${option.label}: ${child.label}`,
    ]),
  ]);
  const labels = new Map(entries);
  return ids.map((id) => labels.get(id) || id).filter(Boolean);
}

function withOther(answers, questionId, options) {
  const selected = optionLabels(options, answers.selections[questionId] || []);
  const other = answers.text[`${questionId}-other`]?.trim();
  return other ? [...selected, `Other: ${other}`] : selected;
}

export function buildProfile(answers) {
  const giftGroups = { likely: [], possible: [], unlikely: [] };
  gifts.forEach((gift) => {
    const rating = answers.gifts[gift.id];
    if (rating) giftGroups[rating].push(gift.label);
  });

  const experiences = {};
  experienceQuestions.forEach((question) => {
    experiences[question.prompt] = withOther(answers, question.id, question.options);
  });
  const workOthers = [
    "automotive", "business", "computer", "entertainment", "education-field",
    "medical", "military", "public-civil", "tax-legal", "transportation", "utilities",
  ].map((id) => answers.text[`work-other-${id}`]?.trim())
    .filter(Boolean)
    .map((value) => `Other: ${value}`);
  experiences[experienceQuestions[3].prompt].push(...workOthers);

  const personality = personalityPairs.map((pair) => {
    const id = answers.personality[pair.id];
    return [pair.left, pair.right].find((choice) => choice.id === id)?.label;
  }).filter(Boolean);

  const hours = optionLabels([
    { id: "1-2-hours", label: "1-2 hours" },
    { id: "3-5-hours", label: "3-5 hours" },
    { id: "6-hours", label: "6+ hours" },
  ], answers.selections.hours || [])[0] || "Not specified";
  const timing = optionLabels([
    { id: "weekday", label: "Weekday" },
    { id: "weeknight", label: "Weeknight" },
    { id: "weekend", label: "Weekend" },
  ], answers.selections.timing || []);
  const recommendedNextStep = giftGroups.likely.length
    ? `Explore current serving opportunities where your ${giftGroups.likely.slice(0, 3).join(", ")} gifts can be tested and developed.`
    : "Explore one or two current serving opportunities and notice where your gifts become clearer through experience.";

  return {
    /*
     * The contact details, carried through with the rest of the profile.
     *
     * They were collected on the first step and then dropped here, so the
     * consent page — the one place they are actually needed — asked for all
     * three again. Somebody who mistyped their email the second time became
     * unreachable while appearing perfectly complete.
     */
    contact: {
      name: (answers.profile.name || "").trim(),
      email: (answers.profile.email || "").trim(),
      phone: (answers.profile.phone || "").trim(),
    },
    spiritualGifts: giftGroups,
    heart: {
      roles: withOther(answers, "heart-roles", heartQuestions[0].options),
      people: withOther(answers, "heart-people", heartQuestions[1].options),
      causes: withOther(answers, "heart-causes", heartQuestions[2].options),
    },
    abilities: [
      ...optionLabels(abilities, answers.selections.abilities || []),
      ...["ability-languages-other", "ability-hobbies-other", "ability-technical-other", "abilities-other"]
        .map((key) => answers.text[key]?.trim())
        .filter(Boolean)
        .map((value) => `Other: ${value}`),
    ],
    personality,
    experiences,
    availability: {
      priority: answers.text["service-priority"] || "Not recorded",
      hours,
      timing,
    },
    recommendedNextStep,
  };
}

/**
 * The profile as text, for copying, emailing and printing.
 *
 * `ranked` is the server's ranking, once the results page has fetched it. It is
 * passed in rather than read from the profile because the profile object is what
 * gets submitted, and the suggestions are no longer part of it: the server ranks
 * them from the answers at submission time, so a copy travelling inside the
 * payload would be a second answer to the same question.
 *
 * Absent when the request has not answered, and the text says so rather than
 * substituting something weaker.
 */
export function profileToText(answers, profile, ranked = null) {
  const lines = [
    "MY S.H.A.P.E. PROFILE - FELLOWSHIP DUBAI",
    answers.profile.name ? `Name: ${answers.profile.name}` : "",
    answers.profile.email ? `Email: ${answers.profile.email}` : "",
    answers.profile.phone ? `Phone: ${answers.profile.phone}` : "",
    "",
    `SPIRITUAL GIFTS\nLikely: ${profile.spiritualGifts.likely.join(", ") || "None selected"}\nPossible: ${profile.spiritualGifts.possible.join(", ") || "None selected"}\nUnlikely: ${profile.spiritualGifts.unlikely.join(", ") || "None selected"}`,
    `HEART / PASSION\nRoles: ${profile.heart.roles.join(", ") || "None selected"}\nPeople: ${profile.heart.people.join(", ") || "None selected"}\nCauses: ${profile.heart.causes.join(", ") || "None selected"}`,
    `ABILITIES\n${profile.abilities.join(", ") || "None selected"}`,
    `PERSONALITY\n${profile.personality.join(" / ") || "Not completed"}`,
    "EXPERIENCES",
    ...Object.entries(profile.experiences).map(([label, values]) => `${label}: ${values.join(", ") || "None selected"}`),
    `AVAILABILITY\nService priority: ${profile.availability.priority}\nTime per week: ${profile.availability.hours}\nBest times: ${profile.availability.timing.join(", ") || "Not specified"}`,
    ranked && ranked.length
      ? `WHERE YOUR S.H.A.P.E. POINTS\n${ranked.map((item, index) => `${index + 1}. ${item.team} (${item.strengthLabel})${item.reasons.length ? `\n   ${item.reasons.slice(0, 4).join("\n   ")}` : ""}`).join("\n")}`
      : "WHERE YOUR S.H.A.P.E. POINTS\nWe could not work these out just now. Your answers are saved, and a ministry leader will have them.",
    `RECOMMENDED NEXT STEP\n${profile.recommendedNextStep}`,
  ];
  return lines.filter((line) => line !== "").join("\n\n");
}
