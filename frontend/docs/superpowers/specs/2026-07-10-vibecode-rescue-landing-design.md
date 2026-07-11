# FlexPick Landing Page Pivot — Vibecoded Codebase Rescue

**Date:** 2026-07-10
**Status:** Approved

## Goal

Rewrite all landing page content to reflect the company's new core focus: helping companies
whose projects were built by vibe coders / AI agents and now face stability, maintainability,
or scalability problems. FlexPick analyzes the codebase, identifies technical risks, introduces
proven engineering practices, and prepares the codebase for effective AI-assisted development.

## Approved positioning decisions

- **Offer model:** Free codebase audit, no public pricing. "If we can't help, we say so."
- **Scope:** Rescue-first narrative; ongoing development/partnership as a secondary offer.
- **Tone:** Empathetic, pro-AI. Vibe coding was the right call to ship fast; now it needs
  engineering. No shaming, no anti-AI sentiment.
- **Dashboard section:** Repurposed as a "Codebase Health Report" audit visual (risk score,
  duplication, test coverage, hotspots) instead of the task/time-tracking showcase.
- No dollar amounts, no line-count reduction promises, no copy quoted from competitors.

## Section-by-section content plan

Structure, layout, and animations stay identical — only content changes.

1. **SEO metadata** (`src/config.yaml`, `src/pages/index.astro` title):
   "FlexPick — We Rescue AI-Built Codebases"; description about turning vibecoded projects
   into stable, scalable, maintainable products.
2. **Hero:** Headline "Your AI-built product works. Let's keep it that way." Subtext validates
   shipping fast with AI, names the pain (features break other features, velocity drops),
   promises engineering foundations. Primary CTA "Get a free audit" → #contact.
3. **Services grid (6 cards):** Free codebase audit · Stabilization (tests, CI, monitoring) ·
   Refactoring & simplification · Engineering practices (reviews, safe deployments) ·
   AI-ready codebase (guardrails, conventions, docs for AI tools) · Ongoing partnership.
4. **Process (3 steps):** Show us your codebase (free audit, honest verdict) → Get a clear
   plan (ranked risks, plain language) → We stabilize, you keep building (handover of code,
   docs, guardrails).
5. **Trust cards (3):** "We'll tell you if we can't help" · "Senior engineering, AI-native" ·
   "You keep everything" (no lock-in).
6. **Codebase Health Report section:** same dashboard frame, mockup reworked into an audit
   report — risk score, duplication %, test coverage, top-risk hotspots, stabilization
   progress bar. Copy explains what the audit examines and what the client receives.
7. **FAQ (6):** rewrite from scratch? · product works, why care? · against AI coding? ·
   what do you need from me? · what if audit finds nothing serious? · what happens after?
8. **CTA:** "Keep building. We'll fix the foundation." Button "Get a free audit".
9. **Navigation** (`src/navigation.ts`): anchors unchanged; header action "Free Audit".
10. **Contact modal:** header "Get your free audit", copy asks about product/codebase.

## Files touched

- `src/pages/index.astro` — all section copy + page title
- `src/config.yaml` — default SEO title/description
- `src/navigation.ts` — header CTA label
- `src/components/widgets/ContactModal.astro` — header + placeholder copy

## Verification

`npm run check` and `npm run build` must pass.
