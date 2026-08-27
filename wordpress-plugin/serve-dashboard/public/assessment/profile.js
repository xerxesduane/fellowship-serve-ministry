import {
  abilities,
  experienceQuestions,
  gifts,
  heartQuestions,
  personalityPairs,
} from "./shapeContent.js";
import { ministryGiftTable } from "./ministryGiftTable.js";

const GIFT_MATCH_ALIASES = {
  administration: ["Organization"],
  apostle: ["Mission", "Vision", "Leadership"],
  pastoring: ["Mentoring", "Leadership"],
  "praying-with-my-spirit": ["Prayer"],
  preaching: ["Teaching", "Evangelism"],
  service: ["Assisting"],
};

function recommendMinistries(answers) {
  const ranked = ministryGiftTable.map((row, index) => {
    const ministryGifts = new Set(row.gifts.map((gift) => gift.toLowerCase()));
    const matches = gifts.filter((gift) => {
      const rating = answers.gifts[gift.id];
      if (rating !== "likely" && rating !== "possible") return false;
      const names = [gift.label, gift.alternateName, ...(GIFT_MATCH_ALIASES[gift.id] || [])].filter(Boolean);
      return names.some((name) => ministryGifts.has(name.toLowerCase()));
    });
    return {
      ministry: row.ministry,
      matchedGifts: matches.map((gift) => gift.label),
      score: matches.reduce((total, gift) => total + (answers.gifts[gift.id] === "likely" ? 3 : 1), 0),
      index,
    };
  }).sort((a, b) => b.score - a.score || a.index - b.index);

  const recommended = ranked.filter((row) => row.score > 0).slice(0, 3);
  const fallbacks = ["Serve", "Welcome", "Administration"];
  for (const ministry of fallbacks) {
    if (recommended.length === 3) break;
    const row = ranked.find((item) => item.ministry === ministry && !recommended.some((item) => item.ministry === ministry));
    if (row) recommended.push(row);
  }
  return recommended.map(({ ministry, matchedGifts }) => ({ ministry, matchedGifts }));
}

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
    recommendedMinistries: recommendMinistries(answers),
  };
}

export function profileToText(answers, profile) {
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
    `TOP MINISTRY MATCHES\n${profile.recommendedMinistries.map((item, index) => `${index + 1}. ${item.ministry}${item.matchedGifts.length ? ` — ${item.matchedGifts.join(", ")}` : ""}`).join("\n")}`,
    `RECOMMENDED NEXT STEP\n${profile.recommendedNextStep}`,
  ];
  return lines.filter((line) => line !== "").join("\n\n");
}
