# Deployment runbook — serve.fellowshipdubai.com

Everything needed to take the SERVE Dashboard from a local XAMPP install to a
live subdomain, in the order it should be done.

The ordering is not arbitrary. Mail comes before the plugin because the plugin's
public half does not work without it, and the steps at the end exist because
several of them look like the plugin is broken when they are skipped.

**Time:** roughly half a day of work, plus however long DNS and DKIM take to
propagate. Do not plan to finish this and launch on the same day — deliverability
needs a few days of watching.

---

## 0. Before touching the server

Decide these first. Each one is someone's call, not a technical default.

| Decision | Why it has to be made first |
|---|---|
| Who is the SERVE Pastor account | They are the only role that can clear a background check, delete a profile, or change settings |
| Which ministry leaders get accounts, and which team each one leads | A leader who leads no team sees an empty dashboard — see step 7 |
| Retention period in months | It is written into the consent text every person agrees to, and changing it later does not change what they agreed to |
| Which team runs the pilot | The deck asks for one team, not all sixteen |

You will also need: DNS control for `fellowshipdubai.com`, hosting with the
requirements below, and credentials for a transactional mail service.

---

## 1. Host requirements

- **PHP 8.1+** (the plugin declares this; 8.2 or 8.3 is fine)
- **WordPress 6.4+**
- **MySQL 5.7+ / MariaDB 10.3+**
- **HTTPS**, with a certificate that covers the subdomain
- Shell or WP-CLI access is strongly preferred — several steps below are far
  easier with it

---

## 2. Mail — do this before installing anything

This is first because it is the single point everything else depends on, and
because it is the slowest to get right.

A person completes nineteen steps, agrees to share their profile, and is then
told to check their email. Until they open that link the profile is invisible to
every leader and is deleted after seven days. **If mail does not arrive, the
entire public half of the product silently does nothing.**

1. **Use a transactional mail service** — Postmark, SendGrid, Mailgun, Brevo, or
   Amazon SES. Not the host's `mail()`. Shared-hosting PHP mail from a brand-new
   subdomain is filtered as spam more often than not.
2. **Install and configure an SMTP plugin** for it. The security checklist looks
   for one by name and will keep warning until it finds one.
3. **Set all three DNS records** for the sending domain:
   - **SPF** — authorising the service to send as your domain
   - **DKIM** — the service will give you the record; this is the one most often
     skipped and it matters most
   - **DMARC** — start at `p=none` so you get reports without bouncing anything
4. **Set a real From address** on a domain you control, with a mailbox that
   someone reads. `noreply@` is acceptable; a made-up domain is not.
5. **Wait for propagation, then test deliverability** to a Gmail address, an
   Outlook/Hotmail address, and whatever the congregation actually uses. Check
   the spam folder, not just the inbox.

> The plugin reports honestly when the mailer *refuses* a message — that becomes
> a failing "Unsent confirmations" row, and the messages can be resent from
> Settings once fixed. What it cannot see is a message accepted and then filed as
> spam. That is why this step is done properly rather than quickly.

---

## 3. Database

Create a **dedicated database and database user** for this site.

- One database, one user, rights to that database only
- A real generated password
- Not the same user as any other site on the host

The security checklist checks for this and will fail on `root`, on a user with
access to more than one database, and on an empty password.

---

## 4. WordPress

Install WordPress on the subdomain as normal, then:

- **Settings → Permalinks**: choose Post name. The plugin builds its own URLs
  with `rest_url()` so it works either way, but the documented endpoint
  (`/wp-json/serve/v1/submissions`) only exists with pretty permalinks, and a
  public church site wants readable URLs regardless.
- **Delete the sample page and the Hello World post.**
- **Review and publish the privacy notice.** Activating the plugin replaces
  WordPress' boilerplate draft — which describes comment forms, Gravatar and
  embedded media, none of which this site uses — with a notice describing what
  the software actually does. **It is left as a draft on purpose**; text about
  religious belief goes live when a person decides it should. Have it reviewed
  (see [`docs/privacy-notice.md`](privacy-notice.md) for the same text with the
  three judgement calls marked), then publish it. The share page shows a Privacy
  link only once a policy is published.

  Once anybody edits that page, the plugin never touches it again — so review it
  in WordPress rather than in the file, and your changes are safe from every
  future upgrade.
