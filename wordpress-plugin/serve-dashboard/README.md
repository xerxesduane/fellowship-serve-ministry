# SERVE Dashboard — WordPress plugin

Gives Fellowship Dubai ministry leaders a scoped, auditable view of who has
completed the S.H.A.P.E. journey, what follow-up is due, and where teams are
short of people.

Built for a dedicated WordPress install at `serve.fellowshipdubai.com`.

## Why this exists

The S.H.A.P.E. assessment is entirely client-side: a completed profile lives in
the visitor's own `localStorage` under `fellowship-dubai-shape-v2` and never
leaves their device. A leader only ever saw a profile if the person copied,
emailed, or printed it themselves.

So the dashboard is not primarily a set of views. It is the missing submission
and storage layer, plus the smallest set of views that make it useful.

## What it adds

**Storage and consent**
- A public REST endpoint (`POST /wp-json/serve/v1/submissions`) that accepts a
  finished profile, but only with explicit consent.
- A consent record per submission, storing the purpose text verbatim and the
  policy version, so a record always reflects what that person actually agreed
  to. IP and user agent are stored salted-hashed, not raw.
- A configurable retention window with a daily sweep that hard-deletes expired
  profiles, their consent rows, and their placements.

**Scoped access**
- Two roles. `SERVE Ministry Leader` sees only submissions suggested to a team
  they lead. `SERVE Pastor` sees everything.
- The Experiences section — which includes painful experiences — is redacted for
  anyone without `serve_view_sensitive`.
- An append-only audit trail covering reads as well as writes.

**Safeguarding**
- Teams can be marked as safeguarded. Fellowship Kids and Youth Ministry are by
  default.
- A submission cannot reach Trial serve or Placed on a safeguarded team without
  a cleared background check. The gate lives in the transition code, not the UI,
  so posting the form directly does not bypass it.

**A pipeline that can actually be worked**
- `Submitted → Contacted → Conversation booked → Trial serve → Placed`, plus
  `Paused` and `Declined`.
- Pausing someone requires a return date. Without that, a follow-up queue fills
  with "travelling until September" and leaders stop opening it.
- Profiles older than 18 months are flagged stale.

**Team gaps**
- `target`, `minimum`, and `current` headcount per team. A gap is
  `target − current`; the assessment never held either number, which is why the
  gap tile could not previously render anything real.
- A team with no target set is excluded from the gap panel rather than reported
  as fully staffed.

**Dubai-specific fields**
- Expected time in the UAE, so a leader does not place someone on a six-month
  contract into a role needing two years of continuity.
- Language filtering on the leader list. The assessment already collects
  languages; nothing could search them before.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+

## Install

1. Copy `serve-dashboard/` into `wp-content/plugins/`.
2. Activate it. Activation creates seven tables, adds the two roles, seeds the 16
   ministry teams from the assessment's own gift table, schedules the retention
   sweep and the weekly digest, and creates the two public pages.
3. Open **SERVE → Teams and gaps** and set real target and current headcounts.
   Until you do, the gap panel stays empty by design.
4. Put `[serve_shape_consent]` on the page that follows the assessment's profile
   step.

## Local testing

```bash
wp plugin activate serve-dashboard
```

The assessment writes its computed profile to
`fellowship-dubai-shape-v2-profile`. The consent form reads that key, so to test
the flow you either complete the journey once in the same browser, or seed the
key from the console.

The automated suite lives one level up, outside the plugin so it never ships:

```bash
SERVE_TEST_OK=1 php ../../tools/run-tests.php --wp=/path/to/wordpress
```

It covers the guarantees on this page whose failure would be silent — the
safeguarding gate, unverified invisibility, Experiences redaction, the export
exclusions, and the confirmation-email path — and it was validated by breaking
each of them on purpose and confirming the right tests went red. See the
repository README for what it writes to the database and the guards around that.

## Tables

All prefixed `{$wpdb->prefix}serve_`:

| Table | Holds |
|---|---|
| `submissions` | One row per shared profile. Full payload in `profile_json`; hot fields broken out for filtering. |
| `consents` | Write-once consent record per submission. |
| `teams` | The 16 ministries, their gift lists, capacity, and safeguarding flag. |
| `placements` | Submission × team, with its own pipeline status. |
| `notes` | Append-only conversation history. Deleted with the profile. |
| `drafts` | Unfinished journeys saved for cross-device resume. No leader query touches this table. |
| `audit` | Append-only. Reads and writes. |

