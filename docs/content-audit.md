# S.H.A.P.E. content fidelity audit

Canonical source: `S.H.A.P.E discovery tool.pdf` (24 pages)

## Initial audit

| PDF pages | Source section | Before rebuild | Gap identified |
|---|---|---|---|
| 1 | Welcome / Introduction | Partial | No Fellowship Dubai welcome, ministry-team context, or discovery-session framing |
| 2 | Table of Contents | Missing | Entire page omitted |
| 3 | What is S.H.A.P.E.? | Missing | Entire teaching page omitted |
| 4 | Spiritual Gifts teaching | Missing | Scripture, six truths, instructions, and example omitted |
| 5-7 | Unwrapping My Gifts | Partial | Names paraphrased; Bible references, alternate names, full definitions, and exact response labels omitted |
| 8 | Heart teaching | Missing | Scripture and teaching content omitted |
| 9 | Passion for a Role | Partial | Definitions and Other field omitted |
| 10 | Passion for People / Cause | Partial | Multiple source options and both Other fields omitted |
| 11 | Abilities teaching | Missing | Entire teaching page omitted |
| 12-13 | Applying My Abilities | Partial | Wording shortened; nested languages, hobbies, technical options, and Other fields omitted |
| 14 | Personality teaching | Missing | Entire teaching page omitted |
| 15 | Plugging In My Personality | Partial | Labels and descriptions paraphrased |
| 16 | Experiences teaching | Missing | Scripture, five categories, and teaching content omitted |
| 17 | Spiritual, Painful, Educational Experiences | Partial | Most options, education category, and Other fields omitted |
| 17-19 | Work Experiences | Partial | Complete category/subcategory list omitted |
| 19-20 | Ministry Experiences | Partial | Complete age/life-stage and role/function lists omitted |
| 21 | Determining My Availability | Partial | Teaching, reflection questions, and exact options omitted |
| 22 | My Next Step | Partial | Source structure and Fellowship Dubai Serve-team handoff omitted |
| 23 | Five ways to S.T.A.R.T. | Missing | Entire page omitted |
| 24 | Discovery Session FAQs | Missing | Entire page omitted |

The rebuild must make every row complete, preserve all assessment options and Other fields, and ensure every response is represented in the final profile.

## Post-implementation audit

| PDF pages | Source section | After rebuild | Evidence in app |
|---|---|---|---|
| 1 | Welcome / Introduction | Complete | Fellowship Dubai welcome, ministry context, prayerful instructions, profile contact fields |
| 2 | Table of Contents | Complete | Dedicated journey-map step with all source headings and page numbers |
| 3 | What is S.H.A.P.E.? | Complete | Full teaching step, Psalm 139:16, five-part acrostic, ministry explanation |
| 4 | Spiritual Gifts teaching | Complete | Scripture, six truths, gift-list context, instructions, service example |
| 5-7 | Unwrapping My Gifts | Complete | All 18 gifts, references, alternate names, full definitions, three exact response choices |
| 8 | Heart teaching | Complete | Scripture, full teaching, three directions of passion |
| 9 | Passion for a Role | Complete | All 15 roles with full descriptions and Other field |
| 10 | Passion for People / Cause | Complete | All 15 people groups, 33 causes, and both Other fields |
| 11 | Abilities teaching | Complete | Full teaching and scripture |
| 12-13 | Applying My Abilities | Complete | All 30 named abilities, 26 language options, 7 hobby options, 8 technical options, and all four Other inputs |
| 14 | Personality teaching | Complete | Full teaching and four-couplet explanation |
| 15 | Plugging In My Personality | Complete | Four exact forced-choice pairs with both descriptions |
| 16 | Experiences teaching | Complete | Romans 8:28, five categories, pastoral teaching, 2 Cor. 1:4 |
| 17 | Spiritual, Painful, Educational Experiences | Complete | 10 spiritual, 31 painful, 6 educational options, sensitivity note, all Other fields |
| 17-19 | Work Experiences | Complete | All 18 categories, complete nested subcategories, search, category-specific Other fields |
| 19-20 | Ministry Experiences | Complete | 13 age/life-stage options, 40 ministry roles/functions, 1-3 selection guidance, both Other fields |
| 21 | Determining My Availability | Complete | Teaching, both reflection questions, exact hour and timing options, John 12:26 |
| 22 | My Next Step | Adapted after profile | A Ministry–Gift Table follows the completed profile and leads to the current Serving Opportunities form |
| 23 | Five ways to S.T.A.R.T. | Complete | Study titles, Trial and error, Analyze, Request input, Take training |
| 24 | Discovery Session FAQs | Deferred | Intentionally unpublished until the SERVE coach team, registration workflow, and dashboard are ready |

## Functional QA

- The current launch flow contains 19 guided stages; source page 24 is intentionally deferred and the page 22 action follows the completed profile.
- Every source assessment option and every source Other field is represented in
  the structured content at
  `serve-standalone/public/assessment/shapeContent.js`. (This
  line previously named a TypeScript source; the TypeScript implementation was
  removed when the project consolidated on the WordPress plugin, and the content
  moved to that file unchanged.)
- Spiritual Gifts cannot be completed until all 18 gifts have a response.
- Personality cannot be completed until all four pairings have a response.
- Every response type is represented in the final profile: three gift groups, three Heart groups, Abilities, four Personality choices, all five Experience categories, Availability reflections/options, and a recommended next step.
- Progress and the current step save to `localStorage` and resume after reload.
- The profile supports copy, email/share, and browser print/save-to-PDF.
- Long Ability and Experience lists are grouped, collapsible, and searchable.
- Production browser journey tested end to end with no console or page errors.
- Desktop and 390px-wide mobile views have no horizontal overflow.
