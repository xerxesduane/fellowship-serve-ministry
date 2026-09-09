# SERVE — PHP and MySQL

The application, running on PHP 8.1 and MySQL with nothing else. No framework,
no Composer, no build step.

It began as a port of a WordPress plugin, and the evidence that the port did not
change any behaviour is that **it runs that build's test suite, unchanged**: 174
passed, 0 failed, 4 not applicable, 749 assertions. The WordPress build has
since been retired, so those are simply the tests now — but they were written
before the port, which is why they are worth trusting. `tools/run-tests.php`
points at `../tests` rather than keeping a copy here, because two copies drift
and the one that drifts is always the one nobody is watching.

## What this is

```
serve-standalone/
  public/            the only directory that needs to be web-accessible
    index.php        the front controller
    assessment/      the participant's journey (unmodified)
    admin/           the dashboard's CSS and JS (unmodified)
  src/
    Platform/        the runtime WordPress used to supply
    Domain/          the ported application: matching, teams, placements, …
    compat-core.php  escaping, sanitising, time, hooks
    compat-app.php   REST, users, URLs, mail, cron, assets
  views/             the pages
  migrations/        the schema, one file per change
  config/            config.php (not in version control)
  bin/serve          the command line
  tests/             tests for behaviour that only exists here
```

## Getting it running

```bash
mysql -u root -e "CREATE DATABASE serve CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
cp config/config.example.php config/config.php
php bin/serve secret          # paste the output into config.php
$EDITOR config/config.php     # database credentials and the site URL
php bin/serve migrate
php bin/serve user:add --email=you@church.org --name="Your Name" --role=serve_pastor
```

Point a web server's document root at `public/` with everything rewritten to
`index.php`. For Apache that is one `.htaccess`; the file is included.

Then the scheduled work, every fifteen minutes:

```
*/15 * * * * cd /path/to/serve-standalone && php bin/serve cron
```

That last line matters more than it looks. WP-Cron only fired when somebody
visited the site, so the nightly retention sweep and the Monday digest of a
quiet church were precisely the jobs that would not run. Retention is a legal
commitment: if the sweep is not scheduled, nothing is ever deleted and nobody
finds out.

## Running the tests

```bash
SERVE_TEST_OK=1 php tools/run-tests.php
```

It refuses to run without `SERVE_TEST_OK=1`, and refuses again if the database
holds submissions unless `SERVE_TEST_ALLOW_EXISTING=1`, because the fixtures
write and delete rows. Point the config at a test database.

Four tests report as **not applicable** rather than passing or being silently
skipped. Each asserts something about a WordPress `wp_post` — that a page
exists, carries a shortcode, is created as a draft, is not overwritten once
edited. That is machinery for getting content into WordPress, not a promise made
to anyone whose data this holds, and there is no posts table to assert against.
The guarantee underneath them — that the privacy notice states the retention
period actually in force — is covered by `tests/test-privacy-notice.php`, and
more directly than before: it is asserted against freshly rendered output rather
than against whatever was stored in a page months ago.

## What changed, and what did not

The domain is unchanged. Matching, teams, placements, submissions, safeguarding,
the gift crosswalk, verification, invitations, drafts, retention, the audit
trail: all ported as-is, which is why the tests still pass. Same for the
participant's journey and the dashboard's JavaScript — those files are byte-for
byte the plugin's, including the `X-WP-Nonce` header name, kept so a bug in the
front-end is a bug in both builds rather than in this port.

Replaced:

| WordPress | Here |
|---|---|
| `$wpdb` | `Platform\Db` — PDO, prepared statements, same method names |
| options API | `Platform\Options` — one table, JSON values, not `serialize()` |
| users, roles, login | `Platform\Auth` — the only genuinely new code |
| `register_rest_route` | `Platform\Router` — same registration shape, same `(?P<id>\d+)` patterns |
| `dbDelta` | `migrations/*.sql` + `Platform\Migrator` |
| `wp_mail` | `Platform\Mail` — SMTP with STARTTLS, or a log file |
| WP-Cron | `bin/serve cron` from the system scheduler |
| asset queue | `Platform\Assets` |

Two things genuinely improved rather than merely moved:

**Attack surface.** The plugin had a `Hardening` class that switched off XML-RPC,
author archives, the generator tag, application passwords and the `wp/v2/users`
endpoints. None of that is needed now, because none of it exists. What is left of
that class is response headers and a content security policy that can be strict —
`script-src 'self'`, no `unsafe-inline` — because this application controls the
whole page and ships no CDN, analytics or web fonts.

**Reachable files.** Only `public/` needs to be served. `src/`, `config/`,
`migrations/` and `bin/` sit above it and cannot be requested however the server
is configured.

## Known gaps

- **The Teams screen is read-only.** It was a WordPress list table with
  `admin-post.php` handlers. The capacity numbers it edits are reachable through
  the dashboard's inline headcount confirmation and the REST routes, both ported
  and tested; the editing screen itself is not ported yet.
- **Settings and the security checklist** have no screens. The values they read
  and write are in the options table and reachable from `bin/serve`.
- **No data migration from an existing WordPress install.** The schema is
  compatible except for the table prefix, so a copy is a matter of
  `INSERT ... SELECT` from `wp_serve_*` into `serve_*` — plus creating accounts,
  since `wp_users` does not come across.
- **One language.** The 291 `__()` calls are still marked, so adding gettext is a
  change to four functions in `compat-core.php` and nothing else.
