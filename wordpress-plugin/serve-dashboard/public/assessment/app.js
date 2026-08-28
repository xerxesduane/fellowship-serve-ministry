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
// The SERVE dashboard reads the computed profile from here, so a leader can be
// sent the finished result rather than the raw answers.
const PROFILE_KEY = `${STORAGE_KEY}-profile`;
const SERVING_FORM = "https://fellowshipdubai.churchcenter.com/people/forms/268058";

/*
 * Optional config supplied by the SERVE Dashboard plugin. Absent it, the
 * journey behaves exactly as the standalone app did: no share step is offered.
 */
const SERVE_CONFIG = (() => {
  try {
    const el = document.getElementById("serve-assessment-config");
    return el ? JSON.parse(el.textContent) : {};
  } catch {
    return {};
  }
})();
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

/*
 * Carry on elsewhere.
 *
 * The journey autosaves to this device, which is no help to someone who starts
 * on a phone at church and wants to finish on a laptop. Sending a link means
 * the answers must leave the device, so it is opt-in, separately worded from
 * the sharing consent, and never shown to a leader.
 */
function resumePanel() {
  if (!SERVE_CONFIG.draftUrl) {
    return "";
  }

  return `<section class="resume-panel">
    <h2>${icon("device", 16)} Continue on another device</h2>
    <p>We can email you a private link so you can pick up where you left off.</p>
    <input type="email" data-resume="email" placeholder="you@example.com" autocomplete="email" aria-label="Email address for your resume link">
    <label><input type="checkbox" data-resume="consent"><span>${escapeHtml(SERVE_CONFIG.draftConsent || "")}</span></label>
    <button type="button" data-action="save-place">${icon("mail", 16)}Email me a link</button>
    <p class="resume-status" role="status" aria-live="polite" data-resume="status"></p>
  </section>`;
}

/** POST the current answers as a draft. */
function savePlace() {
  const email = root.querySelector('[data-resume="email"]');
  const consent = root.querySelector('[data-resume="consent"]');
  const status = root.querySelector('[data-resume="status"]');
  const say = (message, bad) => {
    status.textContent = message;
    status.classList.toggle("is-error", Boolean(bad));
  };

  if (!consent.checked) {
    say("Please tick the box so we know you agree.", true);
    consent.focus();
    return;
  }

  if (!email.value.trim()) {
    say("We need an email address to send the link to.", true);
    email.focus();
    return;
  }

  say("Sending…", false);

  fetch(SERVE_CONFIG.draftUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email: email.value.trim(), answers, step, consent: true }),
  })
    .then((response) => response.json().then((body) => ({ ok: response.ok, body })))
    .then((result) => {
      say(result.ok ? result.body.message : (result.body.message || "That did not send."), !result.ok);
    })
    .catch(() => say("That did not send. Please check your connection.", true));
}

/**
 * Restore a draft from an emailed link before the first render.
 *
 * Resolves either way: a broken or expired link must not stop someone starting
 * the journey fresh.
 */
function maybeResume() {
  const token = new URLSearchParams(window.location.search).get("serve_resume");
  if (!token || !SERVE_CONFIG.draftUrl) {
    return Promise.resolve(false);
  }

  const url = new URL(SERVE_CONFIG.draftUrl, window.location.href);
  url.searchParams.set("token", token);

  return fetch(url.toString())
    .then((response) => response.json().then((body) => ({ ok: response.ok, body })))
    .then((result) => {
      if (!result.ok || !result.body.answers) {
        return false;
      }

      answers = { ...emptyAnswers(), ...result.body.answers };
      step = Number.isInteger(result.body.step) ? result.body.step : 0;
      save();

      // Drop the token from the address bar so it is not left in history or
      // copied out of the URL by accident.
      window.history.replaceState({}, "", window.location.pathname);

      return true;
    })
    .catch(() => false);
}

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

/**
 * Tell the server which step was reached.
 *
 * Fire-and-forget and failure-silent: an analytics counter must never be able
 * to interrupt somebody's journey. Sends a bare step number and nothing else.
 */
