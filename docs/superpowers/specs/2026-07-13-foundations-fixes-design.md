# Foundations Fixes: Docker Permissions, Auth Redirects, Seeders, Checkbox — Design

**Date:** 2026-07-13
**Status:** Approved design, pending implementation plan
**Scope:** Spec 1 of 4 from the 2026-07-13 feature sprint decomposition. Follow-up specs cover the unified frontend layout, user-dashboard audit management, and admin audit management.
**Builds on:** `2026-07-11-audit-freemium-intake-design.md` (products/plans/pricing model), `2026-07-11-monorepo-split-docker-design.md` (Sail-based Docker setup).

## Problem

Four independent foundation-level issues block day-to-day development and the upcoming feature work:

1. `/admin` 500s with a storage permission error inside the Docker dev environment.
2. Logging in from the landing page redirects back to the landing page instead of the dashboard, and the static landing site always shows **Login** even for authenticated users.
3. The monetization seeder (`AuditMonetizationSeeder`) exists but is not wired into any seeder entrypoint, and there are no test users or subscription states for manual testing.
4. The marketing-consent checkbox in the Free Audit modal uses unstyled native browser rendering.

## 1. Docker storage permissions

### Diagnosis

`WWWUSER=1000` is set in `backend/.env` and the Sail entrypoint (`docker/8.4/start-container`) remaps the `sail` user to that UID at boot — but nothing repairs ownership of files created *before* the mapping existed, or created by root (e.g. via `docker compose exec -u root`, or containers started without `WWWUSER`). Once a root-owned subdirectory exists under `storage/framework/cache/data/`, every write into it fails permanently:

```
file_put_contents(/var/www/html/storage/framework/cache/data/85/5f/…): Permission denied
```

### Fix: entrypoint self-heal

Extend `docker/8.4/start-container` (and `docker/8.5/start-container` to keep the images consistent) so that, after the `usermod` step, it repairs Laravel's writable directories:

- Targets: `storage/`, `bootstrap/cache/` (which transitively covers `storage/framework/{cache,sessions,views}` and `storage/logs`).
- Mechanism: `find <dir> \( -not -user $WWWUSER \) -exec chown $WWWUSER:$WWWGROUP {} +` plus `chmod -R u+rwX` on the same mismatched set. Scoped to mismatched files only, so boot cost is near-zero when nothing is wrong.
- Runs on every container start — automatic after `docker compose up`, no manual steps after restarts.
- No `777` anywhere; ownership goes to the mapped dev user. This entrypoint is part of the dev image only — production deploys via Deployer (`backend/deploy.php`) and is unaffected.

### Alternatives rejected

- **Named volume for `storage/`** — hides logs and cached views from the host, hurting the dev workflow.
- **Documented manual `chown`** — violates the "works automatically after `docker compose up`" requirement.

### Verification

One-time cleanup of the currently broken state, then confirm `/admin` loads, and that cache writes, sessions, compiled views, and log writes all succeed as the `sail` user.

## 2. Auth redirects and navigation state

### Login redirect bug

`LoginController::showLoginForm()` seeds Laravel's intended URL from `url()->previous()` (the `Referer` header). Arriving at `/login` from the landing page makes the landing page the "intended" destination, which then wins inside `RedirectAwareTrait::getRedirectUrl()`.

**Fix:** only call `Redirect::setIntendedUrl()` when the previous URL is an in-app URL worth returning to — same host as the backend app, and not the `home`, `login`, or `register` routes. `RedirectAwareTrait` already implements the rest of the desired matrix and stays as-is:

- Real intended URL (protected-page deep link) → preserved.
- Admin user → `filament.admin.pages.dashboard` (`/admin`).
- Regular user → `route('dashboard')` (`/dashboard`).

### Registration and verification

The same trait governs post-registration and post-verification redirects; with the referer fix, users land on the dashboard (or the email-verification notice first, when verification is required) instead of the landing page. Additionally, `RouteServiceProvider::HOME` changes from `/` to `/dashboard` — framework middleware (e.g. `verified`, `guest`) redirects there directly, bypassing the trait.

### Landing-page navigation swap

The landing site is static Astro on a different origin (`flexpick.net` vs `app.flexpick.net` in prod; `localhost:4321` vs `localhost:8080` in dev), so it cannot read the Laravel session server-side.

**Design: auth-status endpoint (server-verified).**