## The dashboard application

`SERVE → Dashboard` is a purpose-built application rather than a WordPress list
table. It runs as a full-bleed admin screen: WordPress still handles login,
capabilities and nonces, and a body class collapses the admin chrome so the app
owns the viewport. An "Exit to WordPress" link is always present.

`Teams and gaps` and `Settings and audit` stay native admin screens on purpose —
they are desktop-only configuration, and the platform's own conventions serve
that better than a bespoke UI.

No framework and no build step. One ES module (`admin/js/app.js`), one
stylesheet, one inline icon set.

### Three places this departs from the concept mockups

1. **No match percentages.** The mockups show "92% match". The brief and the
   deck's own speaker notes both call those figures illustrative, and no scoring
   model exists — the underlying ranking compares spiritual-gift names and
   nothing else. So `Matching` emits a qualitative strength plus **"Why this
   team?"** reasons drawn across gifts, heart, abilities and experience. A leader
   can agree or disagree with a reason; nobody can audit a number that was
   invented.
2. **No "Connected to Planning Center · last sync 2m ago".** Nothing is read
   from or written to Planning Center. `Planning_Center::status()` reports
   `connected: false` and exposes no sync field at all; `push_person()` returns
   a `WP_Error` rather than a pretend success, so anything wired to it
   prematurely fails loudly. The only outbound behaviour is a deep link that
   opens a people search — no credentials involved.
3. **"Invite to serve" is "Invite to a conversation".** It moves the pipeline
   stage and writes an audit row. No messaging workflow has been defined, and a
   button claiming to have emailed someone when it has not is worse than no
   button.

### Team need never drives a recommendation

Suggestions are ranked by how many distinct SHAPE dimensions support them, never
by how badly a team is short of people. Where a team does have openings, that
appears as context carrying its own caveat: *"This is context, not a reason to
place someone."*

## Security model

Two audiences, two very different postures.

### The public journey — open, but verified

Anyone can complete the assessment. That is deliberate: the Newcomers Pathway
team exists to meet people who are not members yet, and a membership wall would
exclude exactly the person it wants to find.

What the assessment must not allow is **impersonation**. Before verification was
added, this succeeded:

```
POST /serve/v1/submissions   (anonymous, plain curl)
  display_name: "Senior Pastor (not really)"
  email:        someone.else@example.org
-> 201, queued for a leader to act on
```

A fabricated profile, attributed to an address the sender did not control,
reaching a leader's follow-up queue. So a submission is now stored immediately —
the person's work is never lost — and stays invisible until the address is
proven:

- One-time token, 48-hour expiry, single use. Only its SHA-256 hash is stored,
  so a leaked database cannot verify anyone's address.
- Unverified rows are excluded from the leader list, from `can_view_submission()`,
  from the person-detail REST route, and from every headline metric. Guessing an
  id does not reveal one.
- Unverified rows are deleted after 7 days by the daily sweep.
- Existing rows were grandfathered on upgrade — see `Schema::migrate()`. Without
  that, adding verification would have silently emptied every leader's queue.

Also on that endpoint: mandatory consent, honeypot, 5 submissions per IP per
hour, and a field-by-field payload whitelist.

### The leader dashboard — authenticated and scoped

Verified from an anonymous client:

```
GET /serve/v1/dashboard   -> 401
GET /serve/v1/people      -> 401
GET /serve/v1/people/1    -> 401
```

Plus capability checks, per-team scoping, Experiences redacted without
`serve_view_sensitive`, and every read audited.

### WordPress defaults closed

`Hardening` fixes what belongs in plugin code. WordPress was handing anonymous
callers the usernames of every leader — the first half of a brute-force attempt:

| | Before | After |
|---|---|---|
| `/wp/v2/users` | 200, leaking usernames | 401 |
| `/wp/v2/users/1` | 200 | 401 |
| `?author=1` | 200 | 301 to home |
| XML-RPC | enabled | disabled |
| `meta generator` | version exposed | removed |
| Login errors | "unknown username" | identical either way |
| Application passwords | available (bypass 2FA) | disabled |

Logged-in users keep their access to `/wp/v2/users`, so the block editor and the
Teams screen still work.