let lastCounted = -1;
function countStep() {
  if (!SERVE_CONFIG.stepUrl || step === lastCounted) {
    return;
  }

  lastCounted = step;

  try {
    fetch(SERVE_CONFIG.stepUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ step }),
      keepalive: true,
    }).catch(() => {});
  } catch {
    // Nothing to do: the journey matters, the counter does not.
  }
}

function save() {
  countStep();

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
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    device: '<rect x="4" y="3" width="11" height="18" rx="2"/><path d="M17 8h3v13h-8"/>',
  };
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || ""}</svg>`;
}

function teachingCard(section) {
  const scripture = section.scripture ? `
    <blockquote>${icon("book", 19)}<p>“${escapeHtml(section.scripture.text)}”<cite>${escapeHtml(section.scripture.reference)}</cite></p></blockquote>` : "";
  const paragraphs = (section.paragraphs || []).map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`).join("");
  const bullets = section.bullets ? `<ul class="teaching-list">${section.bullets.map((item) => `<li>${icon("check", 17)}<span>${escapeHtml(item)}</span></li>`).join("")}</ul>` : "";
  return `<article class="teaching-card">
    <header class="teaching-header">
      ${section.letter ? `<span class="section-letter">${escapeHtml(section.letter)}</span>` : ""}
      <div><h1>${escapeHtml(section.title)}</h1><p>${escapeHtml(section.eyebrow || "")}</p></div>
    </header>
    ${scripture}<div class="teaching-copy">${paragraphs}</div>${bullets}
  </article>`;
}

function welcome() {
  return `<div class="welcome-layout">
    <div class="welcome-brand">
      <div class="shape-orbit" role="img" aria-label="S.H.A.P.E.: Spiritual Gifts, Heart, Abilities, Personality, and Experiences">${icon("compass", 42)}<span aria-hidden="true">S</span><span aria-hidden="true">H</span><span aria-hidden="true">A</span><span aria-hidden="true">P</span><span aria-hidden="true">E</span></div>
      <img class="welcome-logo" src="${escapeHtml(SERVE_CONFIG.logoUrl || "assets/fellowship-logo.jpeg")}" alt="Fellowship Dubai" width="284" height="221">
      <p>OUR VISION</p><p class="welcome-vision">Know Jesus<br>Grow to be like Jesus<br>Go tell the nations about Jesus</p>
    </div>
    <div>${teachingCard(welcomeSection)}
      <p class="journey-estimate">${icon("clock", 16)}This takes ${escapeHtml(SERVE_CONFIG.estimate || "about 20 minutes")}. Your answers save automatically on this device as you go.</p>
      <fieldset class="profile-fields"><legend>Your contact details</legend><p>We need these so a ministry leader can get back to you about serving. They appear on your completed profile, and are only shared if you choose to share it.</p>
        ${profileInput("name", "Name", "text", "name")}
        ${profileInput("email", "Email", "email", "email")}
        ${profileInput("phone", "Phone", "tel", "tel")}
      </fieldset>
      ${resumePanel()}
    </div>
  </div>`;
}

function profileInput(id, label, type, autocomplete) {
  const error = contactError(id);
  // Only flag a field the person has actually typed in. Opening the page to
  // three red boxes reads as being told off before starting.
  const touched = Boolean((answers.profile[id] || "").trim());
  const invalid = Boolean(error) && touched;

  return `<label><span>${label} <b class="field-required" aria-hidden="true">*</b></span><input type="${type}" data-profile="${id}" value="${escapeHtml(answers.profile[id])}" autocomplete="${autocomplete}" required aria-required="true"${invalid ? ` aria-invalid="true" aria-describedby="err-${id}"` : ""}><small class="field-error" id="err-${id}"${invalid ? "" : " hidden"}>${escapeHtml(error || "")}</small></label>`;
}

