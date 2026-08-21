# Pickvy Booklets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the two Pickvy pitch-deck booklets as static assets from the FlexPick frontend, with corrected pricing and a closing CTA, linked from a new homepage section.

**Architecture:** No new subsystems. Two static HTML files move from `backend/storage/app/public/` (gitignored, untracked) into `frontend/public/` (git-tracked, served verbatim by Astro's static build at clean URLs). One new section is added to the existing homepage (`frontend/src/pages/index.astro`) linking to both, `target="_blank"`, matching the page's existing external-link pattern.

**Tech Stack:** Astro 6, static HTML/CSS/JS (the booklets are self-contained, no framework).

**Spec:** `docs/superpowers/specs/2026-08-21-pickvy-booklets-design.md`

## Global Constraints

- Frontend commands run from `frontend/`: `npm run build` (static build → `dist/`), `npm run check` (astro check + eslint + prettier — the CI gate).
- No test runner exists for this frontend beyond `check` — verification for these tasks is build success + manual browser confirmation, not unit tests. There is nothing to TDD against for static content edits, so steps below verify by building and inspecting output rather than a red/green test cycle.
- Pickvy stays a distinct brand name throughout both booklets — do not rename "Pickvy" to "FlexPick" anywhere inside them.
- Every `$` pricing figure inside the booklets must match `backend/config/pricing.php` exactly (Diagnostic $5, Automated $49, Deep AI $199, Expert from $999, Agency plan $499/mo, Enterprise plan from $1,500/mo) — these are the only figures already correct or to be corrected; do not touch any other copy.
- Both booklets' own CSS/JS must remain fully self-contained — do not add Tailwind classes, Astro components, or any dependency on the site's global styles inside these files.
- Prettier formatting: 120 char width, single quotes, semicolons, ES5 trailing commas (applies to the `.astro` file edit, not the static HTML files, which are plain hand-authored HTML and not run through Prettier's HTML formatting in this repo's config — verify with `npm run check:prettier` that it doesn't flag them; if it does, run `npm run fix:prettier` and accept its formatting).

---

### Task 1: Move and correct the two booklet files

**Files:**
- Create: `frontend/public/pickvy-agency-booklet.html` (moved from `backend/storage/app/public/pickvy_agency_booklet_en.html`)
- Create: `frontend/public/pickvy-product-booklet.html` (moved from `backend/storage/app/public/pickvy_product_booklet_en.html`)
- Delete: `backend/storage/app/public/pickvy_agency_booklet_en.html`
- Delete: `backend/storage/app/public/pickvy_product_booklet_en.html`

**Interfaces:**
- Produces: two URLs the homepage will link to in Task 2 — `/pickvy-agency-booklet.html` and `/pickvy-product-booklet.html` (served from `frontend/public/`, so these are the exact paths Astro publishes them at with no build-time transformation).

- [ ] **Step 1: Copy both files into `frontend/public/` with the new names**

```bash
cd /var/www/html/flexpick.net
cp backend/storage/app/public/pickvy_agency_booklet_en.html frontend/public/pickvy-agency-booklet.html
cp backend/storage/app/public/pickvy_product_booklet_en.html frontend/public/pickvy-product-booklet.html
```

- [ ] **Step 2: Fix the four stale "free" pricing mentions in `frontend/public/pickvy-agency-booklet.html`**

All four are pricing-accuracy issues: Diagnostic is a $5 paid tier, not free, and the tier's own display name dropped "Free" in the backend (`AuditTier::DIAGNOSTIC->label()` is now `"Diagnostic Report"`).

Edit 1 — section 9 "IN YOUR WORKFLOW" lead paragraph:

Find:
```html
      <p class="lead">Plug Pickvy into the moments that used to be guesswork. Start free; reach for a deeper tier when you need signed-off evidence.</p>
```

Replace with:
```html
      <p class="lead">Plug Pickvy into the moments that used to be guesswork. Start with a $5 diagnostic; reach for a deeper tier when you need signed-off evidence.</p>
```

Edit 2 — section 10 "WHAT YOU GET" tier card:

Find:
```html
        <div class="tier"><div class="n">Free diagnostic</div><div class="pr">$0</div><div class="d">Quick read to qualify a codebase</div></div>
```

Replace with:
```html
        <div class="tier"><div class="n">Diagnostic Report</div><div class="pr">$5</div><div class="d">Quick read to qualify a codebase</div></div>
```

Edit 3 — section 11 "WHY PICKVY" closing paragraph:

Find:
```html
      <p class="close">Your agency's name ships on every release. Pickvy makes sure you know exactly what you're shipping &mdash; start with a free diagnostic on a live project.</p>
```

Replace with:
```html
      <p class="close">Your agency's name ships on every release. Pickvy makes sure you know exactly what you're shipping &mdash; start with a $5 diagnostic on a live project.</p>
```

- [ ] **Step 3: Fix the one stale "free" pricing mention in `frontend/public/pickvy-product-booklet.html`**

Find (section 11 "WHY PICKVY" closing paragraph):
```html
      <p class="close">Your product ships every week, built by more people than any one person can review. Pickvy gives you one honest number for all of it &mdash; start with a free diagnostic on your main repo.</p>
```

Replace with:
```html
      <p class="close">Your product ships every week, built by more people than any one person can review. Pickvy gives you one honest number for all of it &mdash; start with a $5 diagnostic on your main repo.</p>
```

- [ ] **Step 4: Add a closing CTA to `frontend/public/pickvy-agency-booklet.html`**

Neither booklet links back to flexpick.net anywhere. Add a link right after the `.close` paragraph edited in Step 2, inside the same `<div class="wrap">`, styled with the booklet's own CSS variables (`--gold`, consistent border-radius scale — 14px, between the 16px `.tier` cards and the 22px `.card`/`.callout` blocks) so it stays visually native to the deck rather than looking like an inserted foreign element.

Find:
```html
      <p class="close">Your agency's name ships on every release. Pickvy makes sure you know exactly what you're shipping &mdash; start with a $5 diagnostic on a live project.</p>
    </div>
    <div class="footer"><div class="wrap"><span class="brandmark">Pickvy</span><span>11</span></div></div>
  </section>

</div>
```

Replace with:
```html
      <p class="close">Your agency's name ships on every release. Pickvy makes sure you know exactly what you're shipping &mdash; start with a $5 diagnostic on a live project.</p>
      <p style="margin-top:24px">
        <a href="https://flexpick.net" style="display:inline-block;border:1px solid var(--gold);border-radius:14px;padding:12px 22px;color:var(--gold);text-decoration:none;font-weight:800;font-size:15px">Start at flexpick.net &rarr;</a>
      </p>
    </div>
    <div class="footer"><div class="wrap"><span class="brandmark">Pickvy</span><span>11</span></div></div>
  </section>

</div>
```

- [ ] **Step 5: Add the same closing CTA to `frontend/public/pickvy-product-booklet.html`**

Find:
```html
      <p class="close">Your product ships every week, built by more people than any one person can review. Pickvy gives you one honest number for all of it &mdash; start with a $5 diagnostic on your main repo.</p>
    </div>
    <div class="footer"><div class="wrap"><span class="brandmark">Pickvy</span><span>11</span></div></div>
  </section>

</div>
```

Replace with:
```html
      <p class="close">Your product ships every week, built by more people than any one person can review. Pickvy gives you one honest number for all of it &mdash; start with a $5 diagnostic on your main repo.</p>
      <p style="margin-top:24px">
        <a href="https://flexpick.net" style="display:inline-block;border:1px solid var(--gold);border-radius:14px;padding:12px 22px;color:var(--gold);text-decoration:none;font-weight:800;font-size:15px">Start at flexpick.net &rarr;</a>
      </p>
    </div>
    <div class="footer"><div class="wrap"><span class="brandmark">Pickvy</span><span>11</span></div></div>
  </section>

</div>
```

- [ ] **Step 6: Delete the original files from backend storage**

They are gitignored and untracked (confirmed: `git check-ignore -v backend/storage/app/public/pickvy_agency_booklet_en.html` matches `backend/storage/app/public/.gitignore:1:*`), so deleting them loses no git history.

```bash
cd /var/www/html/flexpick.net
rm backend/storage/app/public/pickvy_agency_booklet_en.html backend/storage/app/public/pickvy_product_booklet_en.html
```

- [ ] **Step 7: Verify both files build and serve correctly**

```bash
cd frontend
npm run build
ls dist/pickvy-agency-booklet.html dist/pickvy-product-booklet.html
grep -c '\$5' dist/pickvy-agency-booklet.html   # expect 3 (workflow line, tier card, closing line)
grep -c '\$5' dist/pickvy-product-booklet.html  # expect 1 (closing line)
grep -c 'flexpick.net' dist/pickvy-agency-booklet.html   # expect at least 1 (the new CTA)
grep -c 'flexpick.net' dist/pickvy-product-booklet.html  # expect at least 1 (the new CTA)
grep -ci 'free' dist/pickvy-agency-booklet.html   # expect 0
grep -ci 'free' dist/pickvy-product-booklet.html  # expect 0
```

Expected: `dist/pickvy-agency-booklet.html` and `dist/pickvy-product-booklet.html` both exist; the `$5` counts match; both `flexpick.net` counts are ≥ 1; both `free` counts are 0 (case-insensitive, confirming no stale "free diagnostic" language survives anywhere in either file).

- [ ] **Step 8: Open both files directly in a browser and confirm they render correctly**

```bash
npm run dev
```

Visit `http://localhost:4321/pickvy-agency-booklet.html` and `http://localhost:4321/pickvy-product-booklet.html`. For each: confirm the deck renders with its own dark/gold styling (not the site's Tailwind styles), confirm arrow-key navigation between slides still works, confirm the last slide shows the corrected pricing/copy and the new "Start at flexpick.net →" link, and click that link to confirm it navigates to the homepage.

- [ ] **Step 9: Commit**

```bash
cd /var/www/html/flexpick.net
git add frontend/public/pickvy-agency-booklet.html frontend/public/pickvy-product-booklet.html
git add -u backend/storage/app/public/
git commit -m "feat(frontend): add Pickvy agency and product booklets as static pages

Move the two self-contained pitch-deck HTML files from backend storage
(gitignored, never tracked) into frontend/public/, where Astro serves
them verbatim at clean URLs. Corrects four stale \"free diagnostic\"
mentions to the current \$5 price and adds a closing-slide CTA back to
flexpick.net, since neither booklet linked back to the product at all."
```

---

### Task 2: Add a homepage section linking to both booklets

**Files:**
- Modify: `frontend/src/pages/index.astro:876-878` (insert a new section between the existing "Product path" section's closing `</section>` and the `<!-- ===== FAQ ===== -->` comment)

**Interfaces:**
- Consumes: `/pickvy-agency-booklet.html` and `/pickvy-product-booklet.html` (produced by Task 1) — the two `href` targets for this section's links.
- Consumes existing CSS classes already defined elsewhere in this file: `.fp-mono` (line ~1114), `.fp-h2` (line ~1126), `.fp-card` / `.fp-card-tag` / `.fp-card-title` / `.fp-card-body` (lines ~1261-1284), `.fp-footlink` (line ~1242), and the `[data-r='grid3']` responsive hook (site-wide rule at line ~1959: `grid-template-columns: 1fr !important` under `@media (max-width: 680px)`, already applied to the two other 3-column grids in this file at lines ~316 and ~824 — reused here on a 2-column grid since the rule only forces single-column at narrow widths regardless of the original column count). No new CSS is added — this task reuses the page's existing section/card/link/responsive patterns exactly as the "Product path" section above it does.
- Produces: nothing consumed by a later task — this is the final piece of the feature.

- [ ] **Step 1: Insert the new section**

Open `frontend/src/pages/index.astro`. Find the end of the "Product path" section and the start of the FAQ section:

```html
        <div
          style="text-align: center; margin-top: 40px; display: flex; gap: 28px; justify-content: center; flex-wrap: wrap;"
        >
          <a
            href={`${PRODUCT_APP.url}/reports/sample`}
            target="_blank"
            rel="noopener"
            class="fp-footlink"
            style="color: #d4a853;"
          >
            See a sample report →
          </a>
          <a href={`${PRODUCT_APP.url}/pricing`} rel="nofollow" class="fp-footlink" style="color: #d4a853;">
            View plans →
          </a>
        </div>
      </div>
    </section>

    <!-- ===== FAQ ===== -->
```

Replace with (the existing block unchanged, plus the new section inserted after it):

```html
        <div
          style="text-align: center; margin-top: 40px; display: flex; gap: 28px; justify-content: center; flex-wrap: wrap;"
        >
          <a
            href={`${PRODUCT_APP.url}/reports/sample`}
            target="_blank"
            rel="noopener"
            class="fp-footlink"
            style="color: #d4a853;"
          >
            See a sample report →
          </a>
          <a href={`${PRODUCT_APP.url}/pricing`} rel="nofollow" class="fp-footlink" style="color: #d4a853;">
            View plans →
          </a>
        </div>
      </div>
    </section>

    <!-- ===== Pickvy booklets ===== -->
    <section
      id="booklets"
      style="position: relative; padding: 100px 32px; border-top: 1px solid rgba(255,255,255,0.05); scroll-margin-top: 80px;"
    >
      <div style="max-width: 1140px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 64px;">
          <p class="fp-mono" style="margin: 0 0 12px; font-size: 12px; letter-spacing: 0.16em; color: #d4a853;">
            READ THE PITCH
          </p>
          <h2 class="fp-h2">Built for how you buy.</h2>
        </div>
        <div data-r="grid3" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
          <a
            href="/pickvy-agency-booklet.html"
            target="_blank"
            rel="noopener"
            class="fp-card"
            style="display: block; border: 1px solid rgba(255,255,255,0.07); border-radius: 10px; padding: 28px; background: rgba(255,255,255,0.015); text-decoration: none;"
          >
            <p class="fp-mono fp-card-tag"><span style="color: #d4a853;">01</span> · agencies/</p>
            <h3 class="fp-card-title">For agencies →</h3>
            <p class="fp-card-body">
              Screening vendor progress, taking over a project, and signing off on milestones — how Pickvy fits an
              agency's delivery pipeline.
            </p>
          </a>
          <a
            href="/pickvy-product-booklet.html"
            target="_blank"
            rel="noopener"
            class="fp-card"
            style="display: block; border: 1px solid rgba(255,255,255,0.07); border-radius: 10px; padding: 28px; background: rgba(255,255,255,0.015); text-decoration: none;"
          >
            <p class="fp-mono fp-card-tag"><span style="color: #d4a853;">02</span> · product teams/</p>
            <h3 class="fp-card-title">For product teams →</h3>
            <p class="fp-card-body">
              One shared health score across every team and every AI coding agent pushing into your codebase — how
              Pickvy fits a product org.
            </p>
          </a>
        </div>
      </div>
    </section>

    <!-- ===== FAQ ===== -->
```

- [ ] **Step 2: Run the CI gate**

```bash
cd frontend
npm run check
```

Expected: astro check, eslint, and prettier all pass with no errors.

- [ ] **Step 3: Build and confirm the section is in the output**

```bash
npm run build
grep -c 'pickvy-agency-booklet.html' dist/index.html
grep -c 'pickvy-product-booklet.html' dist/index.html
```

Expected: both build successfully, both greps return at least 1.

- [ ] **Step 4: Manual browser verification**

```bash
npm run dev
```

Visit `http://localhost:4321/`. Scroll to the new "Built for how you buy" section between the "Product path" cards and the FAQ. Confirm:
- Two cards render side by side on desktop, matching the visual style of the "Product path" cards above (same border, background, hover behavior).
- At a mobile viewport width (e.g. 375px), the cards stack to a single column (via the `[data-r='grid3']` rule reused in Step 1 — no separate mobile fix should be needed; if they don't stack, re-check that `data-r="grid3"` was added to the grid `<div>` exactly as written in Step 1).
- Clicking each card opens the correct booklet in a new tab.
- The FAQ section still immediately follows this new section with no layout gap or overlap.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add frontend/src/pages/index.astro
git commit -m "feat(frontend): link the Pickvy booklets from the homepage

New section between the existing three-card 'audit as a product' pitch
and the FAQ: two cards linking to /pickvy-agency-booklet.html and
/pickvy-product-booklet.html (added in the prior commit), reusing the
page's existing card/link styles rather than introducing new ones."
```
