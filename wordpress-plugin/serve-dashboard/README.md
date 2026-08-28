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
- Every stage is set from the drawer, on the team the move applies to. For a
  long time only one of them could be: the drawer's invite button set
  `Contacted` and nothing else, and the screen holding the rest of the controls
  — `admin/views/submission-detail.php` — was required by nothing and rendered
  by no menu entry. A leader could start a conversation and then had nowhere to
  record how it went. That file and its dead `admin_post` handlers have gone;
  the drawer is now the one place a person is worked from.

**Trial serve and Placed name their team**
- Both are decisions about a particular team, so neither can be recorded
  without one. The safeguarding gate is a question that cannot be asked
  otherwise, and `team_id` used to be optional for every status — so the check
  simply did not run when it was left out, and an uncleared person suggested to
  Fellowship Kids could be moved straight to Placed by omitting one parameter.
- Nothing in the UI ever did that. The point of putting the gate in the
  transition code rather than the form is that the form is not the only caller,
  and the claim only holds if it holds for every path in.
- It is the right rule regardless: someone Placed on no team counts in "Serving
  now" while serving nowhere.

**Recording a background check**
- Its own route and its own capability. A leader who can move somebody to
  Placed must not also be able to clear the check that permits it.
- Before this existed nothing reachable could mark a check cleared, so the gate
  on Fellowship Kids and Youth Ministry was not merely strict — it was shut, and
  nobody could ever be placed on either team.

**Erasing a profile**
- A pastor can delete someone from the drawer; the button asks twice, because
  there is no undo and the record includes pastoral history.
- The plugin takes consent, states a retention period and stores religious
  belief, so "please remove my details" has to be answerable without a database
  client. The only path to it was a form on that same unrendered screen.

**Team gaps**
- `target`, `minimum`, and `current` headcount per team. A gap is
  `target − current`; the assessment never held either number, which is why the
  gap tile could not previously render anything real.
- A team with no target set is excluded from the gap panel rather than reported
  as fully staffed.

**Contact details, collected once**

- Name, email and phone are all required, enforced in the journey before it
  starts and again at the endpoint — `required` in the markup is a courtesy to
  the person filling the form in and no obstacle to anything posting directly.
- Phone validation is deliberately forgiving: enough digits to dial, and no
  characters that mean it is not a number. This congregation's numbers come from
  a dozen countries and no single format covers them.
- The journey carries all three through to the consent page and prefills them,
  because asking twice invites a mistyped second copy — and a wrong email looks
  exactly like a right one until nobody can reach the person.
- They are stored in their own columns, never duplicated into `profile_json`.
  One copy, in one place, erased with the record.
- The dashboard drawer shows both, as `mailto:` and `tel:` links. They were in
  the payload and rendered nowhere, so a leader had to leave for the WordPress
  admin screen to find a phone number.

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

**`POST /serve/v1/suggestions`** is the other public endpoint, and is public for
the same reason: the person filling in the assessment is not a WordPress user. It
is safe to be public because it is a pure calculation. It stores nothing, writes
nothing, logs nothing, reads no other person's data, and returns only team names
with the person's own words quoted back. It needs no name, email or phone and is
given none — the browser sends five profile sections and the handler reads only
those. It is bounded at 64KB and rate limited.

Its limiter is a **separate bucket** from submissions. Sharing one was the first
version, and six views of a results page — trivially reached by reloading — spent
the five-submission allowance and then refused the person when they tried to
share their profile. The cheap repeatable request must never be able to lock
somebody out of the important one.

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

### Personality is read, and deliberately not counted

The assessment has collected the four personality couplets since the beginning
and the profile has always displayed them, but nothing interpreted them — while
the presentation lists personality among the five things matching considers.

It is now interpreted, in the one direction the data honestly supports. Each
tendency produces a note about *how* the person is likely to serve, shown once
per person under the suggestions:

> **Be Introverted.** Gains energy from quiet reflection and listens well. Depth
> with a few people may suit better than a busy front-of-house role.

That is the workbook's own position: personality governs the manner in which a
gift is exercised, not which team somebody belongs on, and serving against the
grain of it "creates tension and discomfort … and produces less than the best
results".

It is kept out of `reasons`, out of `dimensions_hit`, and out of strength, for a
reason worth stating plainly. Nothing in the teams table describes what a role
is actually like, so the same four tendencies fire identically for all sixteen
teams and carry no information about any of them. Counting them would have added
a dimension and several reasons to every suggestion at once — satisfying the
corroboration rule above with evidence that says nothing about the team, and
handing out "Strong match" on the strength of a temperament. A test holds that
line, and a mutation that files personality as a reason fails it.

Making personality genuinely team-specific needs per-team role attributes that
do not exist yet.

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

It also carries the one item that is about the tool rather than a person: team
headcounts that placements have overtaken. See below.

## Headcounts that have gone stale get asked about

`current_headcount` is typed in by hand. Until leaders could work the pipeline
nobody could be placed, so it never drifted; now every placement moves it a
little further from the truth, and the gap panel is one of the four things the
deck promises.

Reporting that drift was only half the job. The note on the gap bars is a
*disclosure* — it has to be scrolled to, and it looks the same in week ten as in
week one, so across a pilot it becomes furniture while the number behind it gets
steadily worse. Nobody was ever asked to correct it.

Two places now ask:

- a **Confirm these headcounts** card on the dashboard, hidden unless something
  has actually drifted, linking to the screen where the number is edited
- a section in the **weekly digest**, which can now be the sole reason an email
  goes out — so the subject line has a third form rather than claiming people
  are waiting when they are not

Both are restricted to leaders who hold `serve_manage_teams`. A ministry leader
can place people, and so cause the drift, but cannot edit team capacity; asking
them to confirm a number they may not change would be an instruction to do
nothing. They still see the drift note on the bars.

Only teams with real drift are listed. A figure nobody has touched in months is
fine if nobody has been placed on that team — age alone is not evidence of
error, and nudging on it would train leaders to ignore the nudge. "Never
confirmed" is reported as never, not as zero days ago.

The proper fix is upstream: Planning Center knows the actual roster, and
`docs/planning-center-prepare.md` §7 argues for a read-only headcount as the
first integration slice, after which this nudge becomes unnecessary.

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

## Suggestions now come from every team, not three chosen on gifts

The suggested teams on a profile were whichever three `recommendMinistries()`
picked in the browser at the end of the assessment. That function ranks on
spiritual-gift name overlap and nothing else. `Matching::explain()` — which
reads heart, abilities and experience as well — then decorated that
already-narrowed set.

So the other four dimensions could reorder three teams chosen on gifts, and
could never put forward a team the gift ranking had missed. The team-first view
had the same defect from the other side: its candidate list pre-filtered on gift
overlap, so somebody whose fit was a passion or a past job was invisible from
both directions at once.

`Matching::rank()` now explains **every active team** and orders them on
evidence. A real example from the development data — hospitality and
encouragement, stated passions "Newcomers" and "Young families", speaks Tagalog
and English:

| | Before (assessment's three) | After (ranked) |
|---|---|---|
| 1 | Welcome | GROW - Small Group — 3 dimensions |
| 2 | Fellowship Kids | Newcomers Pathway — 3 dimensions |
| 3 | — | GROW - Young Adults — 3 dimensions |
| … | | Fellowship Kids, GROW - Women Connect, Welcome |

Her stated passion was the word *Newcomers* and Newcomers Pathway was never
suggested to her. That is the whole defect in one row.

### Teams needed a vocabulary before this could mean anything

Ranking every team is useless while the non-gift dimensions cannot fire, and
they almost never could: `explain()` compared a person's own words against the
team's **name**. "Elementary Children" shares no word with "Fellowship Kids", so
heart, abilities and experience were dead weight on nearly every team.

Teams now carry `keywords` — the everyday words their work involves — seeded for
all sixteen and editable under **What it is about** on the Teams screen. Seeded
matters: this is tuning, not data entry, and nobody has to type anything for the
change to work. Unlike a headcount, a team's subject matter does not drift every
time somebody is placed.

Matched on **word boundaries**, not substrings. "men" sits inside "women", so
substring matching made every passion for women count as evidence for the men's
ministry — wrong, and the kind of wrong a leader notices before the software
does.

### Languages are not evidence for a team either

Same reasoning as personality, found the same way. "Speaks Tagalog, English" was
filed as an abilities reason, and it fired identically for all sixteen teams — so
every multilingual person gained the abilities dimension everywhere, whether one
of their actual abilities matched or not. That inflated the dimension count
ranking sorts on and helped satisfy the two-dimension requirement for "Strong
match" with evidence that says nothing about the team.

It surfaced only once the demo people were given real languages and unrelated
teams started climbing their lists — which is the argument for demo data that
looks like a congregation rather than five copies of one person.

Languages stay in front of the leader: they are part of the Abilities section of
the profile, their own column in the people list, and a filter on it. In a
congregation speaking six languages that is worth surfacing, as something read
about a person rather than as a reason for a team.

### What ordering counts, and what it deliberately does not

Distinct SHAPE dimensions, then readable reasons, then gift selectivity.

Reasons and selectivity were the other way round, which quietly undid the point.
Selectivity is a gifts-only measure — overlapping gifts over gifts claimed — so
somebody who marked one gift scored a perfect 1.0 against all five teams sharing
it, and those five filled the list ahead of a team with five separate reasons
drawn from their abilities. Gifts would have gone on deciding the suggestions
while appearing not to.

`strength()` is untouched. "Strong match" still requires two overlapping gifts
and a second dimension corroborating them, so non-gift evidence can lead the
list while topping out at "Possible match". Ordering is about which conversation
to have first; a strength label is a claim about the evidence.

Team need still never enters the ordering, and a test opens a 39-person hole in
an unsupported team to prove the order does not budge.

### Two things this deliberately does not change

**`suggested_teams` still records only what the person was shown.** That column
is the record of what their downloaded profile says, and the placement rows built
from it at submission time are what scope a ministry leader's visibility.
Widening suggestions must not widen who can see whom — so a leader of a team
newly suggested for somebody still cannot open that profile, and a test asserts
it by re-reading the row and counting the placement rows. Suggestions that the
assessment did not make are flagged **not on their profile** in the drawer, so a
leader knows whether the person is expecting the team being discussed.

**The invitation draft still names the assessment's pick.** It is the team on the
person's own profile, which is the coherent thing for a first email to mention.
Rerouting it to the top-ranked team would also start naming safeguarded teams in
first contact, which deserves its own decision rather than arriving as a side
effect of this one.

## The person and the leader now see the same answer

Ranking every team fixed what a *leader* saw and left the *person* where they
were. The assessment computed its own top three in the browser, on spiritual-gift
name overlap alone, and that is what their results page showed and what the
profile they downloaded said. So the two of them could sit down holding different
lists, and a conversation could open with "it said Worship" about a suggestion
the leader could not see.

The results page now asks the server. `POST /serve/v1/suggestions` takes a
finished profile and returns the same ranking, with the same reasons, that the
dashboard shows — from the same `Matching::rank_profile()`. A second ranking
written in JavaScript would have drifted from the first within a release, and the
reasons a leader reads have to be the reasons the person was given.

`Matching::explain()` takes a profile rather than a submission row to make that
possible. It had taken the row for exactly one reason — the multilingual
"Speaks X, Y" line — and removing that (see above) left the parameter vestigial,
which is what turned the ranking into a pure function of answers and teams.

### Display-only, and why that is the whole point

The person is shown a wider list. Nothing about who can see them changes.

`suggested_teams` still records only what the assessment named, because the
placement rows built from it are what decide which leaders may open a profile.
Widening a suggestion list must not widen access to people. The seam holds
without this change having to be careful about it: `sanitize_profile()` is a
whitelist, so a ranking field the browser invents never reaches storage and
cannot be read by anything downstream. A test asserts both — that the displayed
ranking is not stored, and that a leader of the top-ranked team still cannot open
the profile.

Somebody suggested a team whose leader cannot see them is still reachable: the
team-first view widened to any evidence in 1.17.0, so that leader finds them from
their own end.

### Failure is the old behaviour, not an error

The page renders its gift-only list first and replaces it only if the request
answers. A missing endpoint, a failed fetch or an empty response leaves a
correct, if narrower, results page rather than an error where the person's
profile should be. The catch is deliberately silent.

## The dashboard says what to do first, and the stages say where you are

Five changes, all of them about what a leader can read rather than what the
software can do.

### One priority order instead of six equal cards

A leader landed on a metrics row and six cards — People ready, Team gaps,
Confirm headcounts, Quick follow-ups, Settling in, Gifts — at roughly equal
visual weight, on a product whose deck promises they "see the next best action
in one place". Nothing said where to start, and *Quick follow-ups* sat third in
the right-hand rail underneath two cards about capacity, on a dashboard whose
first stated benefit is bringing overdue conversations into view.

Two bands now: **Needs you now** (people nobody has spoken to, and overdue or
due today) and **Context** (gaps, headcounts, settling in, gifts — labelled
"Nothing here is waiting on you today"). The hierarchy is made of headings and
order, not colour or size: it costs nothing, it reads to a screen reader as
document structure, and it cannot fail the way a colour-coded scheme can.

When both lists in the first band are empty it collapses to one quiet line.
Two empty states stacked under the loudest heading on the page would have made
the most prominent thing on the dashboard a pair of holes.

### Stage as a position, not a colour

Seven statuses were drawn as pills told apart by hue and a glyph. A pipeline is
ordered, and a coloured label carries none of that — you had to already know the
vocabulary, which is why a legend had to be added above the list in 1.16.0.

    Contacted  2 of 5      ▬▬ ▬▬ ── ── ──

The number does the work, so it survives colour-blindness, a greyscale print and
a leader's first week; the filled track is a second, redundant encoding rather
than the only one.

**Paused and Declined get no track.** They are real outcomes but they are not
positions, and rendering Paused as "2 of 5" would state something false. They
get a dashed chip instead, which also signals they are a different kind of thing.

The order comes from `Schema::stage_path()` rather than from the ordering of
`status_labels()`. A UI that draws "step 2 of 5" from an array's insertion order
breaks silently the day somebody inserts a stage; a test now fails instead.

### Discover, Connect, Serve — the deck's own words

Slide 3 of the presentation is nothing but those three words, and the dashboard
had never used them. That is a real cost rather than a cosmetic one: leadership
approved a pilot described in three phases, then opened a tool that talks about
seven statuses, and nothing on screen said the two describe the same thing.

The stage legend is grouped under them now. Discover deliberately holds no
stage — it is the assessment itself, finished before anybody appears in this
dashboard — and saying so is more useful than pretending a status covers it. The
grouping also shows what a flat list of seven could not: that Paused and Declined
are a different kind of thing from the five that are positions.

### The results page stopped hiding evidence

The person's results page ran to **7,354px — over ten screens**. Two things came
out of it.

The suggestion cards showed four reasons each. That was not a summary: the old
code sliced at four and **silently discarded the rest**, so a team supported by
seven reasons showed four and gave no hint the other three existed. Two are shown
now and the remainder folds into a `<details>` — a real disclosure, needing no
JavaScript, losing nothing.

The sixteen-row ministry gift table was the answer to "where might my gifts fit"
*before* the suggestions above it were personalised. It now sits directly beneath
a ranked list built from everything the person told us, competing with it, and
charging 1,132px for the privilege. Folded, with its heading left visible because
it says something the ranking does not — that this is a reflective guide and
conviction comes first.

| | Before | After |
|---|---|---|
| Page height | 7,354px (10.2 screens) | 6,271px (8.7 screens) |
| Ministry guide | 1,132px, 16 rows | 410px, folded |
| Reasons carried | 20, capped at 4 per team | 26, none discarded |
| Reasons shown | 20 | 10, rest one click away |

### The most actionable word survived a narrow screen

Below 768px the row has three tracks and the date column is hidden, so
**"Overdue" disappeared** — leaving only a coral left-border to carry it. A
leader working from a phone between meetings is exactly who needs it. It is
rendered into the row's meta line as well, where there is room.

Two copies, never both visible: `display: none` removes an element from the
accessibility tree as well as the page, so whichever is hidden is also the one a
screen reader skips, and the row is never announced twice.

## The server decides which teams may see a profile

`suggested_teams` is what the placement rows are built from, and those rows
decide which ministry leaders may open somebody's profile. Until now that column
came from `recommendMinistries()` — a function in the visitor's **browser**,
ranking on spiritual-gift name overlap against a hardcoded copy of the team list.

Four problems, all of them structural rather than cosmetic:

- **The browser decided an access-control question.** A hand-crafted submission
  could name whichever teams it liked and get placement rows on them. Bounded —
  you could only ever expose your own answers, and the safeguarding gate is
  enforced at the stage transition rather than from the stored status — but the
  client was choosing who could read a profile.
- **The team list lived twice.** The browser copy said `GROW – Small Group` with
  an en dash; the database said `GROW - Small Group` with a hyphen, and a
  `str_replace` existed in `extract_team_slugs()` for no reason other than to
  bridge them.
- **The copy could not see the Teams screen.** Rename, retire or add a team and
  the browser neither knew nor could ever name the new one.
- **Gift-name overlap is the narrowest signal available**, and it was deciding
  access while a five-dimension ranking sat unused on the server.

`Matching::slugs_for_profile()` now ranks the answers server-side, against the
teams as they actually are, and `recommendMinistries()` and its table are gone
from the submission path. A profile the ranking finds no evidence for gets an
empty list and no placement rows: a pastor sees everyone, and the dashboard puts
people nobody has spoken to first.

### One number, so nothing can disagree

The suggestion limit dropped from five to three, and it is the same three
everywhere: shown to the person, stored, and placed. Five stored would have
widened access for no reason; five shown against three stored would have made the
*not on their profile* flag lie about teams the person had seen.

### Why the flag survived

It was going to be deleted, on the reasoning that everything would now be "on
their profile". That was wrong, and the data says so — for every profile
submitted before this change, the stored teams are the old gift-only picks, so
the current ranking legitimately surfaces teams the person never saw:

    Aiza Ramos   stored: welcome, fellowship-kids
      Welcome              on their profile
      GROW - Small Group   [not on their profile]
      Newcomers Pathway    [not on their profile]
      Fellowship Kids      on their profile

It also keeps working after this change, as a drift indicator: edit a team's
vocabulary and a profile's stored teams may no longer be what the ranking would
choose today. Normally it is empty, and when it is not, it is telling a leader
something true.

### What the person consented to

Worth recording, because it bounds the question. Ministry leaders never see the
Experiences section — `Submissions::profile()` strips it without
`serve_view_sensitive`, which only pastors hold. And the consent text says the
person is sharing their answers "with the Fellowship Dubai SERVE team so a
ministry leader can contact me about serving… only authorised ministry leaders
will see it". Which teams see them is data minimisation, not a consent boundary.

The deck's Prepare phase still lists "agree on who can see personal information"
as an open item. This makes the current answer better-evidenced. It does not make
it decided.

### What is still duplicated

`ministryGiftTable.js` remains, because the printed ministry guide on the results
page is a teaching aid drawn from it. It no longer decides anything, so the en
dash is now only ever displayed and never slugified — but it is still a second
copy of the team list, and a church that renames a team will see the old name in
that table.

## Reading it without being trained on it

Three things assumed knowledge the person using it did not have.

**The columns had no titles.** Four columns of data with nothing above them: the
date could as easily have been when somebody applied as when their next
conversation is due, and the badge on the right had no name at all. Every list
now carries titles — *Name and gifts*, *Suggested teams*, *Next step due*,
*Stage* — in the same vocabulary the leader guide uses.

They are one grid, not two. The header strip and every row are CSS subgrids of
the list, because a header with its own grid and the same declaration still
drifts: `auto` and `fr` tracks resolve against each grid's own contents, so the
empty cell over the avatar collapsed to nothing and *Stage* measured narrower
than the badge it labels. Titles that do not line up are worse than none.

Fixing this surfaced a layout defect. The track count has to equal the number of
*visible* cells, and from 768px to 1279px there were four tracks and five cells
on show — so the status badge dropped onto a second line at every common laptop
width. Columns now appear one tier at a time, each paired with the track it
needs, and the date outlives the suggested team when only one can fit.

**The stage names are jargon.** *Submitted*, *Trial serve* and *Contacted* are
guessable and guessable wrongly. A collapsed *What do these stages mean?*
explains all seven in a sentence each, rendered from `Schema::status_descriptions()`
beside the labels so the two cannot drift; a test fails if a stage gains a label
without a description.

**Every button in the app had lost its edges.** Found while checking the above,
and the same defect twice over. `.serve-app button` and `.serve-app ul` are
resets for the bare elements this app uses as rows and lists, and both score
0,1,1 — one class and one type — which outranks the 0,1,0 of `.serve-btn` and
any list class. So `background: none; border: 0` won on every Send, Save and
Confirm button in the dashboard, and `list-style: none; padding: 0` won on every
prose list. The buttons still read correctly and still worked, which is exactly
why it survived: a labelled control with no outline looks like a design choice
rather than a button that has lost its chrome. The resets now exclude the
classes that bring their own.

**Reporting a problem was a form in the way.** "Something not working?" was a
card wedged under the dashboard's right-hand column — a form nobody was looking
for, occupying space in front of the lists everybody was. It is now its own
**Contact support** view, reachable from the sidebar on every screen, which
keeps it as close to hand while giving it room to say plainly what happens to a
report. Including the part that is easy to overstate: the audit trail records
that you left a report, and not a word of what it said.

## Deliberately not included yet

- **Planning Center data integration.** Field ownership, sync direction and
  frequency, conflict handling, permissions, API limits and the join identifiers
  all have to be agreed first — that is the deck's own "Prepare" phase. The
  boundary class marks where it will go.
- **The ministry guide table.** `ministryGiftTable.js` still holds a second
  copy of the team list, because the printed guide on the results page is
  drawn from it. It no longer decides anything — suggestions and placements
  are ranked on the server as of 1.21.0 — but a renamed team will still show
  its old name there. Serving that table from the Teams screen is the
  remaining piece.
- **Team-specific personality fit.** Personality is interpreted per person (see
  above) but cannot be team-specific: `keywords` describes what a team's work is
  about, not what temperament the role suits, and those are different questions.
  Answering the second needs a further per-team field.
- **Bulk actions.** Every pipeline move is one row at a time. The skill flags
  this (low severity) and it is the obvious next affordance at real volume.
- **Translation of the workbook content.** The plugin's PHP is
  translation-ready, but the assessment's questions are hardcoded English inside
  JavaScript. Given a congregation whose first languages include Tagalog, Urdu,
  Arabic, Malayalam, Hindi and French, this is worth planning properly rather
  than bolting on.
- **A separate mobile surface.** One responsive app, not two builds.