### What code cannot fix

TLS, the database account's privileges, two-factor, mail deliverability and
leftover demo rows are not enforceable from a plugin. Pretending otherwise would
be the dangerous move, so **Settings → Security checklist** checks and reports
them honestly. Before go-live it should read all-pass:

- **HTTPS** with HSTS on the subdomain
- **A dedicated database user** with rights to one database, and a real password
- **`DISALLOW_FILE_EDIT`** in `wp-config.php`
- **Two-factor** on every Pastor and Ministry Leader account
- **Authenticated SMTP** with SPF, DKIM and DMARC — verification emails that
  land in spam mean nothing ever reaches a leader
- **Demo profiles deleted**. `dev/` is excluded by `tools/build-release.sh`,
  which refuses to produce a zip that still contains it — the checklist item is
  now enforced rather than remembered.

### When the confirmation email cannot be sent

The one failure this design is most exposed to is its own mailer. Verification
is what makes an anonymous endpoint safe, so if mail stops working the whole
journey stops working — and it used to stop *silently*:

```
POST /serve/v1/submissions   (SMTP broken)
-> 201 "Almost done — please check your email"
   verify_sent_at recorded as if it had gone
   no email, no log line, no audit row, nothing on any screen
   invisible to leaders; deleted by the purge seven days later
```

Someone gave nineteen steps of honest self-reflection and it was thrown away
without one person knowing it happened. So:

- `verify_sent_at` is written **only after the mailer accepts the message**. A
  row never claims to have emailed someone it did not.
- A failure writes `verification.mail_failed` to the audit trail.
- **Settings → Security checklist** gains an *Unsent confirmations* row. It
  **fails** — not warns — on any non-zero count, and says how many people are
  waiting on a message that does not exist. This is distinct from the
  *Unconfirmed submissions* row below it, which is only ever an inference from a
  large backlog and cannot tell a broken mailer from a quiet week. This one is
  not an inference: `wp_mail()` returned false.
- Those submissions are **held back from the seven-day purge**. Deleting a
  profile because someone "did not confirm" is only defensible if they were
  asked. They remain bounded by the ordinary retention sweep.
- **Send unsent confirmations** on the same screen retries them, issuing a fresh
  token each so the 48-hour clock starts when the email actually does. Manual,
  not scheduled: retrying into a still-broken mailer only burns the sending
  reputation of a domain the church depends on.
- The purge window runs from `verify_sent_at`, not `submitted_at`. Otherwise
  someone whose confirmation was held through a fortnight of broken SMTP would
  be sent their link and have it deleted by the next morning's sweep.

## Accessibility

Built against the `ui-ux-pro-max` guidance and verified in the browser:

- Status is never colour alone — every badge carries a distinct glyph and its label.
- Visible focus ring on every control, including inside the drawer.
- Drawer is a real dialog: `role="dialog"`, `aria-modal`, `aria-labelledby`,
  focus moved in on open, Tab trapped, Escape closes, focus returned to the
  exact opener (falling back to the search box if that element has gone).
- Headings run h1 → h2 → h3 with no skipped levels and a single h1.
- No positive `tabindex`; tab order follows visual order across all columns.
- 44px minimum touch targets, 8px minimum spacing.
- `prefers-reduced-motion` and `prefers-contrast` both honoured.
- Async regions carry `aria-busy` and announce through a polite live region.
- Text contrast ≥4.5:1 throughout (verified with alpha compositing, not just
  declared colours).

## Responsive

Mobile-first. Verified at 320, 375, 768, 1024, 1280 and 1440px with no
horizontal overflow at any width.

| Width | Behaviour |
|---|---|
| < 768 | Mobile bar, off-canvas sidebar, 2×2 metrics, table collapses to rows, drawer is a full-screen sheet |
| 768 | Metrics to one row, suggested-team column appears, drawer becomes a 380px sheet |
| 1024 | Permanent sidebar, two-column content grid |
| 1280 | Remaining people columns appear |
| 1440 | Drawer becomes a third column so it never covers the list being worked from |

## Matching: qualitative, normalised, and honest about weak evidence

The first version emitted a strength label that applied to **80% of all
suggestions** — a verdict that frequent carries almost no information. It also
rewarded claiming more: someone answering agreeably, marking all 18 gifts
"likely", came out with three confident "Strong match" results.

