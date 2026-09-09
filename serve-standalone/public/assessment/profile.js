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
 * What the takeaway document says about teams, in the page's own words.
 *
 * Reads the same request state the results page renders from, so the printed,
 * copied and emailed copies cannot disagree with the screen. It used to take a
 * bare array, which collapsed three different situations — a completed result
 * with no strong match, a request that failed, and a request still in flight —
 * into one sentence claiming the last of them.
 */
function teamsSection(state) {
  const heading = "WHERE YOUR GIFTS POINT";

  if (!state || state.status === "idle" || state.status === "loading") {
    return `${heading}\nThese had not finished loading when this copy was made. Your answers are saved, and the SERVE team will have them.`;
  }

  if (state.status === "error") {
    return `${heading}\nWe could not work these out just now. Nothing is lost — your profile above is complete, and the SERVE team can go through it with you.`;
  }

  if (state.status === "empty" || !state.suggestions.length) {
    return `${heading}\nYour answers do not point clearly to one team yet. That is not a failed result: a conversation and a trial serving opportunity will tell you more than a forced match would.`;
  }

  const lines = state.suggestions.map((item) => {
    const label = item.tierLabel || item.strengthLabel || "";
    const reasons = Array.isArray(item.reasons) ? item.reasons.slice(0, 4) : [];
    const why = reasons.length ? `\n   ${reasons.join("\n   ")}` : "";

    // No numbering: where two teams are separated only by their slug, an
    // ordered list would read as a ranking the evidence does not support.
    return `- ${item.team} (${label})${item.coMatch ? " — equally well supported" : ""}${why}`;
  });

  const unmapped = state.suggestions[0] && Array.isArray(state.suggestions[0].unmappedLikely)
    ? state.suggestions[0].unmappedLikely
    : [];

  const note = unmapped.length
    ? `\n\nNot counted: ${unmapped.join(", ")}. The current Fellowship ministry table does not map ${unmapped.length === 1 ? "that gift" : "those gifts"} yet, and ${unmapped.length === 1 ? "it was" : "they were"} not treated as anything else.`
    : "";

  return `${heading}\nBased on your spiritual gifts. Your interests, availability, experience and each ministry's requirements still matter.\n\n${lines.join("\n")}${note}`;
}

/**
 * The profile as text, for copying, emailing and printing.
 *
 * `state` is the results page's request state, once it has one. It is passed in
 * rather than read from the profile because the profile object is what gets
 * submitted, and the recommendations are no longer part of it: the server works
 * them out from the answers at submission time, so a copy travelling inside the
 * payload would be a second answer to the same question.
 */
export function profileToText(answers, profile, state = null) {
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
    teamsSection(state),
    `RECOMMENDED NEXT STEP\n${profile.recommendedNextStep}`,
  ];
  return lines.filter((line) => line !== "").join("\n\n");
}
