# Unified Frontend Layout for Backend Pages — Design

**Date:** 2026-07-13
**Status:** Approved design, pending implementation plan
**Scope:** Spec 2 of 4 from the 2026-07-13 feature sprint decomposition. Spec 1 (`2026-07-13-foundations-fixes-design.md`) covers auth redirects and nav state; Specs 3–4 cover user/admin audit management.
**Builds on:** the landing design in `frontend/src/pages/index.astro` (the "Stabilize" redesign) and SaaSykit's existing Blade layout system.

## Problem

The Laravel app's public pages (login, register, password flows, pricing, checkout, verification, invitations, legal) still wear SaaSykit's stock light theme — Poppins font, purple/blue palette, gradient side panels. The landing site at flexpick.net is a dark, gold-accented design. Moving between them feels like switching products. This spec makes every backend-served public page visually continuous with the landing.

## Decisions made during brainstorming

| Question | Decision |
|---|---|
| Theme direction | Fully dark, matching the landing (near-black `#0b0a09`, cream text, gold accents); public pages are dark-only, no theme toggle |
| Implementation approach | Restyle SaaSykit's existing layouts in place (`app`, `focus`, `focus-center`); keep all Livewire/controller logic untouched |
| Filament dashboard | Brand alignment only (logo, gold primary, dark default, fonts) via supported theming hooks; no custom Filament theme CSS; admin panel untouched |
| Web report page | Stays light — it is a standalone "document" already using the gold accent |

## Landing design language (source of truth)

Extracted from `frontend/src/pages/index.astro` and `frontend/src/components/CustomStyles.astro`:

- **Canvas:** page background `#0b0a09`; card surfaces `rgba(255,255,255,0.04)`; borders `rgba(255,255,255,0.06)` (chrome) to `rgba(255,255,255,0.12)` (inputs/cards)
- **Text:** body cream `#e8e6de` (muted variants via alpha, e.g. `rgba(232,230,222,0.55)`); bright headings `#f5f5f0`
- **Accents:** gold `#d4a853` (primary actions, links, highlights); coral `#e2694a` / `#dc6b5a` (warnings, errors)
- **Fonts:** DM Sans (body), Syne 700/800 (headings), JetBrains Mono 400/500 (eyebrows, labels, small caps with letter-spacing)
- **Components:** buttons = gold background, dark text, 8px radius; inputs = `rgba(255,255,255,0.04)` fill, `0.12` border, 8px radius, gold focus ring (`outline: 2px solid rgba(212,168,83,0.7)`, offset); wordmark = "Flex" cream + "Pick" gold
- **Nav/footer:** sticky translucent dark nav with mono links; footer with mono section labels and muted link columns

## 1. Design tokens

All theming flows through two existing files; no new CSS architecture.

### `backend/resources/css/colors.css` (Tailwind 4 `@theme`)

- Rebuild `--color-primary-50…950` as a gold scale centered on `#d4a853`.
- Rebuild `--color-secondary-50…950` as a coral scale centered on `#e2694a`.
- Add surface tokens used by layouts and components: canvas `#0b0a09`, raised surface, border strengths, cream text tones.
- Add font tokens: `--font-sans` → DM Sans stack, `--font-heading` → Syne, `--font-mono` → JetBrains Mono.

### `backend/resources/css/app.css` (daisyUI)

- Replace `themes: light --default` with one custom `flexpick` dark theme defining daisyUI's semantic colors from the tokens above (`base-100` = card surface on dark, `base-content` = cream, `primary` = gold, `error` = coral). Every existing daisyUI class in page markup (`card`, `bg-base-100`, `btn`, `input`) flips to the new look with no markup edits.

### `backend/resources/views/components/layouts/partials/head.blade.php`

- Replace the Poppins Google Fonts link with the landing's font request: DM Sans + Syne (700/800) + JetBrains Mono (400/500), same `display=swap` pattern the landing uses.

## 2. Layout rewrites

Three layout components carry effectively all public pages; restyling them restyles the site.

### `components/layouts/app.blade.php` + `app/header.blade.php` + `app/footer.blade.php`

Used by: pricing, terms, privacy, already-subscribed, audit status.