function contents() {
  return `<article class="contents-card"><p class="eyebrow">Your journey map</p><h1>Table of Contents</h1><ol>${tableOfContents.map(([title, page]) => `<li><span>${escapeHtml(title)}</span><b>${escapeHtml(page)}</b></li>`).join("")}</ol></article>`;
}

function giftAssessment() {
  return `<div class="gift-assessment"><header class="assessment-heading"><h1>Unwrapping My Gifts</h1><p>For every gift, choose the response that best describes you. Your profile will group all gifts as likely, possible, or unlikely.</p></header><div class="gift-list">${gifts.map((gift) => `
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
  return `<div><header class="assessment-heading"><h1>Plugging In My Personality</h1><p>Instructions: Select one or the other. There is no right or wrong temperament.</p></header><div class="personality-list">${personalityPairs.map((pair) => `<fieldset class="personality-pair"><legend>${escapeHtml(pair.prompt)}</legend>${[pair.left, pair.right].map((choice) => {
    const selected = answers.personality[pair.id] === choice.id;
    return `<button type="button" data-action="personality" data-pair="${pair.id}" data-value="${choice.id}" aria-pressed="${selected}" class="${selected ? "selected" : ""}"><span class="radio-dot"></span><strong>${escapeHtml(choice.label)}</strong><small>${escapeHtml(choice.description)}</small></button>`;
  }).join("")}</fieldset>`).join("")}</div></div>`;
}

function availability() {
  const hours = availabilitySection.questions[1];
  const timing = availabilitySection.questions[2];
  const selectedHours = answers.selections.hours?.[0];
  return `<div class="field-stack">${teachingCard(availabilitySection)}
    <fieldset class="reflection-fields">
      <label><span>${escapeHtml(availabilitySection.questions[0].prompt)}</span><textarea data-text="service-priority" placeholder="Reflect briefly…">${escapeHtml(answers.text["service-priority"] || "")}</textarea></label>
    </fieldset>
    <fieldset class="single-field"><legend>${escapeHtml(hours.prompt)}</legend><div>${hours.options.map((option) => {
      const selected = selectedHours === option.id;
      return `<button type="button" data-action="single" data-question="hours" data-value="${option.id}" class="${selected ? "selected" : ""}" aria-pressed="${selected}"><span class="radio-dot"></span>${escapeHtml(option.label)}</button>`;
    }).join("")}</div></fieldset>
    ${multiSelect({ label: timing.prompt, options: timing.options, questionId: "timing" })}
  </div>`;
}

function startCard() {
  return `<article class="start-card"><h1>5 Ways to S.T.A.R.T. to Deepen Your S.H.A.P.E.</h1><div>${startWays.map((way) => `<section><span>${way.letter}</span><div><h2>${escapeHtml(way.title)}</h2><p>${escapeHtml(way.text)}</p>${way.bullets ? `<ul>${way.bullets.map((book) => `<li>${escapeHtml(book)}</li>`).join("")}</ul>` : ""}</div></section>`).join("")}</div></article>`;
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

/*
 * Teams the finished profile points to, as the server ranks them.
 *
 * The list below this used to be the only answer a person got: the top three by
 * spiritual-gift name overlap, worked out in this file. Their leader saw every
 * team ranked across all five S.H.A.P.E. dimensions — so the two of them could
 * open a conversation holding different lists, and the person's was the one
 * printed on the profile they downloaded.
 *
 * Asked of the server rather than reimplemented here. A second ranking written
 * in JavaScript would drift from the first within a release, and the reasons a
 * leader reads have to be the reasons the person was given.
 *
 * Held in module state rather than threaded through render(): the results page
 * renders synchronously and this arrives later.
 */
let serverSuggestions = null;
let suggestionsAsked = false;

function requestSuggestions(profile) {
  if (suggestionsAsked || !SERVE_CONFIG.suggestUrl) return;
  suggestionsAsked = true;

  /*
   * Only the sections that carry answers. The server ignores anything else, but
   * there is no reason to put a name, an email or a phone number on the wire
   * for a calculation that does not use them.
   */
  const payload = {
    spiritualGifts: profile.spiritualGifts,
    heart: profile.heart,
    abilities: profile.abilities,
    experiences: profile.experiences,
    personality: profile.personality,
  };

  fetch(SERVE_CONFIG.suggestUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ profile: payload }),
  })
    .then((response) => (response.ok ? response.json() : null))
    .then((data) => {
      if (!data || !Array.isArray(data.suggestions) || !data.suggestions.length) return;
      serverSuggestions = data.suggestions;
      render({ animate: false });
    })
    .catch(() => {
      /*
       * Deliberately silent. The gift-only list is already on screen and is a
       * true statement about their gifts; replacing a working results page with
       * an error because a refinement did not load would be the worse outcome.
       */
    });
}

