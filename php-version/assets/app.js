import {
  abilities,
  abilitiesSection,
  availabilitySection,
  experienceQuestions,
  experiencesSection,
  giftResponseOptions,
  gifts,
  heartQuestions,
  heartSection,
  journeySteps,
  personalityPairs,
  personalitySection,
  spiritualGiftsSection,
  startWays,
  tableOfContents,
  welcomeSection,
  whatIsShapeSection,
  workExperiences,
} from "./shapeContent.js";
import { ministryGiftTable } from "./ministryGiftTable.js";
import { buildProfile, emptyAnswers, profileToText } from "./profile.js";

const STORAGE_KEY = "fellowship-dubai-shape-v2";
const SERVING_FORM = "https://fellowshipdubai.churchcenter.com/people/forms/268058";
const WORK_OTHER_GROUPS = [
  "automotive", "business", "computer", "entertainment", "education-field",
  "medical", "military", "public-civil", "tax-legal", "transportation", "utilities",
];

const root = document.querySelector("#shape-app");
const searches = {};
const openGroups = new Set();
let step = 0;
let answers = emptyAnswers();
let copied = false;

function restore() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
    if (saved?.answers) {
      answers = {
        ...emptyAnswers(),
        ...saved.answers,
        profile: { ...emptyAnswers().profile, ...(saved.answers.profile || {}) },
        gifts: saved.answers.gifts || {},
        selections: saved.answers.selections || {},
        text: saved.answers.text || {},
        personality: saved.answers.personality || {},
      };
    }
    if (Number.isInteger(saved?.step) && saved.step >= 0 && saved.step < journeySteps.length) {
      step = saved.step;
    }
  } catch {
    answers = emptyAnswers();
  }
}

function save() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ step, answers }));
  } catch {
    // Storage is a convenience; the assessment remains usable without it.
  }
}

function escapeHtml(value = "") {
  return String(value).replace(/[&<>'"]/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;",
  })[character]);
}

function icon(name, size = 18) {
  const paths = {
    arrowLeft: '<path d="m15 18-6-6 6-6"/><path d="M9 12h12"/>',
    arrowRight: '<path d="M9 18l6-6-6-6"/><path d="M3 12h12"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    chevron: '<path d="m6 9 6 6 6-6"/>',
    clipboard: '<rect width="14" height="16" x="5" y="4" rx="2"/><path d="M9 4V2h6v2"/>',
    compass: '<circle cx="12" cy="12" r="10"/><path d="m16 8-3 5-5 3 3-5 5-3Z"/>',
    external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    lock: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-10 6L2 7"/>',
    printer: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.34 5.66"/><path d="M20 4v7h-7"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
  };
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || ""}</svg>`;
}

function sourcePage(page) {
  return Array.isArray(page) ? page.join("-") : page;
}

function teachingCard(section) {
  const scripture = section.scripture ? `
    <blockquote>${icon("book", 19)}<p>“${escapeHtml(section.scripture.text)}”<cite>${escapeHtml(section.scripture.reference)}</cite></p></blockquote>` : "";
  const paragraphs = (section.paragraphs || []).map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`).join("");
  const bullets = section.bullets ? `<ul class="teaching-list">${section.bullets.map((item) => `<li>${icon("check", 17)}<span>${escapeHtml(item)}</span></li>`).join("")}</ul>` : "";
  return `<article class="teaching-card">
    <header class="teaching-header">
      ${section.letter ? `<span class="section-letter">${escapeHtml(section.letter)}</span>` : ""}
      <div><p class="eyebrow">Source page ${sourcePage(section.page)}</p><h1>${escapeHtml(section.title)}</h1><p>${escapeHtml(section.eyebrow || "")}</p></div>
    </header>
    ${scripture}<div class="teaching-copy">${paragraphs}</div>${bullets}
  </article>`;
}

