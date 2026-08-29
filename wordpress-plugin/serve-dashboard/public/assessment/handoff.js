/**
 * What the results page offers once somebody has finished.
 *
 * This used to sit in app.js, which cannot be imported outside a browser, so
 * the one part of the journey that decides whether a person becomes visible to
 * the SERVE team was also the part no test could reach. A source-scanning test
 * was tried and passed against a version that loaded the form on arrival
 * anyway, which is the whole argument for moving it here: these are pure
 * functions of their arguments, and the tests check what they produce rather
 * than what the file contains.
 *
 * The problem being solved is not cosmetic. Finishing the assessment used to
 * offer six routes into the church's Church Center form against one route into
 * this dashboard, and one of the six was an embedded copy somebody could
 * complete in place. A person who does that has volunteered, and no submission
 * row is ever created — so they appear in no queue, against no team's gap, and
 * in no pilot figure. The pilot reports them as a drop-off.
 *
 * Every route is still here. The order changed, the page now says what the
 * other route costs, and the form is fetched when it is asked for.
 */

import { escapeHtml, icon } from "./render.js";

/**
 * Sharing the profile — the step that makes a leader able to begin.
 *
 * Absent a consent page there is nothing to link to, and the journey shows no
 * share action at all, exactly as it behaved before this plugin existed.
 */
export function shareStep(shareUrl) {
  if (!shareUrl) {
    return "";
  }

  return `<section class="next-step-panel share-panel"><header><p class="eyebrow">Recommended next step</p><h2>Let the SERVE team know you are interested</h2></header><div class="next-step-grid"><a href="${escapeHtml(shareUrl)}"><strong>Share my profile with the SERVE team</strong><span>A ministry leader will read everything you have just filled in and get in touch about where you might serve. You decide what happens next. ${icon("arrowRight", 17)}</span></a></div></section>`;
}

/**
 * The church's own serving form, offered second.
 *
 * `open` is false until somebody asks for the embed. As an eager iframe this
 * contacted Planning Center for every person who reached their results, handing
 * over an IP address and accepting cookies from a third party they had not
 * chosen to use. The privacy notice disclosed that accurately and flagged it as
 * a decision rather than a necessity; this is that decision, taken.
 *
 * Returns nothing at all when no form is configured, rather than a heading with
 * a dead link underneath it.
 */
export function servingFormStep(servingFormUrl, open = false) {
  if (!servingFormUrl) {
    return "";
  }

  const url = escapeHtml(servingFormUrl);

  const embed = open
    ? `<iframe src="${url}" title="Serving opportunities form" loading="lazy"></iframe>`
    : `<button type="button" class="load-form" data-action="load-form">${icon("external", 17)}Show the form on this page</button>`;

  return `<section class="next-step-panel"><header><p class="eyebrow">Or, if you would rather look yourself</p><h2>Browse current serving opportunities</h2></header><div class="next-step-grid"><a href="${url}" target="_blank" rel="noopener noreferrer"><strong>Explore Serving Opportunities</strong><span>View current opportunities and tell the church where you would like to serve. ${icon("arrowRight", 17)}</span></a><a href="${url}" target="_blank" rel="noopener noreferrer"><strong>Talk to a S.H.A.P.E. Advisor</strong><span>Open the form and select the SERVE Team to ask for personal guidance. ${icon("arrowRight", 17)}</span></a></div><div class="embedded-form"><div><h3>Serving opportunities form</h3><p class="external-note">${icon("alert", 16)}<span>This form goes to the church&#39;s Planning Center, not to the SERVE team. If you use it without sharing your profile above, nobody will see the answers you have just given &mdash; so share first, then use this as well if you would like to.</span></p></div>${embed}<a href="${url}" target="_blank" rel="noopener noreferrer">Open the serving form in a new tab ${icon("external", 16)}</a></div></section>`;
}

/**
 * The three panels at the end of the journey, in the order they are offered.
 *
 * Sharing first, saving second, the external form last. The order is the fix,
 * so it is asserted rather than left to whoever edits this next.
 */
export function resultsHandoff({ shareUrl = "", servingFormUrl = "", servingFormOpen = false, mailto = "" } = {}) {
  return `<article class="results-handoff no-print">${shareStep(shareUrl)}<section class="save-reminder"><div><p class="eyebrow">Before you continue</p><h2>Save your results</h2><p>Save a PDF or email a copy to yourself before you leave, so your profile is easy to return to.</p></div><div><button type="button" data-action="print">${icon("printer", 17)}Save / Download PDF</button><a href="${escapeHtml(mailto)}">${icon("mail", 17)}Email My Results</a></div></section>${servingFormStep(servingFormUrl, servingFormOpen)}</article>`;
}