function ministryRecommendations(profile) {
  if (serverSuggestions) {
    const cards = serverSuggestions.map((item, index) => {
      const why = item.reasons.length
        ? `<ul class="ministry-why">${item.reasons.slice(0, 4).map((reason) => `<li>${escapeHtml(reason)}</li>`).join("")}</ul>`
        : "<p>A flexible place to explore your S.H.A.P.E. with a ministry leader.</p>";

      return `<li><span>${index + 1}</span><div><h3>${escapeHtml(item.team)}</h3><p class="ministry-strength">${escapeHtml(item.strengthLabel)}</p>${why}</div></li>`;
    }).join("");

    const caveat = serverSuggestions.find((item) => item.caveat);

    return `<article class="recommendations-section"><header><p class="eyebrow">Personalized starting points</p><h2>Where your S.H.A.P.E. points</h2><p>Weighed across everything you told us — your gifts, what you care about, what you can do, and what you have lived through. Conversation starters, not a final assignment.</p></header>${
      caveat ? `<p class="ministry-caveat">${escapeHtml(caveat.caveat)}</p>` : ""
    }<ol class="ministry-recommendations">${cards}</ol></article>`;
  }

  requestSuggestions(profile);

  const cards = profile.recommendedMinistries.map((item, index) => `<li><span>${index + 1}</span><div><h3>${escapeHtml(item.ministry)}</h3><p>${item.matchedGifts.length ? `Strong alignment with ${escapeHtml(item.matchedGifts.join(", "))}.` : "A flexible place to explore your S.H.A.P.E. with a ministry leader."}</p></div></li>`).join("");
  return `<article class="recommendations-section"><header><p class="eyebrow">Personalized starting points</p><h2>Your top 3 ministry matches</h2><p>These suggestions are based on your likely and possible spiritual gifts. Use them as conversation starters, not a final assignment.</p></header><ol class="ministry-recommendations">${cards}</ol></article>`;
}

function ministryTable() {
  const rows = ministryGiftTable.map((row) => `<tr><th scope="row">${escapeHtml(row.ministry)}</th><td>${escapeHtml(row.gifts.join(", "))}</td></tr>`).join("");
  const mobile = ministryGiftTable.map((row) => `<section><h3>${escapeHtml(row.ministry)}</h3><p>${escapeHtml(row.gifts.join(", "))}</p></section>`).join("");
  return `<article class="ministry-table-section"><header class="ministry-table-heading"><div class="ministry-table-icon">${icon("compass", 26)}</div><div><p class="eyebrow">Ministry guide</p><h2>Explore more places where your gifts may contribute.</h2><p>This table is a reflective guide, not a prescription. Prayerfully consider where your gifts may align, while giving priority to personal conviction and the Holy Spirit’s leading.</p></div></header><div class="ministry-table-desktop"><table><caption class="sr-only">Fellowship Dubai ministry and spiritual gift guide</caption><thead><tr><th scope="col">Ministry</th><th scope="col">Spiritual gifts that strengthen it</th></tr></thead><tbody>${rows}</tbody></table></div><div class="ministry-table-mobile">${mobile}</div></article>`;
}

/*
 * The share step. Offered first, because sending the profile to the SERVE team
 * is what lets a leader begin the conversation, whereas the Church Center links
 * below ask the person to go and find an opportunity themselves.
 */
