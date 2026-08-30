# Ministry–Gift crosswalk

What the Ministry–Gift Table's vocabulary means in terms of the eighteen gifts
the assessment actually measures — and, just as importantly, what it has been
decided *not* to mean.

- **Mapping version:** `2.0.0` (`Gift_Crosswalk::VERSION`)
- **Taxonomy version:** `1.0.0` (`Gift_Taxonomy::VERSION`)
- **Contract version:** `gift-v2` (`Matching_Contract::VERSION`)
- **Corroboration registry version:** `0.0.0` — empty, see below
- **Approved:** 2026-08-30

Change anything in `includes/class-gift-crosswalk.php` and change this file in
the same commit. The version above is what gets written into every participant's
stored match snapshot, so a result produced in March can still be explained
after the mapping moves in June.

## Why this exists

The assessment and the ministry table were written by different people for
different purposes and share only part of a vocabulary. Matching compared
display strings, so:

- 12 of the table's 22 distinct terms happened to be spelt like an assessed
  gift and matched; the other 10 could never match anything.
- 6 of the 18 assessed gifts were invisible to every ministry in the church.
- The **Administration** ministry could not see the gift of administration.
  The **Prayer** ministry could not see prayer.
- **Production** and the **Livestream Team** could each be recognised by
  exactly one of their listed gifts, which under the current tier rules makes
  them unrecommendable at any tier.

## The rules this file follows

1. Exact identity, and an alternate name **printed in the assessment itself**,
   are safe — the instrument states the equivalence.
2. A legacy alias is evidence of past product behaviour, not approval.
3. Never fuzzy-match, substring-match, stem, or use a thesaurus or a model to
   invent a theological equivalence.
4. Never silently discard an unknown term. It is surfaced in
   `Gift_Crosswalk::validate()` and on the admin audit screen.
5. Each ministry resolves to a **set of unique gift ids**. Two source terms
   landing on one id count a participant's gift once.
6. Unmapped terms stay visible in the ministry guide and carry no scoring
   weight.

**Unmapped means unknown coverage. It never means "unlikely", and it is never
permission to pick the nearest-looking concept.**

## Resolved by the assessment itself (Rule 1)

| Table term | Gift id | How |
|---|---|---|
| `Organization` | `administration` | The assessment prints "Organization" as this gift's alternate name on the page the participant reads. |

This is the single most load-bearing resolution in the system — on its own it is
what stops Production and Livestream being unrecommendable — and it deliberately
does **not** live in the approved-alias list. It is not a church decision that
could be reversed by a later ministry-owner review; it is what the instrument
says. A mutation test confirms that removing it breaks eight tests.

## Approved aliases

Four, all approved 2026-08-30. Each was re-approved on the assessment's own
definition of the gift, not merely because the removed browser code did it.

| Table term | Gift id | Reasoning |
|---|---|---|
| `Assisting` | `service` | Service is defined as taking "the initiative to provide practical assistance". Same work, different word. |
| `Mentoring` | `pastoring` | Pastoring is "the ability to nurture a small group in spiritual growth and assume responsibility for their welfare", which is what the GROW and Youth rows mean. This is the entry that makes `pastoring` visible to any ministry at all. |
| `Mission` | `apostle` | Apostle is "the ability to start new churches/ventures and oversee their development" — the sending sense the Young Adults row means. |
| `Vision` | `leadership` | The assessment defines leadership as "the ability to clarify and communicate the purpose and direction (**'vision'**) of a ministry" — it uses the word itself. |

`Vision` was pointed at `leadership` rather than `apostle`, where the removed
browser code sent it. Four of the five teams listing `Vision` already list
`Leadership`, so under Rule 5 this adds nothing there. It matters for the
**Livestream Team**, which lists `Vision` and not `Leadership`, and which it
lifts from Suggested-only to Strong-capable.

## Rejected

Recorded because "nobody considered it" and "it was considered and declined"
are different states, and only one of them should be quiet. These are readable
in the admin audit screen via `Gift_Crosswalk::rejected()`.

| Proposed | Rejected because |
|---|---|
| `Prayer` → `praying-with-my-spirit` | That gift's alternate name in the assessment is **"Tongues/Interpretation"**, defined as praying in a language understood only by God. Treating it as the Prayer ministry's `Prayer` would make the gift of tongues a requirement for matching an intercession team. It also changes no team's reachable tier — Prayer already scores on faith, discernment, healing, mercy and wisdom. |
| `Leadership` → also `apostle`, `pastoring` | The removed browser code let three gifts satisfy one term. `Leadership` already has an exact assessed counterpart, so this would inflate every team listing it. |
| `Teaching` / `Evangelism` → `preaching` | Both already have exact counterparts. Equating the preaching/prophecy gift with teaching is a theological claim, not a normalisation. |

## Unmapped, and staying that way

**Ministry-table terms with no gift (5):** `Crafting`, `Creativity`, `Justice`,
`Knowledge`, `Prayer`.

**Assessed gifts no ministry maps (3):** `miracles`, `praying-with-my-spirit`,
`preaching`.

A participant whose likely gifts include one of those three is told so plainly,
rather than having it quietly routed to the nearest team:

> Pastoring is one of your likely gifts, but the current Fellowship ministry
> table does not map that gift yet. It was not silently treated as Mentoring.

## What this changes

Best achievable tier per ministry, assuming an empty corroboration registry
(so only the gift-only Strong route and the two-gift Suggested route can fire):

| | Strong-capable | Suggested-only | Never recommendable |
|---|---|---|---|
| Before (string equality) | 13 | 1 | **2** |
| After (this mapping) | **15** | 1 | **0** |

**Production remains Suggested-only.** It resolves to two gifts (`service`,
`administration`) because `Crafting` and `Creativity` are unmeasured. That is
the table's limit being reported honestly, not a gap waiting for an invented
alias. If the church wants Production to be Strong-capable, the real options
are to add a measured gift to that ministry row or to extend the assessment —
not to bridge `Crafting` to something that is not it.

## Heart, Abilities and Experience

The corroboration registry (`Corroboration::registry()`) ships **empty**, at
version `0.0.0`.

The supplied table validates ministry-to-gift relationships and nothing else.
There is no approved mapping from a heart option, an ability or a past job to a
particular ministry, so **Strong route B and Suggested route B cannot currently
fire** and only gift evidence produces a recommendation.

Heart, abilities and experience are still shown to the participant and to
authorised leaders as clearly-labelled supporting context. They do not enter a
tier, a threshold or the ordering, and they cannot create or widen access.
Free-text keyword overlap will never be corroboration: mappings must be by
canonical option id, chosen by ministry owners, versioned, and reviewed.

## Open questions for ministry owners

1. **Production.** Accept Suggested-only, or revisit that ministry row?
2. **`Knowledge`** appears on four rows (Livestream, Compass, Serve,
   Administration) and is unmapped. It is not one of the 18. Should those rows
   list a measured gift instead?
3. **`Creativity`** appears on six rows and is unmapped — the widest unmeasured
   term in the table.
4. **Structured H/A/E mappings.** Worth piloting for the overlapping pairs
   (Youth/Young Adults, Small Group/Compass, Welcome/Newcomers,
   Production/Livestream/Events), where gift evidence alone produces genuine
   co-matches.
5. **Thresholds.** The tier numbers in `Matching_Contract` are provisional
   launch values, not calibrated ones. They need real outcomes.
