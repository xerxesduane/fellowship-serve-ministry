# Fellowship Dubai — S.H.A.P.E. Discovery & SERVE Dashboard

A standalone PHP and MySQL application containing both halves of the serving
journey:

- the public **S.H.A.P.E. Discovery** assessment, a complete interactive
  adaptation of the 24-page workbook, and
- the **SERVE Dashboard**, which gives ministry leaders a scoped, auditable view
  of who is ready for a serving conversation.

**DISCOVER → CONNECT → SERVE.** The assessment helps a person understand how God
has shaped them. The dashboard keeps what happens next visible, so a real
conversation actually takes place.

Built for a dedicated install at `serve.fellowshipdubai.com`.

## Repository layout

```
serve-standalone/                      the entire product
serve-standalone/bin/serve             the command line: migrate, users, seed, cron
serve-standalone/src/Domain/           the application itself
serve-standalone/src/Platform/         database, auth, sessions, mail, routing
serve-standalone/src/compat-*.php      the WordPress-shaped functions the domain expects
serve-standalone/migrations/           schema, applied in order
serve-standalone/tools/run-tests.php   the suite runner
tools/run-unit-tests.php               the matching arithmetic, without a database
tools/run-js-tests.mjs                 the assessment test runner
tests/                                 what must never quietly regress
tests/js/                              the same, for the assessment JavaScript
docs/leader-guide.md                   for the ministry leaders who use it
docs/deployment-runbook.md             taking it live, in order
docs/planning-center-prepare.md        field ownership, before any integration
docs/privacy-notice.md                 what the software actually does with people's data
docs/content-audit.md                  page-by-page workbook fidelity audit
docs/ministry-gift-crosswalk.md        which ministry words mean which assessed gift, and which do not
```

PHP, vanilla JavaScript, and CSS. No build step, no framework, no npm
dependencies, and no Composer.

## History

This repository has held three generations of the same questionnaire. First a
Next.js/TypeScript app alongside a framework-free PHP port; then a WordPress
plugin, which is what the assessment and dashboard were originally built as; and
now a standalone PHP/MySQL application with no WordPress at all.

Every move was made with content parity verified before anything was deleted —
the same 73 option ids, 19 journey steps, 18 spiritual gifts and 4 personality
pairings survive from the first implementation to this one.

The WordPress plugin was retired once it was clear nothing would be deployed to
it, which made keeping two builds in step pure cost. That port is why the
compatibility layer exists: `src/compat-core.php` and `src/compat-app.php`
provide the WordPress functions the domain classes were written against, so the
domain code did not have to be rewritten — and therefore did not have to be
re-verified — in order to drop the platform beneath it.

## Requirements

- PHP 8.1+ with `pdo_mysql` and `mbstring`
- MySQL 5.7+ / MariaDB 10.3+

## Setup

```bash
cd serve-standalone
cp config/config.example.php config/config.php
php bin/serve secret          # paste the output into config.php
$EDITOR config/config.php     # database credentials and the site URL
php bin/serve migrate
php bin/serve user:add        # the first account
```

Then set real target and current headcounts under **Teams and gaps**. Until you
do, the team-gap panel stays empty by design rather than inventing numbers.

## Tests

```bash
cd serve-standalone
SERVE_TEST_OK=1 php tools/run-tests.php
```

**Point this at a scratch database, never at one holding real profiles.** It
creates submissions, users and placements and deletes them again. Two guards
stand in the way: it refuses to run without `SERVE_TEST_OK=1`, and it refuses a
database that already holds submissions it did not create.

The second exists because the first only asks whether somebody *declared* the
database safe, and a local install holding a congregation's real answers
declares nothing at all — so it waved it through. A development database is
normally empty of people between runs, because the suite cleans up after itself,
so the check costs a correctly used install nothing.

One hundred and seventy-four tests covering the guarantees whose failure would
be silent: the safeguarding gate, unverified profiles staying invisible,
Experiences redaction, what a CSV may contain, the confirmation-email path in
both directions, what the public intake endpoint accepts, who may move somebody
along the pipeline or erase them, who may manage an account and what they may
grant themselves, and whether the pilot report counts the same population in
both halves of a proportion.

These test files were written against the WordPress build and were carried
across unchanged. That is deliberate, and it is the whole argument for trusting
them: a port "verified" by tests rewritten alongside it proves nothing. Four
tests report as *not applicable* rather than having been deleted — each asserts
something about a WordPress `wp_post`, which is machinery for getting content
into WordPress rather than a promise made to anyone.

`tests/test-routing.php` is the group that matters most and reads least like
matching: that a recommendation creates no placement row and grants no ministry
leader access, that all four result tiers reach central intake, that a leader
cannot move somebody onto a team they do not lead, that a fabricated or
deactivated team id fails before anything changes, that a failed placement write
rolls the submission status back rather than leaving it claiming success, and
that routing somebody by hand to a safeguarded team raises the requirement
instead of meeting a record that says none is needed.

### The matching arithmetic

```bash
php tools/run-unit-tests.php
```

Thirty-seven tests over the canonical taxonomy, the approved ministry-gift
crosswalk and the tier contract. No database and no confirmation flag — these
are pure functions over arrays, and the team rows come from the application's
own seed, parsed out of `Teams::seed()` so the fixture cannot drift into
agreeing with itself.

They have their own runner because they are the part most likely to be wrong in
a way nobody notices, and they were previously reachable only through a runner
that needs MySQL stood up first. The regression that prompted them was a string
comparison silently matching twelve of the ministry table's twenty-two terms —
which no test caught, because the fixtures used a gift name the assessment
cannot emit. `Fixtures::gift_profile()` builds profiles through the real
taxonomy so that particular blindness cannot recur.