function shareStep() {
  if (!SERVE_CONFIG.shareUrl) {
    return "";
  }

  return `<section class="next-step-panel share-panel"><header><p class="eyebrow">Recommended next step</p><h2>Let the SERVE team know you are interested</h2></header><div class="next-step-grid"><a href="${escapeHtml(SERVE_CONFIG.shareUrl)}"><strong>Share my profile with the SERVE team</strong><span>A ministry leader will review your profile and get in touch about where you might serve. You decide what happens next. ${icon("arrowRight", 17)}</span></a></div></section>`;
}

function nextSteps(mailto) {
  return `<article class="results-handoff no-print">${shareStep()}<section class="save-reminder"><div><p class="eyebrow">Before you continue</p><h2>Save your results</h2><p>Serving forms open outside this tool. Save a PDF or email a copy to yourself before you leave so your profile is easy to return to.</p></div><div><button type="button" data-action="print">${icon("printer", 17)}Save / Download PDF</button><a href="${escapeHtml(mailto)}">${icon("mail", 17)}Email My Results</a></div></section><section class="next-step-panel"><header><p class="eyebrow">Ready to take the next step?</p><h2>Choose how you would like to continue</h2></header><div class="next-step-grid"><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer"><strong>Explore Serving Opportunities</strong><span>View current opportunities and tell Fellowship Dubai where you would like to serve. ${icon("arrowRight", 17)}</span></a><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer"><strong>Talk to a S.H.A.P.E. Advisor</strong><span>Open the form and select the SERVE Team to ask for personal guidance. ${icon("arrowRight", 17)}</span></a></div><div class="embedded-form"><div><h3>Serving opportunities form</h3><p>You can complete the form here or open it in a new tab.</p></div><iframe src="${SERVING_FORM}" title="Fellowship Dubai serving opportunities form" loading="lazy"></iframe><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer">Open the serving form in a new tab ${icon("external", 16)}</a></div></section></article>`;
}

function saveProfile(profile) {
  try {
    localStorage.setItem(PROFILE_KEY, JSON.stringify(profile));
  } catch {
    // Same as save(): storage is a convenience, not a requirement.
  }
}

function profilePage() {
  const profile = buildProfile(answers);
  saveProfile(profile);
  const text = `${profileToText(answers, profile, serverSuggestions)}\n\nCURRENT SERVING OPPORTUNITIES\n${SERVING_FORM}`;
  const mailto = `mailto:${encodeURIComponent(answers.profile.email || "")}?subject=${encodeURIComponent(`${answers.profile.name || "My"} S.H.A.P.E. Profile`)}&body=${encodeURIComponent(text)}`;
  return `<section class="profile-page"><header class="profile-hero"><p class="eyebrow">Fellowship Dubai · Complete profile</p><h1>${answers.profile.name ? `${escapeHtml(answers.profile.name)}’s` : "My"} S.H.A.P.E. Profile</h1><p>A clear starting point for prayer, reflection, and exploring current serving opportunities.</p><div class="profile-contact"><span>${escapeHtml(answers.profile.email || "Email not provided")}</span><span>${escapeHtml(answers.profile.phone || "Phone not provided")}</span></div><div class="profile-actions no-print"><button type="button" data-action="copy">${icon("clipboard", 17)}${copied ? "Copied" : "Copy My Profile"}</button><button type="button" data-action="print">${icon("printer", 17)}Download / Print PDF</button><a href="${escapeHtml(mailto)}">${icon("mail", 17)}Email / Share My Profile</a><a href="${SERVING_FORM}" target="_blank" rel="noopener noreferrer">Begin Serving ${icon("external", 16)}</a></div></header>
    ${profileSection("S", "Spiritual Gifts", giftGroup("Likely gifts", profile.spiritualGifts.likely) + giftGroup("Possible gifts", profile.spiritualGifts.possible) + giftGroup("Unlikely gifts", profile.spiritualGifts.unlikely, true))}
    ${profileSection("H", "Heart / Passion", profileList("Roles I enjoy", profile.heart.roles) + profileList("People I care about", profile.heart.people) + profileList("Causes I feel led to champion", profile.heart.causes))}
    ${profileSection("A", "Abilities", profileList("Abilities I can use", profile.abilities))}
    ${profileSection("P", "Personality", profileList("My personality pattern", profile.personality))}
    ${profileSection("E", "Experiences", `<div class="profile-experience-grid">${Object.entries(profile.experiences).map(([label, values]) => profileList(label, values)).join("")}</div>`)}
    ${profileSection("+", "Availability", `<dl class="availability-summary"><div><dt>Are you making service a priority?</dt><dd>${escapeHtml(profile.availability.priority)}</dd></div><div><dt>Time per week</dt><dd>${escapeHtml(profile.availability.hours)}</dd></div><div><dt>Best times</dt><dd>${escapeHtml(profile.availability.timing.join(", ") || "Not specified")}</dd></div></dl>`)}
    ${ministryRecommendations(profile)}${ministryTable()}${nextSteps(mailto)}<div class="profile-footer no-print"><button class="back-button" type="button" data-action="restart">${icon("refresh", 17)}Start a new profile</button></div></section>`;
}

