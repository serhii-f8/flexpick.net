# Pickvy Booklets — Design

## Context

Two standalone pitch-deck HTML files exist at `backend/storage/app/public/pickvy_agency_booklet_en.html` and `pickvy_product_booklet_en.html` — an agency-facing and a product-company-facing deck for "Pickvy," a distinct sales-deck brand name from the FlexPick homepage's own branding. Each is a complete, self-contained document: its own `<head>` (Google Fonts, meta tags), inline CSS (dark background, gold/coral accents, scroll-snapped full-viewport "slides"), and inline JS (arrow-key navigation between slides). Neither links back to flexpick.net anywhere.

This is the first of three sub-projects under the original "frontend homepage" request (the other two — layout/button cleanup, and stale pricing-copy fixes — are separate, not addressed here).

## Decisions

- **Brand:** Pickvy stays as its own name throughout both booklets — no rebrand to FlexPick. They are linked *from* the FlexPick homepage as external-feeling documents, not merged into it.
- **Serving:** Both files move into `frontend/public/` and are served as static assets verbatim — no Astro page wrapper, no reuse of the site's layout. This avoids any CSS/JS collision with the site's own Tailwind styles (each keeps its own isolated stylesheet and script) and matches the existing pattern of linking out to a full external document (`target="_blank" rel="noopener"`, as already used for "See a sample report →").
- **Placement:** A new, compact section on the homepage (`src/pages/index.astro`), positioned after the main pitch content and before the FAQ section. Two cards/links: "For agencies →" and "For product teams →", each opening its booklet in a new tab.
- **Pricing accuracy:** Every `$` figure in both files was checked against `config/pricing.php`. Only one drift: the agency booklet's tier card says `"Free diagnostic" / "$0"`. Corrected to `"Diagnostic Report" / "$5"`, matching the backend's own Diagnostic-tier rename from the manual-pricing plan earlier this session (`AuditTier::DIAGNOSTIC->label()` dropped "Free" the same way). No other figures need changing — $49 / $199 / $999 tier prices and $499/mo / $1,500/mo subscription prices in both booklets already match the catalog exactly.
- **Closing CTA:** Neither booklet currently links back to the product at all. Add a small CTA on each booklet's closing slide — a link back to the FlexPick homepage, styled to match the booklet's own gold-accent design language (not FlexPick's Tailwind styles, since the booklet stays visually self-contained).

## File changes

- `frontend/public/pickvy-agency-booklet.html` — moved from backend storage; pricing tier corrected; closing-slide CTA added.
- `frontend/public/pickvy-product-booklet.html` — moved from backend storage; closing-slide CTA added (no pricing correction needed).
- `frontend/src/pages/index.astro` — new section with two cards linking to the above, placed between the main pitch and the FAQ section.
- The two original files under `backend/storage/app/public/` are removed once moved (no reason to keep a copy outside version control in a Laravel storage directory not otherwise referenced by the app).

## Testing

- `npm run build` (frontend/) — confirms the static files land in `dist/` at their clean URLs and the new homepage section builds without errors.
- `npm run check` (astro check + eslint + prettier) — the CI gate.
- Manual browser verification: dev server up, new section renders and links correctly at both desktop and mobile widths; each booklet opens standalone and its own styling/keyboard navigation still works, unaffected by the site's global CSS (expected, since `public/` files bypass Astro's page pipeline entirely).
