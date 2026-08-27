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
- [`docs/content-audit.md`](docs/content-audit.md) — workbook fidelity, page by page.