function welcome() {
  return `<div class="welcome-layout">
    <div class="welcome-brand">
      <div class="shape-orbit" role="img" aria-label="S.H.A.P.E.: Spiritual Gifts, Heart, Abilities, Personality, and Experiences">${icon("compass", 42)}<span aria-hidden="true">S</span><span aria-hidden="true">H</span><span aria-hidden="true">A</span><span aria-hidden="true">P</span><span aria-hidden="true">E</span></div>
      <p>FELLOWSHIP DUBAI</p><h2>Know Jesus<br>Grow to be like Jesus<br>Go tell the nations about Jesus</h2>
    </div>
    <div>${teachingCard(welcomeSection)}
      <fieldset class="profile-fields"><legend>Your profile details</legend><p>These optional details will appear on your completed profile if you choose to download or share it.</p>
        ${profileInput("name", "Name", "text", "name")}
        ${profileInput("email", "Email", "email", "email")}
        ${profileInput("phone", "Phone", "tel", "tel")}
      </fieldset>
    </div>
  </div>`;
}

function profileInput(id, label, type, autocomplete) {
  return `<label><span>${label}</span><input type="${type}" data-profile="${id}" value="${escapeHtml(answers.profile[id])}" autocomplete="${autocomplete}"></label>`;
}

function contents() {
  return `<article class="contents-card"><p class="eyebrow">Source page 2 · Your journey map</p><h1>Table of Contents</h1><p>We will move through the source in order, with teaching before each reflection.</p><ol>${tableOfContents.map(([title, page]) => `<li><span>${escapeHtml(title)}</span><b>${escapeHtml(page)}</b></li>`).join("")}</ol></article>`;
}

function giftAssessment() {
  return `<div class="gift-assessment"><header class="assessment-heading"><p class="eyebrow">Source pages 5-7</p><h1>Unwrapping My Gifts</h1><p>For every gift, choose the response that best describes you. Your profile will group all gifts as likely, possible, or unlikely.</p></header><div class="gift-list">${gifts.map((gift) => `
    <fieldset class="gift-full-row"><legend><span><strong>${escapeHtml(gift.label)}</strong><small>${escapeHtml(gift.reference)}${gift.alternateName ? ` · Also called ${escapeHtml(gift.alternateName)}` : ""}</small></span><p>${escapeHtml(gift.description)}</p></legend>
      <div class="gift-response-grid">${giftResponseOptions.map((response) => `<button type="button" data-action="gift" data-gift="${gift.id}" data-value="${response.id}" aria-pressed="${answers.gifts[gift.id] === response.id}" class="${answers.gifts[gift.id] === response.id ? "selected" : ""}">${escapeHtml(response.label)}</button>`).join("")}</div>
    </fieldset>`).join("")}</div></div>`;
}

function matches(option, query) {
  return `${option.label} ${option.description || ""} ${(option.children || []).map((child) => child.label).join(" ")}`.toLowerCase().includes(query);
}

function multiSelect({ label, help = "", options, questionId, otherLabel = "", searchable = false, maxSelections = 0 }) {
  const selected = answers.selections[questionId] || [];
  const query = (searches[questionId] || "").trim().toLowerCase();
  const visible = query ? options.filter((option) => matches(option, query)) : options;
  const search = searchable ? `<label class="search-field">${icon("search")}<span class="sr-only">Search options</span><input data-search="${questionId}" value="${escapeHtml(searches[questionId] || "")}" placeholder="Search this list…"></label>` : "";
  const other = otherLabel ? `<label class="other-field"><span>${escapeHtml(otherLabel)}</span><input data-text="${questionId}-other" value="${escapeHtml(answers.text[`${questionId}-other`] || "")}" placeholder="Type your own response"></label>` : "";
  return `<fieldset class="multi-field"><legend>${escapeHtml(label)}</legend>${help ? `<p class="field-help">${escapeHtml(help)}</p>` : ""}${maxSelections ? `<p class="selection-count">Select up to ${maxSelections} · ${selected.length} selected</p>` : ""}${search}<div class="option-grid">${visible.map((option) => option.children ? nestedOption(option, questionId, selected, query, maxSelections) : optionButton(option, option.id, questionId, selected, maxSelections)).join("")}</div>${other}</fieldset>`;
}

function nestedOption(option, questionId, selected, query, maxSelections) {
  const groupKey = `${questionId}:${option.id}`;
  const open = Boolean(query) || openGroups.has(groupKey);
  return `<details class="nested-option" data-group="${groupKey}" ${open ? "open" : ""}><summary><span>${escapeHtml(option.label)}</span>${icon("chevron")}</summary>${option.description ? `<p>${escapeHtml(option.description)}</p>` : ""}<div class="nested-grid">${option.children.map((child) => optionButton(child, `${option.id}:${child.id}`, questionId, selected, maxSelections)).join("")}</div></details>`;
}