Strength is now normalised against how much the person claimed, because three
matching gifts out of four is strong evidence while the same three out of
eighteen is noise. "Strong" additionally requires corroboration from a second
SHAPE dimension, so gifts alone can never reach it. Measured on the same data:

| | Before | After |
|---|---|---|
| Suggestions labelled "Strong" | 80% | 53–57% |
| 18-of-18 answers | 3 × Strong match | 3 × Possible match, flagged |

Answers that do not discriminate are called out once per person, not once per
team:

> This person marked 18 of the 18 gifts as likely, so their answers do not point
> clearly to one team. Worth exploring together rather than relying on a
> suggestion.

Ranking is by distinct dimensions supported, then by selectivity, then by reason
count. Team need is deliberately absent from the ordering.

## Follow-up ownership and conversation history

Two columns existed that nothing wrote to, leaving two holes: nobody owned a
follow-up (so two leaders on one team could both ring the same person), and
there was nowhere to record what was said.

- **Claim / release.** One holder at a time. A second leader is refused with the
  holder's name; only the holder or a pastor can release.
- **Notes are append-only.** A later leader adds to the history rather than
  overwriting it. Anyone with access can add context — only *ownership* is
  exclusive.
- Notes are deleted with the profile. Pastoral content must not outlive the
  record it describes.
- The note body is never copied into the audit trail, only its length.

## Team-first matching

The inverse workflow: start from a team that needs people rather than from a
profile. Candidates come from those already suggested to the team **plus anyone
whose likely gifts overlap it**, so the list is not limited to whatever the
assessment's own ranking happened to pick — people it missed are marked
`not auto-suggested`.

Ordered by strength of evidence. Team need never enters the ordering, and the
panel says so: *"a team being short is never a reason on its own."*

## Weekly digest

Nothing pushed before, so "fewer people overlooked" depended on the memory the
tool was meant to replace. One email a week, built *as each leader* so team
scoping and redaction apply to the email too.

Recipients are selected **by capability, not role name** — churches commonly set
the pastor up as a WordPress administrator, and filtering on the two custom roles
would have quietly excluded exactly the person most likely to want it.

Names and dates only. No gifts, no notes: email is not a place to put a
S.H.A.P.E. profile.

## Continue on another device

Nineteen steps and `localStorage` only meant someone starting on a phone at
church could not finish on a laptop. Resuming requires the answers to leave the
device, and an unfinished journey is not something the person has agreed to share
with anyone — so a draft is:

- saved only on explicit request, with wording separate from the sharing consent
- held in its own table that **no leader query touches**
- kept 30 days, not the submission retention period
- single-use: deleted the moment it is resumed, and cleared when the finished
  profile is submitted

## Completion funnel

One integer per step. No identifiers, no IP, no session, no per-person path — so
there is nothing to leak and nothing to reconcile against a profile. It answers
"where do people stop" and nothing else. Reported in Settings with drop-off
between steps.

## CSV export

The honest pilot version of the Planning Center handoff: export what you can see,
import it into Planning Center, carry on there. Scoped by the same query the
dashboard uses. UTF-8 BOM so non-Latin names open correctly in Excel.

**Experiences and conversation notes are never exported at any capability level** —
a spreadsheet is the easiest thing in the world to forward to the wrong person.

## Deliberately not included yet

- **Planning Center data integration.** Field ownership, sync direction and
  frequency, conflict handling, permissions, API limits and the join identifiers
  all have to be agreed first — that is the deck's own "Prepare" phase. The
  boundary class marks where it will go.
- **A better candidate pool.** Strength and reasons are now normalised and
  multi-dimensional, and Team matching widens the pool by gift overlap. But the
  *default* suggested teams on a profile still come from the assessment's
  gift-only `recommendMinistries()`. Replacing that ranking is the remaining
  matcher work.
- **Bulk actions.** Every pipeline move is one row at a time. The skill flags
  this (low severity) and it is the obvious next affordance at real volume.
- **Translation of the workbook content.** The plugin's PHP is
  translation-ready, but the assessment's questions are hardcoded English inside
  JavaScript. Given a congregation whose first languages include Tagalog, Urdu,
  Arabic, Malayalam, Hindi and French, this is worth planning properly rather
  than bolting on.
- **A separate mobile surface.** One responsive app, not two builds.