function stage() {
  const id = journeySteps[step].id;
  if (id === "welcome") return welcome();
  if (id === "contents") return contents();
  if (id === "what-is-shape") return teachingCard(whatIsShapeSection);
  if (id === "gifts-teaching") return teachingCard(spiritualGiftsSection);
  if (id === "gifts-assessment") return giftAssessment();
  if (id === "heart-teaching") return teachingCard(heartSection);
  if (id === "heart-roles") return multiSelect({ label: heartQuestions[0].prompt, help: heartQuestions[0].help, options: heartQuestions[0].options, questionId: "heart-roles", otherLabel: heartQuestions[0].otherLabel });
  if (id === "heart-people-causes") return `<div class="field-stack">${multiSelect({ label: heartQuestions[1].prompt, help: heartQuestions[1].help, options: heartQuestions[1].options, questionId: "heart-people", otherLabel: heartQuestions[1].otherLabel })}${multiSelect({ label: heartQuestions[2].prompt, help: heartQuestions[2].help, options: heartQuestions[2].options, questionId: "heart-causes", otherLabel: heartQuestions[2].otherLabel, searchable: true })}</div>`;
  if (id === "abilities-teaching") return teachingCard(abilitiesSection);
  if (id === "abilities-assessment") return `<div class="field-stack">${multiSelect({ label: "Select the following Abilities that apply to your life!", help: "Select every ability that best describes you.", options: abilities, questionId: "abilities", searchable: true })}${otherInputs([["ability-languages-other", "Other language"], ["ability-hobbies-other", "Other hobby-related ability"], ["ability-technical-other", "Other technical ability"], ["abilities-other", "Other ability"]])}</div>`;
  if (id === "personality-teaching") return teachingCard(personalitySection);
  if (id === "personality-assessment") return personalityAssessment();
  if (id === "experiences-teaching") return teachingCard(experiencesSection);
  if (id === "experiences-personal") return `<div class="field-stack"><div class="pastoral-note"><p><strong>Your story belongs to you.</strong> Only share what you are comfortable sharing. Your answers are saved in this browser and may appear in the profile you download, copy, email, print, or share.</p></div>${experienceQuestions.slice(0, 3).map((question) => multiSelect({ label: question.prompt, help: question.help, options: question.options, questionId: question.id, otherLabel: question.otherLabel, searchable: question.id === "painful-experiences" })).join("")}</div>`;
  if (id === "experiences-work") return `<div class="field-stack">${multiSelect({ label: experienceQuestions[3].prompt, help: "Select all the work areas that apply. Expand each category to choose the complete list.", options: workExperiences, questionId: "work-experiences", otherLabel: "Other work experience", searchable: true })}${otherInputs(WORK_OTHER_GROUPS.map((groupId) => [`work-other-${groupId}`, `Other ${workExperiences.find((item) => item.id === groupId)?.label || "work"} experience`]))}</div>`;
  if (id === "experiences-ministry") return `<div class="field-stack">${experienceQuestions.slice(4).map((question) => multiSelect({ label: question.prompt, help: "Select 1 to 3 of your ministry experiences.", options: question.options, questionId: question.id, otherLabel: question.otherLabel, searchable: true, maxSelections: question.maxSelections })).join("")}</div>`;
  if (id === "availability") return availability();
  if (id === "start") return startCard();
  return profilePage();
}

