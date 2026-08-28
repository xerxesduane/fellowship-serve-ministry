# Prepare phase — field ownership and Planning Center touchpoints

> **For discussion, not a specification.** The deck's Prepare phase asks the
> team to *"confirm the profile fields, permissions, and Planning Center
> touchpoints"* and to *"document which system owns each piece of data"* before
> anything is built. This is the first half of that: what the SERVE dashboard
> actually holds today, and where a connection would touch.
>
> The decisions in the right-hand columns are Fellowship Dubai's to make. Every
> one marked **decide** is a real question, not a placeholder.

---

## The short version

Nothing is connected. `Planning_Center::status()` reports `connected: false`,
`push_person()` returns an error rather than pretending to succeed, and no
credentials exist anywhere in the plugin. The only outbound behaviour is a link
that opens a Planning Center people search — a leader follows it, and nothing
is sent.

That is deliberate, and it is why this document exists rather than an
integration.

---

## 1. What the dashboard holds about a person

Every column in `wp_serve_submissions`, with a proposed owner. **Owner** means
the system where a human changes it and which wins in a disagreement.

| Field | What it is | Proposed owner | Notes |
|---|---|---|---|
| `uuid` | Stable id for this profile | SERVE | Never reused; safe to store in PCO as a join key |
| `display_name` | Name as they typed it | **decide** | PCO almost certainly already has them; see §3 |
| `email` | Address, confirmed | **decide** | The join key candidate. See §4 |
| `phone` | Number, required since 1.7.0 | **decide** | Same question as name |
| `tenure_months` | Expected time in the UAE | SERVE | PCO has no equivalent field |
| `status` | Pipeline stage | SERVE | Meaningless outside this journey |
| `gifts_likely` | Spiritual gifts, likely | SERVE | Comes from the assessment; nothing else produces it |
| `languages` | Languages spoken | **decide** | PCO can hold this; two copies will drift |
| `suggested_teams` | Computed suggestions | SERVE | Derived, never authored |
| `profile_json` | The whole S.H.A.P.E. answer set | SERVE | Includes Experiences. See §5 — this one should not travel |
| `safeguarding_status` | Background check outcome | **decide** | Many churches track this in PCO already. See §6 |
| `safeguarding_verified_at` | When it was cleared | **decide** | Follows whatever owns the status |
| `snooze_until` | Paused until | SERVE | Journey state |
| `next_action_at` | Next follow-up or settling-in check | SERVE | Journey state |
| `assigned_user_id` | Which leader claimed follow-up | SERVE | A WordPress user id; no PCO meaning |
| `verified_at`, `verify_token`, `verify_sent_at` | Email confirmation | SERVE | Never leaves |
| `submitted_at`, `updated_at` | Timestamps | SERVE | |

And on `wp_serve_teams`:

| Field | Proposed owner | Notes |
|---|---|---|
| `name`, `slug` | **decide** | PCO Teams may already be authoritative |
| `gifts` | SERVE | Drives matching; no PCO equivalent |
| `target_headcount`, `min_headcount` | SERVE | Planning, not roster |
| `current_headcount` | **decide** | PCO knows who is actually on a team. See §7 |
| `requires_safeguarding` | **decide** | Follows §6 |
| `leader_user_id` | SERVE | A WordPress user, not a PCO person |

---

## 2. Questions that have to be answered before any code

The deck lists these. They are repeated here because each has a wrong answer
that is easy to reach by accident.

1. **Which system owns each field** — the table above.
2. **Direction of travel** — read, write, or both, per field. Both is the
   expensive answer and needs conflict rules.
3. **How often** — on demand when a leader clicks, on a schedule, or on change.
4. **Conflict handling** — if a name differs in both systems, which wins, and
   does anybody get told?
5. **Permissions** — a PCO API token is not scoped the way this plugin's roles
   are. A ministry leader who can see two teams here could, through a naive
   integration, cause reads across the whole PCO database.
6. **The join identifier** — see §4.
7. **API limits** — PCO rate-limits; a sync that walks every profile needs to
   be designed for that from the start.