function optionButton(option, value, questionId, selected, maxSelections) {
  const isSelected = selected.includes(value);
  const disabled = maxSelections && selected.length >= maxSelections && !isSelected;
  return `<button type="button" class="option-button ${isSelected ? "selected" : ""}" data-action="toggle" data-question="${questionId}" data-value="${escapeHtml(value)}" aria-pressed="${isSelected}" ${disabled ? "disabled" : ""}><span class="choice-check">${isSelected ? icon("check", 14) : ""}</span><span><strong>${escapeHtml(option.label)}</strong>${option.description ? `<small>${escapeHtml(option.description)}</small>` : ""}</span></button>`;
}

function otherInputs(fields) {
  return `<div class="other-input-grid">${fields.map(([id, label]) => `<label class="other-field"><span>${escapeHtml(label)}</span><input data-text="${id}" value="${escapeHtml(answers.text[id] || "")}" placeholder="Type your own response"></label>`).join("")}</div>`;
}

function personalityAssessment() {
  return `<div><header class="assessment-heading"><p class="eyebrow">Source page 15</p><h1>Plugging In My Personality</h1><p>Instructions: Circle one or the other. There is no right or wrong temperament.</p></header><div class="personality-list">${personalityPairs.map((pair) => `<fieldset class="personality-pair"><legend>${escapeHtml(pair.prompt)}</legend>${[pair.left, pair.right].map((choice) => {
    const selected = answers.personality[pair.id] === choice.id;
    return `<button type="button" data-action="personality" data-pair="${pair.id}" data-value="${choice.id}" aria-pressed="${selected}" class="${selected ? "selected" : ""}"><span class="radio-dot"></span><strong>${escapeHtml(choice.label)}</strong><small>${escapeHtml(choice.description)}</small></button>`;
  }).join("")}</fieldset>`).join("")}</div></div>`;
}

function availability() {
  const hours = availabilitySection.questions[2];
  const timing = availabilitySection.questions[3];
  const selectedHours = answers.selections.hours?.[0];
  return `<div class="field-stack">${teachingCard(availabilitySection)}
    <fieldset class="reflection-fields">
      <label><span>${escapeHtml(availabilitySection.questions[0].prompt)}</span><textarea data-text="service-priority" placeholder="Reflect briefly…">${escapeHtml(answers.text["service-priority"] || "")}</textarea></label>
      <label><span>${escapeHtml(availabilitySection.questions[1].prompt)}</span><textarea data-text="season-time" placeholder="Describe what is realistic…">${escapeHtml(answers.text["season-time"] || "")}</textarea></label>
    </fieldset>
    <fieldset class="single-field"><legend>${escapeHtml(hours.prompt)}</legend><div>${hours.options.map((option) => {
      const selected = selectedHours === option.id;
      return `<button type="button" data-action="single" data-question="hours" data-value="${option.id}" class="${selected ? "selected" : ""}" aria-pressed="${selected}"><span class="radio-dot"></span>${escapeHtml(option.label)}</button>`;
    }).join("")}</div></fieldset>
    ${multiSelect({ label: timing.prompt, options: timing.options, questionId: "timing" })}
  </div>`;
}

function startCard() {
  return `<article class="start-card"><p class="eyebrow">Source page 23</p><h1>5 Ways to S.T.A.R.T. to Deepen Your S.H.A.P.E.</h1><div>${startWays.map((way) => `<section><span>${way.letter}</span><div><h2>${escapeHtml(way.title)}</h2><p>${escapeHtml(way.text)}</p>${way.bullets ? `<ul>${way.bullets.map((book) => `<li>${escapeHtml(book)}</li>`).join("")}</ul>` : ""}</div></section>`).join("")}</div><a href="https://fellowshipdubai.com/" target="_blank" rel="noreferrer">Visit fellowshipdubai.com for next steps and resources.</a></article>`;
}

function profileList(label, values) {
  return `<div class="profile-list"><h3>${escapeHtml(label)}</h3>${values.length ? `<ul>${values.map((value) => `<li>${escapeHtml(value)}</li>`).join("")}</ul>` : "<p>None selected</p>"}</div>`;
}

