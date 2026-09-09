/**
 * The end of the journey — the point where somebody either becomes visible to
 * the SERVE team or quietly does not.
 *
 * Finishing the assessment used to offer six routes into the church's Church
 * Center form against one into this dashboard, and one of the six was an
 * embedded copy completable in place. Somebody who fills that in has
 * volunteered and no submission row exists, so they appear in no queue, against
 * no team's gap, and in no pilot figure — the pilot counts them as a drop-off.
 *
 * These assert what the functions produce. An earlier attempt asserted that
 * app.js *contained* certain strings, and it passed unchanged against a version
 * that loaded the form for every visitor anyway.
 */

import { test } from "./bootstrap.mjs";
import { resultsHandoff, servingFormStep, shareStep, contactStep } from "../../serve-standalone/public/assessment/handoff.js";

const SHARE = "https://serve.example.test/share-my-profile";
const FORM = "https://example.churchcenter.com/people/forms/12345";

/** Count non-overlapping occurrences. */
const times = (haystack, needle) => haystack.split(needle).length - 1;

test("sharing is offered before the route that leaves no record", (a) => {
  const html = resultsHandoff({ shareUrl: SHARE, servingFormUrl: FORM, mailto: "mailto:a@b.test" });

  const share = html.indexOf("Share my profile with the SERVE team");
  const external = html.indexOf("Browse current serving opportunities");

  a.ok(share !== -1, "the share step is rendered");
  a.ok(external !== -1, "and so is the external form");
  a.ok(share < external, "sharing comes first");
});

test("the page says what the other route costs", (a) => {
  const html = resultsHandoff({ shareUrl: SHARE, servingFormUrl: FORM });

  /*
   * A person choosing the church's form is making a legitimate choice. They
   * should be making it knowingly — the alternative is somebody volunteering
   * and then never being contacted, with no way to find out why.
   */
  a.contains("not to the SERVE team", html, "it names where the form actually goes");
  a.contains("nobody will see the answers you have just given", html, "and what that costs them");
  a.contains("share first", html, "and what to do instead");
});

test("the embedded form is not fetched until somebody asks for it", (a) => {
  const closed = servingFormStep(FORM, false);

  // As an eager iframe this contacted Planning Center for every person who
  // reached their results, whether or not they ever used the form.
  a.lacks("<iframe", closed, "nothing is loaded from a third party on arrival");
  a.contains('data-action="load-form"', closed, "there is a control to ask for it");
  a.contains("Show the form on this page", closed, "which says what it will do");
});

test("asking for it loads exactly the configured form", (a) => {
  const open = servingFormStep(FORM, true);

  a.same(1, times(open, "<iframe"), "one iframe, not two");
  a.contains(`<iframe src="${FORM}"`, open, "pointed at the configured address");
  a.lacks("load-form", open, "and the button is gone once it has loaded");
});

test("the default is closed, so a forgotten argument cannot leak the request", (a) => {
  /*
   * `open` defaulting to true would reintroduce the third-party request for
   * every visitor through nothing worse than a missed argument at a call site.
   */
  a.lacks("<iframe", servingFormStep(FORM), "omitting the flag keeps the form closed");
  a.lacks("<iframe", resultsHandoff({ shareUrl: SHARE, servingFormUrl: FORM }), "and so does the whole handoff");
});

test("no configured form means no external route and nothing said about one", (a) => {
  ["", null, undefined].forEach((missing) => {
    a.same("", servingFormStep(missing, true), "no form, no section — even asked to open");
  });

  const html = resultsHandoff({ shareUrl: SHARE, servingFormUrl: "" });

  a.contains("Share my profile with the SERVE team", html, "sharing is still offered");
  a.lacks("Browse current serving opportunities", html, "but no heading above a dead link");
  a.lacks("churchcenter", html, "and no address is invented");
});

test("no consent page means no share step, and the rest still renders", (a) => {
  const html = resultsHandoff({ shareUrl: "", servingFormUrl: FORM });

  a.lacks("Share my profile with the SERVE team", html, "nothing to link to, so nothing offered");
  a.contains("Save your results", html, "the save reminder survives");
  a.contains("Browse current serving opportunities", html, "and so does the form");
});

test("every route out is still reachable", (a) => {
  /*
   * The fix is the order and the wording, not removal. Taking the church's form
   * away would be a ministry decision, and it is not this one.
   */
  const html = resultsHandoff({ shareUrl: SHARE, servingFormUrl: FORM });

  a.same(3, times(html, `href="${FORM}"`), "both cards and the new-tab link still point at it");
  a.contains('target="_blank" rel="noopener noreferrer"', html, "opened safely");
  a.contains("Talk to a S.H.A.P.E. Advisor", html, "the advisor route is kept");
  a.contains("Explore Serving Opportunities", html, "and the browse route");
});

test("a hostile share address cannot break out of the markup", (a) => {
  const html = shareStep('https://x.test/"><script>alert(1)</script>');

  a.lacks("<script>", html, "the tag is escaped");
  a.contains("&lt;script&gt;", html, "and rendered as text");
});

test("the whole handoff is hidden from a printed profile", (a) => {
  // These are buttons and links. On paper they are noise on a document
  // somebody is keeping.
  a.contains('class="results-handoff no-print"', resultsHandoff({ shareUrl: SHARE }), "marked no-print");
});

/*
 * Questions.
 *
 * Somebody finishes nineteen steps holding a page about their spiritual gifts,
 * their personality and, if they answered, their painful experiences -- and
 * until this the page offered three ways to hand it onward and no way to ask
 * anything.
 */

test("the results page offers a way to ask a question", (a) => {
  const html = contactStep("serve@fellowshipdubai.com");

  a.contains("serve@fellowshipdubai.com", html, "the address is shown");
  a.contains('href="mailto:serve@fellowshipdubai.com"', html, "and is a mailto link");
  a.contains("talk to someone", html, "and says a person will reply");
});

test("no address configured means no panel, rather than an empty one", (a) => {
  a.same("", contactStep(""), "nothing is rendered without an address");
  a.same("", contactStep(), "including when it is not passed at all");
});

test("the address is escaped, because it comes from a setting", (a) => {
  const html = contactStep('a"><script>x</script>@b.test');

  a.lacks("<script>", html, "no markup survives from the setting");
  a.contains("&lt;script&gt;", html, "it is escaped instead");
});

test("the contact panel survives a print, and the action panels do not", (a) => {
  const html = resultsHandoff({
    shareUrl: "https://example.test/share",
    contactEmail: "serve@fellowshipdubai.com",
  });

  /*
   * The three action panels are inside a no-print wrapper; the contact panel
   * is deliberately outside it. Somebody who saves their profile to read later
   * is exactly the person who will have a question later, and an address they
   * can only see on screen is no use to them then.
   */
  const wrapper = html.slice(html.indexOf('class="results-handoff'), html.indexOf("</article>"));

  a.contains("no-print", wrapper, "the actions are marked no-print");
  a.lacks("contact-panel", wrapper, "and the contact panel is not among them");
  a.contains("contact-panel", html, "but it is on the page");
});

test("a journey with no share step still offers a way to ask", (a) => {
  /*
   * The share step disappears when no consent page is configured. The question
   * a participant has does not, so the two are independent.
   */
  const html = resultsHandoff({ shareUrl: "", contactEmail: "serve@fellowshipdubai.com" });

  a.contains("contact-panel", html, "the contact panel is still there");
  a.contains("serve@fellowshipdubai.com", html, "with the address");
});