---

## 3. The thing worth deciding first

**Is a S.H.A.P.E. profile a Planning Center person, or a document about one?**

Everything else follows from the answer.

- *A person.* Then PCO owns identity — name, email, phone — and SERVE holds only
  the assessment and the journey. SERVE stops being a place people are created
  and becomes a place they are described.
- *A document.* Then SERVE keeps its own copy, PCO is a destination a leader
  moves to when they act, and the two are deliberately loosely coupled — which
  is what exists today.

The second is cheaper and is what the deck's own slide 6 describes: *"SERVE adds
the serving lens; Planning Center supports established workflows."* The first is
tidier and considerably more work.

---

## 4. The join identifier

Email is the obvious candidate and the flawed one.

- Households share addresses. Couples and families frequently use one.
- People change them, and the assessment captures the address they used that day.
- The address here is *confirmed* — somebody opened a link — which is more than
  can be said for most PCO records, so matching on it is not symmetrical.

`uuid` is stable and ours, but PCO has nowhere natural to keep it without a
custom field. **Decide** whether a custom field on the PCO person record is
acceptable; if it is, that is the cleanest join and everything else gets easier.

---

## 5. What should never leave

**The Experiences section.** It can contain bereavement, addiction, abuse and
other pastoral history. Inside this system it is redacted from ministry leaders,
excluded from the CSV export at every capability level, and every read of it is
recorded.

None of those protections travel. A PCO note field has different permissions,
different retention, and a different audience. **Recommendation: Experiences are
never synced, in any direction, under any configuration.** If that is agreed, it
should be a hard rule in code rather than a setting somebody can turn on.

The same logic applies to conversation notes.

---

## 6. Background checks

Many churches already run background checks through PCO. If Fellowship Dubai
does, then holding `safeguarding_status` here as well creates two answers to a
question that must only have one — and this plugin *gates placement* on its
answer.

Three options:

1. **PCO owns it, SERVE reads it.** Safest. The gate consults the system that
   actually knows. Requires a read at the moment of placement, and a decision
   about what happens when PCO is unreachable — refusing is the correct failure
   mode.
2. **SERVE owns it.** What happens today. Fine only if nobody records checks
   anywhere else.
3. **Both.** Do not.

---

## 7. Headcount

`current_headcount` is typed in by hand and means everyone serving on a team,
most of whom never completed an assessment. PCO knows the actual roster.

If PCO becomes the owner, the gap panel stops being an estimate, and both of
the workarounds around it become unnecessary: the "placed since this was last
checked" drift indicator added in 1.12.0, and the confirm-the-headcount nudge
added in 1.15.0 because disclosing the drift was not the same as anybody
correcting it. Two rounds of compensating for a hand-typed field is itself an
argument for who should own it. That is probably the single highest-value read in any integration,
and it is read-only, one-directional, and involves no personal data leaving.

**Worth considering as a first slice on its own**, before anything harder.

---

## 8. Where the code would attach

`includes/class-planning-center.php` is the boundary and is currently four
things:

| Method | Today | Under an integration |
|---|---|---|
| `status()` | Reports `connected: false` | Connection state, last successful call |
| `subdomain()` | A setting used only to build links | Unchanged |
| `person_search_url( $email )` | Opens a PCO people search | Becomes a direct link once a join key exists |
| `push_person( $id )` | Returns `WP_Error` | The first write, if there is to be one |

Nothing else in the plugin talks to Planning Center, so an integration has one
seam rather than many. That was the point of writing it this way.

---

## 9. Suggested order

1. **Agree §3.** Everything else is downstream of it.
2. **Read-only headcount (§7).** Useful immediately, no personal data leaves, and
   it exercises credentials, rate limits and error handling on something where
   failure is harmless.
3. **Agree the join key (§4)** and, if a custom field is acceptable, write the
   `uuid` to PCO — still one-directional, still low-risk.
4. **Then and only then** consider anything that writes a person.

Experiences and notes (§5) stay out of all four.