function giftGroup(label, values, muted = false) {
  return `<div class="gift-group ${muted ? "muted" : ""}"><h3>${escapeHtml(label)}</h3><div>${values.length ? values.map((value) => `<span>${escapeHtml(value)}</span>`).join("") : "<p>None selected</p>"}</div></div>`;
}

function profileSection(letter, title, body) {
  return `<article class="profile-section"><header><span>${letter}</span><h2>${escapeHtml(title)}</h2></header><div class="profile-section-body">${body}</div></article>`;
}

function ministryTable() {
  const rows = ministryGiftTable.map((row) => `<tr><th scope="row">${escapeHtml(row.ministry)}</th><td>${escapeHtml(row.gifts.join(", "))}</td></tr>`).join("");
  const mobile = ministryGiftTable.map((row) => `<section><h3>${escapeHtml(row.ministry)}</h3><p>${escapeHtml(row.gifts.join(", "))}</p></section>`).join("");
  return `<article class="ministry-table-section"><header class="ministry-table-heading"><div class="ministry-table-icon">${icon("compass", 26)}</div><div><p class="eyebrow">How to start serving</p><h2>Explore ministries where your gifts may contribute.</h2><p>This table is a reflective guide, not a prescription. Prayerfully consider where your gifts may align, while giving priority to personal conviction and the Holy Spirit’s leading.</p></div></header><div class="ministry-table-desktop"><table><caption class="sr-only">Fellowship Dubai ministry and spiritual gift guide</caption><thead><tr><th scope="col">Ministry</th><th scope="col">Spiritual gifts that strengthen it</th></tr></thead><tbody>${rows}</tbody></table></div><div class="ministry-table-mobile">${mobile}</div><div class="ministry-table-action"><div><strong>Ready to take a next step?</strong><p>View the current opportunities and tell Fellowship Dubai where you would like to serve.</p></div><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer">View Serving Opportunities ${icon("arrowRight")}</a></div></article>`;
}

function profilePage() {
  const profile = buildProfile(answers);
  const text = `${profileToText(answers, profile)}\n\nCURRENT SERVING OPPORTUNITIES\n${SERVING_FORM}`;
  const mailto = `mailto:?subject=${encodeURIComponent(`${answers.profile.name || "My"} S.H.A.P.E. Profile`)}&body=${encodeURIComponent(text)}`;
  return `<section class="profile-page"><header class="profile-hero"><p class="eyebrow">Fellowship Dubai · Complete profile</p><h1>${answers.profile.name ? `${escapeHtml(answers.profile.name)}’s` : "My"} S.H.A.P.E. Profile</h1><p>A clear starting point for prayer, reflection, and exploring current serving opportunities.</p><div class="profile-contact"><span>${escapeHtml(answers.profile.email || "Email not provided")}</span><span>${escapeHtml(answers.profile.phone || "Phone not provided")}</span></div><div class="profile-actions no-print"><button type="button" data-action="copy">${icon("clipboard", 17)}${copied ? "Copied" : "Copy My Profile"}</button><button type="button" data-action="print">${icon("printer", 17)}Download / Print PDF</button><a href="${escapeHtml(mailto)}">${icon("mail", 17)}Email / Share My Profile</a><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer">Begin Serving ${icon("external", 16)}</a></div></header>
    ${profileSection("S", "Spiritual Gifts", giftGroup("Likely gifts", profile.spiritualGifts.likely) + giftGroup("Possible gifts", profile.spiritualGifts.possible) + giftGroup("Unlikely gifts", profile.spiritualGifts.unlikely, true))}
    ${profileSection("H", "Heart / Passion", profileList("Roles I enjoy", profile.heart.roles) + profileList("People I care about", profile.heart.people) + profileList("Causes I feel led to champion", profile.heart.causes))}
    ${profileSection("A", "Abilities", profileList("Abilities I can use", profile.abilities))}
    ${profileSection("P", "Personality", profileList("My personality pattern", profile.personality))}
    ${profileSection("E", "Experiences", `<div class="profile-experience-grid">${Object.entries(profile.experiences).map(([label, values]) => profileList(label, values)).join("")}</div>`)}
    ${profileSection("+", "Availability", `<dl class="availability-summary"><div><dt>Are you making service a priority?</dt><dd>${escapeHtml(profile.availability.priority)}</dd></div><div><dt>Current season and time</dt><dd>${escapeHtml(profile.availability.season)}</dd></div><div><dt>Time per week</dt><dd>${escapeHtml(profile.availability.hours)}</dd></div><div><dt>Best times</dt><dd>${escapeHtml(profile.availability.timing.join(", ") || "Not specified")}</dd></div></dl>`)}
    ${ministryTable()}<div class="profile-footer no-print"><button class="back-button" type="button" data-action="restart">${icon("refresh", 17)}Start a new profile</button></div></section>`;
}