- **Settings → Discussion**: turn comments off. Nothing here needs them.
- Use a **light, fast theme**. The assessment renders on a full-canvas template
  that bypasses the theme, but the consent page does not.

### wp-config.php

Add these above the `/* That's all, stop editing! */` line:

```php
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );   // see step 9 — set the real cron job too
```

`WP_ENVIRONMENT_TYPE` matters for more than tidiness: the test runner refuses
outright to run against an install that declares itself production, and that
refusal cannot be overridden by a flag.

---

## 5. Install the plugin

Use the built zip, not a folder copy:

```bash
tools/build-release.sh
```

That produces `dist/serve-dashboard-<version>.zip` with `dev/` removed, and
fails rather than shipping the demo seeder. Install it through
**Plugins → Add New → Upload Plugin**, then activate.

Activation creates seven tables, adds the two roles, seeds the sixteen ministry
teams, creates the two public pages, and schedules the retention sweep and the
weekly digest. It deliberately does **not** change your front page.

### What must never reach the server

- **`wp-content/mu-plugins/local-mail-capture.php`** — the local development
  file that intercepts outgoing mail and writes it to a log while reporting
  success. On production it would make every confirmation email vanish while
  every check reported healthy. This is the single most dangerous file in the
  local setup. Confirm `wp-content/mu-plugins/` is empty on the server.
- **`dev/seed-demo.php`** — excluded by the build script. If you copied a folder
  instead, delete it.
- Any local `wp-config.php`, `debug.log` or `mail.log`.

---

## 6. Accounts

Create the pastor and leader accounts now, before configuring teams.

- **SERVE Pastor** — sees every profile, including the Experiences section;
  the only role that can clear a background check, delete a profile, or change
  settings.
- **SERVE Ministry Leader** — sees only profiles suggested to a team they lead,
  with Experiences redacted.

Administrators automatically get everything a pastor can do, because churches
commonly run the pastor as a WordPress administrator.

**Turn on two-factor for every one of these accounts.** The checklist looks for
a 2FA plugin and will keep failing until one is active.

**Hand each of them [`docs/leader-guide.md`](leader-guide.md).** It is the only
document here written for a ministry leader rather than for whoever installs the
site, and it covers the two things that otherwise generate questions on day one:
why their dashboard is empty (no team assigned yet), and why the Experiences
section is hidden from them.

---

## 7. Configure teams — skip this and it looks broken

Go to **SERVE → Teams and gaps** and, for each team in the pilot:

1. **Assign a leader.** This is the step that looks like a bug when missed: a
   ministry leader who leads no team sees an entirely empty dashboard, because
   their scope is empty rather than unrestricted. That is deliberate — an
   unassigned team must not make everyone its leader — but it is invisible until
   you know it.
2. **Set target and current headcount.** Until a team has a target it is left
   out of the gap panel entirely, rather than reported as fully staffed. An empty
   gap panel usually means nobody has set these.
3. **Confirm the safeguarding flag.** Fellowship Kids and Youth Ministry are
   marked by default. Anything else working with under-18s should be marked too.

Note that `current headcount` is a number you maintain by hand — it means people
actually serving on that team, most of whom never completed a SHAPE assessment.
Placing someone through the dashboard does not change it.

---

## 8. Publish the journey

1. **SERVE → Settings and audit** → tick *"Make this the site's front page"* so
   visitors land on the assessment.
2. Confirm both pages exist and render: **Discover your S.H.A.P.E.** and
   **Share your profile**.
3. Set the **retention period**, if it differs from the default. Do this before
   the first real submission — the consent text is stored verbatim per person, so
   changing it later does not change what earlier people agreed to.

---

## 9. Cron

WordPress fires its scheduled jobs on page loads. A new subdomain with almost no
traffic may go days without one, and two of these jobs are commitments rather
than conveniences:

| Job | Runs | Why it matters |
|---|---|---|
| Retention sweep | daily | Deletes profiles past their retention window. This is what the consent text promises. |
| Unverified purge | daily | Removes submissions whose confirmation was sent and never opened. |
| Draft purge | daily | Clears saved half-finished journeys after 30 days. |
| Weekly digest | Mondays | One email per leader; nothing is pushed without it. |