/*
 * Contact details are required before the journey starts.
 *
 * A finished profile that nobody can be reached about cannot become a serving
 * conversation, which is the only thing the journey exists to start. The rules
 * are deliberately forgiving about format: this congregation's numbers come
 * from a dozen countries, so anything with enough digits to dial is accepted.
 */
const CONTACT_FIELDS = ["name", "email", "phone"];

function contactError(id) {
  const value = (answers.profile[id] || "").trim();

  if (!value) {
    return { name: "Please tell us your name.", email: "Please add your email address.", phone: "Please add a phone number." }[id];
  }

  if (id === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
    return "That email address does not look complete — check for a typo.";
  }

  // Digits only after stripping the punctuation people legitimately type.
  if (id === "phone" && (value.replace(/[^\d]/g, "").length < 7 || /[^\d\s+()./-]/.test(value))) {
    return "That phone number does not look complete. Include the country code if you have one.";
  }

  return "";
}

function contactErrors() {
  return CONTACT_FIELDS.map((id) => [id, contactError(id)]).filter(([, error]) => error);
}

function validationMessage() {
  const id = journeySteps[step].id;
  if (id === "welcome") {
    const errors = contactErrors();
    if (errors.length) return errors[0][1];
  }
  if (id === "gifts-assessment" && Object.keys(answers.gifts).length < gifts.length) return "Choose a response for every spiritual gift before continuing.";
  if (id === "personality-assessment" && Object.keys(answers.personality).length < personalityPairs.length) return "Choose one response from each personality pairing before continuing.";
  return "";
}

/*
 * Rebuild the page.
 *
 * `animate` is off for anything that is not a change of step. The entrance
 * animation is right when a new stage arrives and wrong when someone ticks one
 * of eighteen gifts: the whole stage is recreated on every click, so replaying
 * it made answering a single question look like the page had reloaded.
 */
