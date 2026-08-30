# Running it on your own machine

One command puts a working copy of the whole thing at **http://localhost/serve**
— the assessment, the dashboard, the two public pages, and a mailbox that
catches the confirmation emails.

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/) and
nothing else. No PHP, no MySQL, no XAMPP, no WordPress download.

> This stack is for looking at the software and working on it. It is not a way
> to run a real site, and it holds nothing you would mind losing. To install
> this plugin into a local WordPress that already has real profiles in it, use
> [`tools/deploy-local.ps1`](../tools/deploy-local.ps1) instead — it takes a
> database backup first and stops if the backup did not work.

## Start it

Windows, in PowerShell, from the project folder:

```powershell
.\tools\dev-up.ps1
```

macOS, Linux, or Git Bash:

```bash
tools/dev-up.sh
```

First run pulls about a gigabyte of images and takes a few minutes. After that
it is seconds.

| | |
|---|---|
| Assessment | <http://localhost/serve/> |
| Dashboard | <http://localhost/serve/wp-admin/admin.php?page=serve-dashboard> |
| WordPress admin | <http://localhost/serve/wp-admin/> — `admin` / `admin` |
| Email | <http://localhost:8025> |

Run it again whenever you like. Every step checks whether it has already
happened, so a second run reinstalls nothing and loses nothing.

## With content in it

An empty dashboard shows very little. Add `-Seed` (or `--seed`) to seed five
invented profiles and capacity for five teams:

```powershell
.\tools\dev-up.ps1 -Seed
```

Every name in that seed is fictional. It is the same
`dev/seed-demo.php` the release zip deliberately excludes.

## Editing

`wordpress-plugin/serve-dashboard/` is mounted into the container, not copied
into it. Save a PHP, JavaScript or CSS file and the next page load has it. There
is no build step and nothing to restart.

Two exceptions, because they run once rather than on every request:

- **Activation** — tables, roles, the seeded teams and the two public pages.
  Deactivate and reactivate the plugin in wp-admin to run it again, or start
  clean with `-Reset`.
- **Schema changes** — `Schema::maybe_upgrade()` fires on the first request
  after `SERVE_DASHBOARD_VERSION` changes, so bump the version in the plugin
  header *and* the constant, exactly as a release would.

## Reading the email

Every message the plugin sends is caught by [Mailpit](http://localhost:8025) and
goes nowhere else. That matters more here than it might look: a submission stays
invisible to leaders until the person clicks the link in their confirmation
email, so on an install with no mail the assessment appears to swallow people
whole. Submit the assessment, open Mailpit, click the link, and the profile
appears on the dashboard.

## Running the tests against it

The stack has PHP in it, so both PHP runners work without one installed on your
machine:

```bash
docker compose run --rm -e SERVE_TEST_OK=1 cli php /repo/tools/run-tests.php --wp=/var/www/html/serve
docker compose run --rm cli php /repo/tools/run-unit-tests.php
```

The plugin suite writes to the database it is pointed at, which is the throwaway
one in this stack. If you have seeded demo profiles it will stop, because it
refuses a database that already holds submissions it did not create — add
`-e SERVE_TEST_ALLOW_EXISTING=1` if that demo data is data you are willing to
lose, or `-Reset` first.

The assessment's JavaScript tests need Node, not PHP:

```bash
node tools/run-js-tests.mjs
```

Without Node on your machine, in the project folder:

```bash
docker run --rm -v "$PWD:/repo" -w /repo node:22 node tools/run-js-tests.mjs
```

## Stopping, and starting over

```bash
docker compose stop     # keeps everything; the next dev-up picks up where you left off
docker compose down     # removes the containers, keeps the database
```

```powershell
.\tools\dev-up.ps1 -Reset    # destroys the database and the WordPress install, then rebuilds
```

`-Reset` is scoped to this project's own volumes. It cannot reach another
Docker stack on the same machine.

## When port 80 is already taken

Common on Windows: IIS, Skype, or another local server holds it, and
`dev-up` stops with a message about the port being in use. Create a file called
`.env` beside `docker-compose.yml`:

```
SERVE_PORT=8080
SERVE_URL=http://localhost:8080/serve
SERVE_MAIL_PORT=8026
```

Then `.\tools\dev-up.ps1 -Reset`.

Both of the first two lines, always. WordPress stores absolute URLs in its
database, so the port has to appear in the site address as well as in the
published port — set only one and every link on the site points at the wrong
place. `-Reset` is needed because the address is written at install time.

The site is then at <http://localhost:8080/serve/>. `.env` is already
gitignored.

## What is actually running

| Container | What it is |
|---|---|
| `wordpress` | Apache and PHP 8.2. WordPress is installed in a real `/serve` subdirectory rather than proxied there, so the URLs it generates and the URLs Apache serves cannot disagree. |
| `db` | MariaDB 10.6, matching what CI runs against. |
| `mail` | Mailpit. Accepts every message and delivers none. |
| `cli` | WP-CLI. Not started by `up` — the scripts call it with `docker compose run`. |

Two pieces of glue, both in `docker/`:

- `apache-serve.conf` sets the document root to the parent of the WordPress
  directory, which is what puts the site under `/serve`, and redirects the bare
  host there.
- `htaccess` replaces the one the WordPress image writes for itself, which
  hardcodes `RewriteBase /`. On a subdirectory install that file lets the front
  page load and 404s every other page. WP-CLI cannot correct it, because
  writing `.htaccess` needs an Apache it can detect and the CLI container is not
  one.

And one mu-plugin, `docker/mu-plugins/serve-local-mail.php`, which points
`wp_mail()` at Mailpit and puts a notice in wp-admin saying where the mail went.
It is development scaffolding: it is not part of the plugin, it is not in the
release zip, and nothing in the product knows it exists.

## If something goes wrong

```bash
docker compose logs wordpress    # PHP errors and Apache's log
docker compose logs db
docker compose ps                # what is actually up
```

A white page is almost always a PHP fatal error, and it will be in
`docker compose logs wordpress`. `WP_DEBUG` is on, so it will be on the page
too.