function stage() {
  const id = journeySteps[step].id;
  if (id === "welcome") return welcome();
  if (id === "contents") return contents();
  if (id === "what-is-shape") return teachingCard(whatIsShapeSection);
  if (id === "gifts-teaching") return teachingCard(spiritualGiftsSection);
  if (id === "gifts-assessment") return giftAssessment();
  if (id === "heart-teaching") return teachingCard(heartSection);
  if (id === "heart-roles") return multiSelect({ label: heartQuestions[0].prompt, help: `${heartQuestions[0].help} Source page 9.`, options: heartQuestions[0].options, questionId: "heart-roles", otherLabel: heartQuestions[0].otherLabel });
  if (id === "heart-people-causes") return `<div class="field-stack">${multiSelect({ label: heartQuestions[1].prompt, help: `${heartQuestions[1].help} Source page 10.`, options: heartQuestions[1].options, questionId: "heart-people", otherLabel: heartQuestions[1].otherLabel })}${multiSelect({ label: heartQuestions[2].prompt, help: heartQuestions[2].help, options: heartQuestions[2].options, questionId: "heart-causes", otherLabel: heartQuestions[2].otherLabel, searchable: true })}</div>`;
  if (id === "abilities-teaching") return teachingCard(abilitiesSection);
  if (id === "abilities-assessment") return `<div class="field-stack">${multiSelect({ label: "Circle the following Abilities that apply to your life!", help: "Select every ability that best describes you. Source pages 12-13.", options: abilities, questionId: "abilities", searchable: true })}${otherInputs([["ability-languages-other", "Other language"], ["ability-hobbies-other", "Other hobby-related ability"], ["ability-technical-other", "Other technical ability"], ["abilities-other", "Other ability"]])}</div>`;
  if (id === "personality-teaching") return teachingCard(personalitySection);
  if (id === "personality-assessment") return personalityAssessment();
  if (id === "experiences-teaching") return teachingCard(experiencesSection);
  if (id === "experiences-personal") return `<div class="field-stack"><div class="pastoral-note">${icon("lock", 20)}<p><strong>Your story belongs to you.</strong> Only share what you are comfortable sharing. These responses remain on this device unless you choose to share your completed profile.</p></div>${experienceQuestions.slice(0, 3).map((question) => multiSelect({ label: question.prompt, help: question.help, options: question.options, questionId: question.id, otherLabel: question.otherLabel, searchable: question.id === "painful-experiences" })).join("")}</div>`;
  if (id === "experiences-work") return `<div class="field-stack">${multiSelect({ label: experienceQuestions[3].prompt, help: "Select all the work areas that apply. Expand each category to choose its complete source sub-list. Source pages 17-19.", options: workExperiences, questionId: "work-experiences", otherLabel: "Other work experience", searchable: true })}${otherInputs(WORK_OTHER_GROUPS.map((groupId) => [`work-other-${groupId}`, `Other ${workExperiences.find((item) => item.id === groupId)?.label || "work"} experience`]))}</div>`;
  if (id === "experiences-ministry") return `<div class="field-stack">${experienceQuestions.slice(4).map((question) => multiSelect({ label: question.prompt, help: `Circle 1 to 3 of your ministry experiences. Source pages ${sourcePage(question.page)}.`, options: question.options, questionId: question.id, otherLabel: question.otherLabel, searchable: true, maxSelections: question.maxSelections })).join("")}</div>`;
  if (id === "availability") return availability();
  if (id === "start") return startCard();
  return profilePage();
}

function validationMessage() {
  const id = journeySteps[step].id;
  if (id === "gifts-assessment" && Object.keys(answers.gifts).length < gifts.length) return "Choose a response for every spiritual gift before continuing.";
  if (id === "personality-assessment" && Object.keys(answers.personality).length < personalityPairs.length) return "Choose one response from each personality pairing before continuing.";
  return "";
}