function render({ focusSearch = "", animate = true } = {}) {
  const current = journeySteps[step];
  const isProfile = current.id === "profile";
  const progress = Math.round((step / (journeySteps.length - 1)) * 100);
  const validation = validationMessage();
  root.innerHTML = `<main class="app-shell"><div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
    ${isProfile ? "" : `<header class="progress-header no-print"><div class="journey-progress"><button class="fd-mark" type="button" data-action="home" aria-label="Return to welcome"><img src="${escapeHtml(SERVE_CONFIG.logoUrl || "assets/fellowship-logo.jpeg")}" alt="" width="284" height="221"></button><div class="progress-copy"><div class="progress-label"><span>${escapeHtml(current.title)}</span><span>${progress}%</span></div><div class="progress-track"><div class="progress-fill" style="width:${progress}%"></div></div><small>Step ${step + 1} of ${journeySteps.length}</small></div><span class="purpose-mini">KNOW · GROW · GO</span></div></header>`}
    <div class="journey-wrap ${isProfile ? "profile-wrap" : ""}"><section class="journey-stage${animate ? " animate-in" : ""}">${stage()}</section>
      ${isProfile ? "" : `<footer class="journey-actions no-print"><button class="back-button" type="button" data-action="back" ${step === 0 ? "disabled" : ""}>${icon("arrowLeft")}Back</button><div>${validation ? `<p class="validation-message">${validation}</p>` : ""}<button class="primary-button" type="button" data-action="next" ${validation ? "disabled" : ""}>${step === journeySteps.length - 2 ? "Build My Profile" : "Continue"}${icon("arrowRight")}</button></div></footer><p class="autosave-note no-print">${icon("check", 14)} Progress is saved in this browser. <button type="button" data-action="restart" class="restart-inline">${icon("refresh", 14)}Start over</button></p>`}
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
  const text = `${profileToText(answers, profile, serverSuggestions)}\n\nCURRENT SERVING OPPORTUNITIES\n${SERVING_FORM}`;
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
  if (action === "save-place") { savePlace(); return; }

  /*
   * Answering a question is not the same event as moving to a new step, and
   * should not look like one. render() replaces the whole page, so without
   * this the button you just pressed ceases to exist: the animation replays,
   * the scroll position resets, and keyboard focus is dumped back to the top
   * of the document — which is not merely jarring, it makes the journey very
   * hard to complete without a mouse.
   */
  const navigating = ["home", "back", "next", "restart"].includes(action);
  const anchor = navigating ? "" : selectorFor(button);
  const offset = window.scrollY;

  save();
  render({ animate: navigating });

  if (navigating) {
    window.scrollTo({ top: 0, behavior: "smooth" });
    return;
  }

  // `scroll-behavior: smooth` is set globally, so without this the restore
  // glides back into place — which is its own small lurch. Staying put should
  // look like nothing happened, because nothing did.
  window.scrollTo({ top: offset, behavior: "instant" });

  const again = anchor && root.querySelector(anchor);
  if (again) again.focus({ preventScroll: true });
});

/**
 * A selector that finds the same control again after the page is rebuilt.
 *
 * Built from the data attributes the control already carries, so it needs no
 * extra ids in the markup.
 */
function selectorFor(button) {
  return ["action", "gift", "pair", "question", "value"]
    .filter((key) => undefined !== button.dataset[key])
    .map((key) => `[data-${key}="${CSS.escape(button.dataset[key])}"]`)
    .join("");
}

/*
 * Refresh the Continue gate in place instead of re-rendering.
 *
 * render() rebuilds the whole stage, which would throw the caret out of the
 * field on every keystroke. Only three things can change while someone types a
 * contact detail, so update exactly those three.
 */
function syncContactGate(changed) {
  const message = validationMessage();
  const next = root.querySelector('[data-action="next"]');
  if (next) next.disabled = Boolean(message);

  const holder = next && next.parentElement;
  if (holder) {
    let line = holder.querySelector(".validation-message");
    if (message && !line) {
      line = document.createElement("p");
      line.className = "validation-message";
      holder.insertBefore(line, next);
    }
    if (line) {
      line.textContent = message;
      line.hidden = !message;
    }
  }

  if (!changed) return;

  // Flag the field itself only once there is something in it. Complaining at an
  // empty box the moment it is touched is nagging rather than help; the footer
  // already names what is still missing.
  const error = contactError(changed);
  const show = Boolean(error) && Boolean((answers.profile[changed] || "").trim());
  const field = root.querySelector(`[data-profile="${changed}"]`);
  const note = root.querySelector(`#err-${changed}`);

  if (field) {
    if (show) {
      field.setAttribute("aria-invalid", "true");
      field.setAttribute("aria-describedby", `err-${changed}`);
    } else {
      field.removeAttribute("aria-invalid");
      field.removeAttribute("aria-describedby");
    }
  }
  if (note) {
    note.textContent = show ? error : "";
    note.hidden = !show;
  }
}

root.addEventListener("input", (event) => {
  const input = event.target;
  if (input.dataset.profile) {
    answers.profile[input.dataset.profile] = input.value;
    syncContactGate(input.dataset.profile);
  }
  if (input.dataset.text) answers.text[input.dataset.text] = input.value;
  if (input.dataset.search) {
    searches[input.dataset.search] = input.value;
    // Typing in a search box is not a new stage either.
    render({ focusSearch: input.dataset.search, animate: false });
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

// A resume link wins over whatever is already on this device, because the
// person explicitly asked to come back to that draft.
maybeResume().then(() => render());