- Backend: `GET /api/auth/status` returning `{"authenticated": true|false}`. Reads the session cookie via the `web` session guard; no CSRF requirement (read-only), `Cache-Control: no-store`.
- CORS: allow the landing origin with `Access-Control-Allow-Credentials: true`, origin taken from config (`FRONTEND_URL`). Prod additionally requires `SESSION_DOMAIN=.flexpick.net` so the session cookie is visible to requests initiated from the landing origin. In dev, cookies on `localhost` are shared across ports, so `:4321 → :8080` works with CORS alone.
- Frontend: a small progressive-enhancement script in the Astro header. Default render is **Login** (correct for the logged-out majority — no wrong-state flash). On a successful `{"authenticated": true}` response, swap the button to **Dashboard** linking to the backend `/dashboard`. Any error or timeout leaves **Login** untouched.
- The same script hides or swaps any other login/register CTAs on the landing page for authenticated visitors.

### Alternatives rejected

- **JS-readable marker cookie** (`fp_auth=1` on `.flexpick.net`) — no network call, but goes stale after logout/session expiry and adds a second source of truth.
- **Skip landing detection** — fails the acceptance criteria.

## 3. Seeders for products, plans, and subscriptions

Split per approved decision: catalog data in the default seed, test users in the demo seed.

### Default seed (`DatabaseSeeder`)

- Wire the existing `AuditMonetizationSeeder` into `DatabaseSeeder` so `migrate:fresh --seed` always produces the purchasable catalog from the freemium design: the $5 `audit-report-unlock` `OneTimeProduct` and the three `Product`+`Plan`+`PlanPrice` tiers (Starter 5/$10, Growth 20/$30, Scale 50/$60, monthly interval).
- Extend the seeder with what the pricing page and checkout need: display order, active/inactive flags, plan feature lists / descriptions, and payment-provider identifiers as clearly marked test placeholders (e.g. `price_test_audit_starter`) so nothing accidentally looks like a live Stripe ID.
- Remains idempotent: every record keyed through `updateOrCreate` on slug/currency, safe to run repeatedly with no duplicates.

### Demo seed (`Demo\AuditDemoSeeder`, called from `DemoDatabaseSeeder`)

Test users with a known password, one per entitlement state:

| User | State |
| --- | --- |
| starter/growth/scale user | active subscription on each tier |
| trial user | trialing subscription |
| cancelled user | cancelled subscription (still in paid period) |
| expired user | expired / past-due subscription |
| free user | no subscription, free quota partially used |
| exhausted user | no subscription, 3/3 free audits consumed |

- Each user gets a spread of `AuditRequest` / `AuditReport` rows across statuses (pending verification, queued, running, completed, failed, awaiting access) to exercise dashboards built in later specs.
- Subscriptions are created directly in the database with placeholder provider data — sufficient for entitlements, audit limits, pricing pages, and upgrade/downgrade UI. Real checkout still requires Stripe test keys; the seeded placeholder IDs make that boundary explicit.
- Idempotent: users keyed by email, subscriptions/audits keyed by stable natural keys.

## 4. Free Audit checkbox styling

In `frontend/src/components/widgets/ContactModal.astro`, replace the native checkbox with the landing design language:

- Visually hidden native `<input type="checkbox">` kept in the DOM for accessibility and form submission; a styled sibling box renders the visual state.
- Styling matches the modal's palette: subtle `rgba(232,230,222,…)` border, gold `#d4a853` checked state (same accent as links), consistent radius/spacing.
- Checkbox and label text on one horizontal line; the whole `<label>` remains the click target as today.
- States: default, hover, focus-visible ring, checked, disabled.
- Pure CSS within the component; no JavaScript.

## Testing

- **Redirect matrix (feature tests):** guest login → `/dashboard`; admin login → `/admin`; login initiated from a protected page → original destination; login form opened from the landing page (external referer) → `/dashboard`, not the referer.
- **Auth-status endpoint (feature tests):** guest → `{"authenticated": false}`; authenticated session → `true`; CORS headers present for the configured landing origin; `no-store` cache header.
- **Seeders (tests):** run twice, assert record counts unchanged (idempotency); assert each demo user resolves to the expected entitlement state (plan allowance, quota remaining).
- **Permissions & checkbox:** verified manually — `/admin` smoke test after a fresh `docker compose up`, and browser check of the checkbox states.

## Out of scope (later specs)

- Unified landing-style layout for auth/pricing/checkout pages (Spec 2).
- Audits section + widgets in the user dashboard (Spec 3).
- Admin audit management, email notification tracking, admin widgets (Spec 4).