function render({ focusSearch = "" } = {}) {
  const current = journeySteps[step];
  const isProfile = current.id === "profile";
  const progress = Math.round((step / (journeySteps.length - 1)) * 100);
  const source = current.pages.length ? `page${current.pages.length > 1 ? "s" : ""} ${current.pages.join(", ")}` : "profile";
  const validation = validationMessage();
  root.innerHTML = `<main class="app-shell"><div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
    ${isProfile ? "" : `<header class="progress-header no-print"><div class="journey-progress"><button class="fd-mark" type="button" data-action="home" aria-label="Return to welcome"><img src="assets/fellowship-logo.jpeg" alt="" width="284" height="221"></button><div class="progress-copy"><div class="progress-label"><span>${escapeHtml(current.title)}</span><span>${progress}%</span></div><div class="progress-track"><div class="progress-fill" style="width:${progress}%"></div></div><small>Source ${source} · Step ${step + 1} of ${journeySteps.length}</small></div><span class="purpose-mini">KNOW · GROW · GO</span></div></header>`}
    <div class="journey-wrap ${isProfile ? "profile-wrap" : ""}"><section class="journey-stage animate-in">${stage()}</section>
      ${isProfile ? "" : `<footer class="journey-actions no-print"><button class="back-button" type="button" data-action="back" ${step === 0 ? "disabled" : ""}>${icon("arrowLeft")}Back</button><div>${validation ? `<p class="validation-message">${validation}</p>` : ""}<button class="primary-button" type="button" data-action="next" ${validation ? "disabled" : ""}>${step === journeySteps.length - 2 ? "Build My Profile" : "Continue"}${icon("arrowRight")}</button></div></footer><p class="autosave-note no-print">${icon("check", 14)} Progress is saved privately on this device. <button type="button" data-action="restart">${icon("refresh", 13)}Start over</button></p>`}
    </div></main>`;
  if (focusSearch) {
    const input = root.querySelector(`[data-search="${CSS.escape(focusSearch)}"]`);
    if (input) {
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
    }
  }
}

async function copyProfile() {
  const profile = buildProfile(answers);
  const text = `${profileToText(answers, profile)}\n\nCURRENT SERVING OPPORTUNITIES\n${SERVING_FORM}`;
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.append(textarea);
    textarea.select();
    document.execCommand("copy");
    textarea.remove();
  }
  copied = true;
  render();
  window.setTimeout(() => { copied = false; render(); }, 1600);
}

root.addEventListener("click", (event) => {
  const button = event.target.closest("[data-action]");
  if (!button || button.disabled) return;
  const action = button.dataset.action;
  if (action === "home") step = 0;
  if (action === "back") step = Math.max(0, step - 1);
  if (action === "next" && !validationMessage()) step = Math.min(journeySteps.length - 1, step + 1);
  if (action === "restart") {
    answers = emptyAnswers();
    step = 0;
    Object.keys(searches).forEach((key) => delete searches[key]);
    openGroups.clear();
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* no-op */ }
  }
  if (action === "gift") answers.gifts[button.dataset.gift] = button.dataset.value;
  if (action === "personality") answers.personality[button.dataset.pair] = button.dataset.value;
  if (action === "single") answers.selections[button.dataset.question] = [button.dataset.value];
  if (action === "toggle") {
    const id = button.dataset.question;
    const value = button.dataset.value;
    const selected = answers.selections[id] || [];
    answers.selections[id] = selected.includes(value) ? selected.filter((item) => item !== value) : [...selected, value];
  }
  if (action === "copy") { copyProfile(); return; }
  if (action === "print") { window.print(); return; }
  save();
  render();
  if (["home", "back", "next", "restart"].includes(action)) window.scrollTo({ top: 0, behavior: "smooth" });
});

root.addEventListener("input", (event) => {
  const input = event.target;
  if (input.dataset.profile) answers.profile[input.dataset.profile] = input.value;
  if (input.dataset.text) answers.text[input.dataset.text] = input.value;
  if (input.dataset.search) {
    searches[input.dataset.search] = input.value;
    render({ focusSearch: input.dataset.search });
  }
  save();
});

root.addEventListener("toggle", (event) => {
  const details = event.target.closest("details[data-group]");
  if (!details) return;
  if (details.open) openGroups.add(details.dataset.group);
  else openGroups.delete(details.dataset.group);
}, true);

restore();
render();