With `DISABLE_WP_CRON` set in step 4, add a real system cron:

```bash
*/15 * * * * cd /path/to/site && wp cron event run --due-now --quiet
```

Or, without WP-CLI, a scheduled `curl` to `https://serve.fellowshipdubai.com/wp-cron.php?doing_wp_cron`.

---

## 10. The go-live gate

**Do not announce the tool until this passes end to end with a real external
address**, not a colleague on the same domain.

1. Open the assessment as a member of the public would. Complete the journey —
   all nineteen steps, with a real name, email and phone.
2. Reach the profile, follow the link to **Share your profile**, tick consent,
   submit.
3. **Confirm the email actually arrives.** Check the spam folder. Time how long
   it takes.
4. Open the confirmation link. You should land on the consent page with a
   thank-you message.
5. Log in as a **ministry leader** and confirm the person now appears in their
   list, with contact details, and that the Experiences section is redacted.
6. Log in as the **pastor** and confirm Experiences are visible.
7. Move the person through a stage or two. On a safeguarded team, confirm the
   background-check gate refuses until a pastor clears it.
8. Delete that test profile from the drawer when finished.

If step 3 fails, stop. Nothing downstream matters until it works.

---

## 11. Security checklist to all-pass

**SERVE → Settings and audit** checks the things plugin code cannot enforce.
Before go-live every row should pass:

- **HTTPS** with HSTS on the subdomain
- **A dedicated database user** with rights to one database
- **`DISALLOW_FILE_EDIT`** in wp-config.php
- **Two-factor** on every pastor and leader account
- **Authenticated SMTP** with SPF, DKIM and DMARC
- **Unsent confirmations** at zero
- **Demo profiles deleted** — if you ever ran the seeder, delete those profiles
- **Error display** off

### Verify the hardening from outside

Run these from any machine. They confirm WordPress is not handing out leader
usernames, which is the first half of a brute-force attempt:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://serve.fellowshipdubai.com/wp-json/wp/v2/users
```

Expect `401`. Also expect `401` from `/wp-json/serve/v1/dashboard`, `/people`
and `/people/1`; a `301` to the home page from `?author=1`; and XML-RPC with
every method stripped.

---

## 12. Rollback

Have this ready before you need it.

- **Take a database dump and a file backup immediately before go-live**, and
  again before any plugin upgrade.
- To back out an upgrade: deactivate, upload the previous zip, reactivate.
  Deactivation drops the two roles, tidies the capabilities it added to the
  administrator, and unschedules the cron jobs — but **leaves every profile
  untouched**. Reactivating restores the roles, and the people who held them get
  them back; team leader assignments and headcounts live in the plugin's own
  tables and are never touched. Deactivating is safe.
- **Deleting the plugin is not reversible.** It drops all seven tables and every
  profile in them, by design. Deactivate instead unless removal is what you mean.

---

## 13. First fortnight

- **Watch "Unsent confirmations" daily.** Any non-zero number means people are
  waiting on emails that never left.
- **Read "How the pilot is going"** in Settings each week. The number that
  matters is *Waiting on a first response* — people who confirmed their address
  and have had nothing happen since. That is the claim the pilot is testing.
- **Watch the completion funnel** in Settings. It shows where people abandon the
  journey. Contact details are required on the first step, which is the highest
  drop-off position in the whole journey — if that number is bad, moving that
  fieldset to the end is a small change.
- **Ask the questions the figures cannot answer.** Settings lists them: whether
  leaders understood the suggested teams, whether the profiles matched the
  people once met, whether the invitation felt personal, and whether moving into
  Planning Center was clear. Four of the deck's five success questions are of
  this kind, and no database produces them.
- **Check the audit trail** in Settings once or twice, to confirm what leaders
  are actually doing matches what you expected.

---

## Still outstanding

Not blockers, but known and worth tracking:

- **Planning Center is not integrated.** The dashboard offers a deep link to a
  people search and nothing more. Field ownership, sync direction and permissions
  all need agreeing first — that is the deck's own Prepare phase, and
  [`docs/planning-center-prepare.md`](planning-center-prepare.md) is the material
  for that conversation.
- **The end of the journey offers two routes.** The Church Center serving form
  appears alongside the share step; anyone who takes the form lands in Planning
  Center invisible to this dashboard.