### The assessment JavaScript

```bash
node tools/run-js-tests.mjs
```

Fifty-nine tests over the journey's pure modules — `profile.js`, which builds
the object that becomes somebody's stored profile and the text they download;
`handoff.js`, which decides what the results page offers once they have
finished; `suggestions.js`, which holds the results page's request state; and
`render.js`. No database and no network, so the runner touches nothing and needs
no confirmation flag.

It exists because the PHP suite stops at the REST endpoint. Everything on the
visitor's side of that boundary could be broken freely and no test noticed —
including the two things nobody would catch by eye: an "Other" answer attaching
to the wrong question, and the takeaway document quietly omitting a section.
Both are now covered, and both were confirmed by breaking them on purpose.

`app.js` is still not covered. It reads `document` at import time and renders on
load, so importing it outside a browser needs a DOM shim larger than the tests
it would enable. CI parses it, which catches the mistake that actually happens;
the rest is checked by eye.

`suggestions.js` was extracted for the same reason `handoff.js` was. Three
defects lived in two module-level variables in `app.js` and all looked identical
from outside — a page stuck on "Working these out…" forever. A valid empty
response was discarded by a truthiness check, a failed request was swallowed by
a silent catch, and restart cleared everything except those two variables, so
the next person on a shared device inherited the previous person's teams and no
new request was ever made. None of it was reachable by a test until the state
became a value in its own module.

The useful move has been taking things *out* of it. `handoff.js` was extracted
precisely because the code deciding whether a person becomes visible to the
SERVE team was trapped in the one file no test could reach — and a
source-scanning test written in its place passed against a version that behaved
wrongly. Pure functions in their own module are testable; that is most of what
these extractions buy.

### Why the suite is shaped this way

The main suite runs against a real database, because every one of those
guarantees is a SQL predicate or a capability check and none of them would
survive being mocked. That means **the runner writes to the database it is
pointed at** — it creates submissions, users and placements, and deletes them
again, then reports if a count moved.

The runner is about a hundred lines and has no dependencies, which was a
deliberate trade: adding Composer and a full test framework to run these would
have been a bigger change to this repository than anything they check.

The suite was checked by breaking things on purpose. Removing the safeguarding
gate, dropping the unverified filter, writing `verify_sent_at` before the mail,
disabling redaction, emptying the metrics scope clause, reopening the `team_id`
safeguarding bypass, and letting a ministry leader clear a background check,
erase a profile or grant themselves a role each turn the relevant tests red;
restoring each turns them green. A suite that has only ever passed has not been
shown to test anything.

Three things that exercise found, which is the argument for doing it rather than
assuming:

- A test can pass by asserting nothing. The metrics test iterated the wrong
  level of a nested array, so its loop body never ran and it sailed through a
  deliberately broken scope clause. The runner now **fails any test that
  finishes without making an assertion.**
- Several mutations appeared uncaught and had simply never applied — regexes
  that did not match, and one aimed at the deployed copy of a file the test
  reads from the repository. Check the mutation landed before believing what it
  tells you. One of these masked a real result twice.
- The suite has to be repeatable, not merely green once. The intake tests post
  at the throttled public endpoint and cleared the wrong rate-limit bucket
  afterwards, so counters accumulated and the suite began returning 429 after
  enough local runs — passing today, failing on Thursday, and invisible on CI
  because CI gets a fresh database every time. Running it three times in a row
  is now part of checking it.

## The routes

| Route | Purpose |
|---|---|
| `/` | The 19-step S.H.A.P.E. journey. |
| `/share` | The consent step. Reads the completed profile from the browser and, only with consent, sends it to the SERVE team. |
| `/confirm` | The link in the confirmation email. A profile is invisible to every leader until the address is proven. |
| `/privacy` | What the software does with people's answers, generated from the live retention setting. |
| `/login`, `/reset` | For ministry leaders and pastors. Volunteers never need an account. |
| `/dashboard`, `/teams`, `/settings`, `/users` | The SERVE Dashboard. |

## Local development

Anything that can run PHP 8.1 and reach MySQL will do — XAMPP, Laragon, or any
LAMP stack. There is nothing to compile. For a quick look:

```bash
cd serve-standalone
php -S localhost:8321 -t public
```

To review the dashboard with content in it:

```bash
php bin/serve seed:demo
```

That seeds invented profiles and capacity for five teams. Development only —
every name in it is fictional, and they should be removed before real use.

## Further reading

- [`serve-standalone/README.md`](serve-standalone/README.md) — architecture, the
  data model, the compatibility layer, and the setup steps in detail.
- [`docs/deployment-runbook.md`](docs/deployment-runbook.md) — going live on
  `serve.fellowshipdubai.com`: mail first, the setup steps that look like bugs
  when skipped, the go-live gate, and rollback.
- [`docs/leader-guide.md`](docs/leader-guide.md) — the only document written for
  a ministry leader rather than for whoever installs the thing.
- [`docs/planning-center-prepare.md`](docs/planning-center-prepare.md) — what the
  dashboard holds, who should own each field, and the questions the deck's
  Prepare phase asks.
- [`docs/privacy-notice.md`](docs/privacy-notice.md) — a draft privacy notice
  describing the actual behaviour, table by table, for legal review before it is
  published.
- [`docs/content-audit.md`](docs/content-audit.md) — workbook fidelity, page by page.
