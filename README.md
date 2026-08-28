# Fellowship Dubai — S.H.A.P.E. Discovery & SERVE Dashboard

A WordPress plugin containing both halves of the serving journey:

- the public **S.H.A.P.E. Discovery** assessment, a complete interactive
  adaptation of the 24-page workbook, and
- the **SERVE Dashboard**, which gives ministry leaders a scoped, auditable view
  of who is ready for a serving conversation.

**DISCOVER → CONNECT → SERVE.** The assessment helps a person understand how God
has shaped them. The dashboard keeps what happens next visible, so a real
conversation actually takes place.

Built for a dedicated WordPress install at `serve.fellowshipdubai.com`.

## Repository layout

```
wordpress-plugin/serve-dashboard/   the entire product, one deployable plugin
tools/build-release.sh              produces the installable zip
tools/run-tests.php                 the test runner
tests/                              what must never quietly regress
docs/leader-guide.md                for the ministry leaders who use it
docs/deployment-runbook.md          taking it live, in order
docs/planning-center-prepare.md     field ownership, before any integration
docs/privacy-notice.md              what the software actually does with people's data
docs/content-audit.md               page-by-page workbook fidelity audit
```

Everything ships as one plugin: PHP, vanilla JavaScript, and CSS. No build step,
no framework, no npm dependencies.

## History

This repository previously held three implementations of the same
questionnaire — a Next.js/TypeScript app in `src/`, a framework-free PHP port in
`php-version/`, and whatever `vercel.json` happened to be publishing (it pointed
at the PHP directory, so the Next.js app was never the deployed site).

Three copies of the same workbook content is three chances for it to drift. The
target is now WordPress on the church's own hosting, so the Next.js, TypeScript
and Vercel files have been removed and the PHP/JavaScript assessment is the one
surviving implementation, housed inside the plugin. Content parity was verified
before deleting anything: both implementations carried the same 73 option ids,
19 journey steps, 18 spiritual gifts and 4 personality pairings.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+

## Tests

```bash
SERVE_TEST_OK=1 php tools/run-tests.php --wp=/path/to/wordpress
```

Eighty-one tests covering the guarantees whose failure would be silent: the
safeguarding gate, unverified profiles staying invisible, Experiences redaction,
what a CSV may contain, the confirmation-email path in both directions, what the
public intake endpoint accepts, who may move somebody along the pipeline or
erase them, what activation is supposed to have left behind, and whether the
pilot report counts the same population in both halves of a proportion.

That last group exists because activation runs once, on a database nobody has
looked at yet, and then never again — so it is the least-exercised code here and
the only defect this suite has found in anger was hiding in it.

They boot a real WordPress and run against a real database, because every one of
those guarantees is a SQL predicate or a capability check and none of them would
survive being mocked. That means **the runner writes to the database it is
pointed at** — it creates submissions, users and placements, and deletes them
again, then reports if a count moved. It refuses to run against an install
declaring itself production, and otherwise requires `SERVE_TEST_OK=1` so that
pointing it somewhere real has to be deliberate.

Adding Composer and the WordPress PHPUnit scaffold to run eighty-one tests
would have been a bigger change to this repository than anything they check, so
the runner is about a hundred lines and has no dependencies.

The suite was checked by breaking things on purpose. Removing the safeguarding
gate, dropping the unverified filter, writing `verify_sent_at` before the mail,
disabling redaction, emptying the metrics scope clause, deleting an option from
`uninstall.php`, reopening the `team_id` safeguarding bypass, and letting a
ministry leader clear a background check or erase a profile each turn the
relevant tests red; restoring each turns them green. A suite that has only ever
passed has not been shown to test anything.

Two things that exercise found, which is the argument for doing it rather than
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

## Build

```bash
tools/build-release.sh
```

Reads the version from the plugin header, checks it against
`SERVE_DASHBOARD_VERSION` (a bump that touches only one of the two ships a
plugin whose upgrade routine never runs), lints every PHP file, and writes
`dist/serve-dashboard-<version>.zip` with `dev/` removed. It verifies that
exclusion against the finished archive and fails rather than shipping the demo
seeder to a live church site.

Needs PHP on `PATH`, and either `zip` or PowerShell.

## Install

1. Install `dist/serve-dashboard-<version>.zip` through **Plugins → Add New →
   Upload Plugin**, or copy `wordpress-plugin/serve-dashboard/` into
   `wp-content/plugins/` for local development.
2. Activate it. Activation creates the database tables, adds the two roles, seeds
   the 16 ministry teams, schedules the retention sweep, and creates the two
   public pages.
3. Go to **SERVE → Settings and audit** and tick *"Make this the site's front
   page"* so visitors land on the assessment. Activation deliberately does not
   change the front page on its own.
4. On the same screen, set real target and current headcounts under **SERVE →
   Teams and gaps**. Until you do, the team-gap panel stays empty by design
   rather than inventing numbers.

## The two public pages

| Page | Shortcode | Purpose |
|---|---|---|
| Discover your S.H.A.P.E. | `[serve_shape_assessment]` | The 19-step journey. Uses the *SERVE — Full canvas* page template, which bypasses the theme. |
| Share your profile | `[serve_shape_consent]` | The consent step. Reads the completed profile from the browser and, only with consent, sends it to the SERVE team. |

The assessment links to the consent page once someone reaches their completed
profile. If no consent page exists, no share action is offered at all.

## Local development

The plugin needs a WordPress install with MySQL — XAMPP, Laragon, or any LAMP
stack. There is nothing to compile:

```bash
wp plugin activate serve-dashboard
```

To review the dashboard with content in it:

```bash
wp eval-file wp-content/plugins/serve-dashboard/dev/seed-demo.php
```

That seeds five invented profiles and capacity for five teams. Development only —
every name in it is fictional.

## Further reading

- [`wordpress-plugin/serve-dashboard/README.md`](wordpress-plugin/serve-dashboard/README.md)
  — architecture, the data model, accessibility and responsive behaviour, and the
  three places the build deliberately departs from the concept mockups.
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
