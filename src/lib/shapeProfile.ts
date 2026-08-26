import {
  abilities, experienceQuestions, giftResponseOptions, gifts, heartQuestions,
  personalityPairs, type Option, type ProfileSummary,
} from "@/data/shapeContent";
import { ministryGiftTable } from "@/data/ministryGiftTable";

const GIFT_MATCH_ALIASES: Record<string, string[]> = {
  administration: ["Organization"],
  apostle: ["Mission", "Vision", "Leadership"],
  pastoring: ["Mentoring", "Leadership"],
  "praying-with-my-spirit": ["Prayer"],
  preaching: ["Teaching", "Evangelism"],
  service: ["Assisting"],
};

function recommendMinistries(answers: ShapeAnswers) {
  const ranked = ministryGiftTable.map((row, index) => {
    const ministryGifts = new Set(row.gifts.map((gift) => gift.toLowerCase()));
    const matches = gifts.filter((gift) => {
      const rating = answers.gifts[gift.id];
      if (rating !== "likely" && rating !== "possible") return false;
      const names = [gift.label, gift.alternateName, ...(GIFT_MATCH_ALIASES[gift.id] || [])].filter((name): name is string => Boolean(name));
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

export type GiftResponse = "likely" | "possible" | "unlikely";

export type ShapeAnswers = {
  profile: { name: string; email: string; phone: string };
  gifts: Record<string, GiftResponse>;
  selections: Record<string, string[]>;
  text: Record<string, string>;
  personality: Record<string, string>;
};

export const emptyShapeAnswers: ShapeAnswers = {
  profile: { name: "", email: "", phone: "" }, gifts: {}, selections: {}, text: {}, personality: {},
};

export function flattenOptions(options: Option[]): Option[] {
  return options.flatMap((option) => [option, ...(option.children ? flattenOptions(option.children) : [])]);
}

export function optionLabels(options: Option[], ids: string[]) {
  const entries = options.flatMap((option) => [
    [option.id, option.label] as const,
    ...(option.children || []).map((child) => [`${option.id}:${child.id}`, `${option.label}: ${child.label}`] as const),
  ]);
  const map = new Map(entries);
  return ids.map((id) => map.get(id) || id).filter(Boolean);
}

function withOther(answers: ShapeAnswers, questionId: string, options: Option[]) {
  const selected = optionLabels(options, answers.selections[questionId] || []);
  const other = answers.text[`${questionId}-other`]?.trim();
  return other ? [...selected, `Other: ${other}`] : selected;
}

export function buildProfile(answers: ShapeAnswers): ProfileSummary {
  const giftGroups = { likely: [] as string[], possible: [] as string[], unlikely: [] as string[] };
  gifts.forEach((gift) => {
    const rating = answers.gifts[gift.id];
    if (rating) giftGroups[rating].push(gift.label);
  });

  const experienceMap: Record<string, string[]> = {};
  experienceQuestions.forEach((question) => {
    experienceMap[question.prompt] = withOther(answers, question.id, question.options);
  });
  const workOthers = ["automotive", "business", "computer", "entertainment", "education-field", "medical", "military", "public-civil", "tax-legal", "transportation", "utilities"]
    .map((id) => answers.text[`work-other-${id}`]?.trim()).filter((value): value is string => Boolean(value)).map((value) => `Other: ${value}`);
  experienceMap[experienceQuestions[3].prompt].push(...workOthers);

  const personality = personalityPairs.map((pair) => {
    const id = answers.personality[pair.id];
    return [pair.left, pair.right].find((choice) => choice.id === id)?.label;
  }).filter((label): label is string => Boolean(label));

  const hours = optionLabels([{ id: "1-2-hours", label: "1-2 hours" }, { id: "3-5-hours", label: "3-5 hours" }, { id: "6-hours", label: "6+ hours" }], answers.selections.hours || [])[0] || "Not specified";
  const timing = optionLabels([{ id: "weekday", label: "Weekday" }, { id: "weeknight", label: "Weeknight" }, { id: "weekend", label: "Weekend" }], answers.selections.timing || []);
  const recommendation = giftGroups.likely.length
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
      ...["ability-languages-other", "ability-hobbies-other", "ability-technical-other", "abilities-other"].map((key) => answers.text[key]?.trim()).filter((value): value is string => Boolean(value)).map((value) => `Other: ${value}`),
    ],
    personality,
    experiences: experienceMap,
    availability: { priority: answers.text["service-priority"] || "Not recorded", hours, timing },
    recommendedNextStep: recommendation,
    recommendedMinistries: recommendMinistries(answers),
  };
}

export function profileToText(answers: ShapeAnswers, profile: ProfileSummary) {
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

export { giftResponseOptions };
