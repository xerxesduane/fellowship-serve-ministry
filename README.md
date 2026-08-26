# Fellowship Dubai S.H.A.P.E. Discovery

A complete, mobile-first interactive adaptation of the 24-page S.H.A.P.E. Discovery workbook for Fellowship Dubai.

The journey preserves the source order and includes:

- teaching content before every assessment section
- all 18 spiritual gifts with references, alternate names, full descriptions, and three response categories
- all Heart roles, people groups, causes, and Other fields
- all 30 named Abilities plus nested language, hobby, and technical lists
- all four forced-choice Personality pairings
- all five Experience categories, including complete nested work and ministry lists
- Availability reflection, S.T.A.R.T., and a post-profile Ministry–Gift Table leading to current serving opportunities
- browser-based save/resume through `localStorage`
- a complete shareable profile with top-three ministry suggestions, copy, self-email/share, print/PDF, advisor, and Serving Opportunities actions

## Local development

```bash
npm install
npm run dev
```

Open `http://localhost:3000`.

## Deploy to another Vercel account

The app has no database, API keys, or required environment variables.

1. Import the GitHub repository into the target Vercel account.
2. Keep the detected Next.js defaults.
3. Deploy.

Do not copy a `.vercel` directory between accounts. Link the repository as a new project in the intended account.

## Main files

- Canonical structured content and types: `src/data/shapeContent.ts`
- Journey state and step routing: `src/components/shape-assessment.tsx`
- Profile construction and plain-text export: `src/lib/shapeProfile.ts`
- Final leader-ready profile: `src/components/shape-profile.tsx`
- Page-by-page fidelity audit: `docs/content-audit.md`