- Body goes dark (`#0b0a09` canvas, cream text) — remove `text-primary-900`.
- **Header**: landing nav pattern — FlexPick wordmark (cream "Flex", gold "Pick") linking to the landing site, mono-font nav links, gold CTA. Links: landing home, Pricing, and the existing auth-aware entry (Login for guests / Dashboard + user menu for authenticated users — behavior from `navigation-links.blade.php` and `user-menu.blade.php` is kept, only restyled).
- **Footer**: landing footer pattern — mono section labels, muted cream links, legal links (terms, privacy), contact email. Same links the footer has today, landing visual language.

### `components/layouts/focus.blade.php`

Used by: login, register, password email/reset/confirm, 2FA challenge.

- Drop the purple gradient right panel and white form area.
- New composition: full-bleed dark page; wordmark top-left (links to landing); the form slot renders in a landing-style card (raised surface, `0.12` border, 8px radius); the side panel becomes a quiet brand panel — mono eyebrow, Syne heading, one gold accent rule — reusing each page's existing `$right` slot copy ("Login.", "It's great to see you back…").
- "&lt; back home" link kept, restyled mono.

### `components/layouts/focus-center.blade.php`

Used by: checkout (plan, product, subscription convert/change), all thank-you pages, email/phone verify, invitations.

- Same dark treatment with a single centered landing-style card.

### Shared component styling — `backend/resources/css/styles.css`

One pass over the shared form/UI styling so Livewire form internals need no logic changes: inputs/selects/textareas (dark fill, `0.12` border, gold focus ring), labels (mono, small, letter-spaced), primary/secondary/ghost buttons (gold solid / outlined / text), links (gold), headings (Syne), validation errors (coral). Mirrors the landing's `fp-input` / `fp-label` / `fp-btn` specs.

### Page-level cleanup

Individual pages keep their structure; the only per-page edits are hardcoded light-theme utilities that fight the dark canvas (e.g. `shadow-xl` on white cards, explicit `text-primary-900`/`text-white` on the old gradient panel, light-only illustration assets if any). Pages in scope: login, register, password email/reset/confirm, 2FA, email verify, phone verify, registration thank-you, pricing, plan checkout, product checkout, subscription change/convert (+ local variants), all thank-you pages, already-subscribed, invitations accept, terms, privacy, audit status.

## 3. What deliberately stays

- **Web report page** (`reports/audit-web.blade.php`, `reports/link-expired.blade.php`): light document styling, already gold-accented — untouched.
- **Emails** (`components/layouts/email.blade.php`): untouched.
- **Error pages**: untouched in this spec.
- **Admin panel**: stock Filament.
- **All routes, controllers, Livewire components, and form behavior**: untouched — presentation only.
- Blog/roadmap marketing routes were already removed in a previous branch (`RemovedMarketingRoutesTest`), so "backend serves only required pages" is already satisfied.

## 4. Filament user dashboard — brand alignment

In `app/Providers/Filament/DashboardPanelProvider.php`, via supported Filament APIs only:

- Primary color: custom gold palette (replacing `Color::Teal`).
- Brand: FlexPick name and wordmark logo (SVG added under `backend/public/images/`), favicon matching the landing.
- Dark mode as the default appearance.
- Font: DM Sans via `->font()`.

No custom theme CSS file; no admin panel changes.

## 5. Testing

- **Existing feature tests keep passing** — pricing, login/register, checkout, verification, invitations tests assert behavior and specific copy, not colors; they double as regression proof that no page lost its layout.
- **New layout smoke test**: one feature test asserting the shared layout markers (FlexPick wordmark markup, dark canvas class) render on login, register, pricing, plan checkout, and password-reset request pages — catches a page silently falling back to an unstyled or partial layout. (Layout slot names — `$left`, `$right`, `$slot`, `$title` — stay unchanged, so no page's slot bindings break.)
- **Build gate**: backend `npm run build` (Vite/Tailwind) succeeds.
- **Manual visual pass** over the full page inventory listed in §2, logged-out and logged-in, mobile and desktop widths.

## Out of scope (later specs)

- Audits section and widgets in the user dashboard (Spec 3).
- Admin audit management and email notification tracking (Spec 4).
- Full custom Filament theme.
