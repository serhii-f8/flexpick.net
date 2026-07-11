# Monorepo Split: Frontend Website / Backend Product App + Docker Dev Environment

**Date:** 2026-07-11
**Status:** Approved
**Scope:** Plan 1 of 2 (see `2026-07-11-audit-report-pipeline-design.md` for the report feature)

## Goal

`flexpick.net` (the static Astro landing) is the only public marketing surface. The Laravel
backend (`backend/`, SaaSykit Tenancy) serves **only** the product app: registration/login,
plans & products, subscription checkout, and the tenant/admin dashboards. Both apps run
locally with one `docker compose up` at the repo root.

Out of scope: production deployment topology (Deployer stays as-is for backend; frontend
static hosting unchanged), the audit-report feature (Plan 2).

## URL layout

| Surface | Production | Local dev |
|---|---|---|
| Marketing site (Astro) | `https://flexpick.net` | `http://localhost:4321` |
| Product app (Laravel) | `https://app.flexpick.net` | `http://localhost:8080` |

No path-based proxying: the backend lives on its own host, so Laravel, Filament, and Vite
need no sub-path configuration. Cookies and sessions stay scoped per host.

## Component 1 — Frontend: configurable app URL

**What it does:** every landing CTA that leads into the product (register, login, pricing,
dashboard) points at the backend host without hardcoding it.

**Design:**
- Add to `frontend/src/config.yaml`:
  ```yaml
  productApp:
    url: 'https://app.flexpick.net'   # dev override via PUBLIC_APP_URL env if set
  ```
- Expose it through the existing `flexpick:config` virtual module (same mechanism as
  `site`, `metadata`, etc. — see `frontend/CLAUDE.md` config system). Allow an environment
  override (`PUBLIC_APP_URL`) so the Docker dev build points at `http://localhost:8080`
  without editing the YAML.
- The audit-request modal (`ContactModal.astro`) stays on the landing; its endpoint wiring
  is Plan 2's concern.
- Add product-app links where the landing design calls for them (header/footer login link,
  any pricing CTA), always built from `productApp.url` + named paths (`/register`,
  `/login`, `/pricing`, `/dashboard`).

**Dependencies:** none new. Uses the existing config/virtual-module system.

## Component 2 — Backend: remove the landing, add a lean plans page

**What it does:** the backend no longer presents SaaSykit marketing; it routes visitors to
plans or their dashboard.

**Design:**
- **`/` route** (`backend/routes/web.php`): replace `view('home')` with:
  - authenticated → redirect to `route('dashboard')` (existing `UserDashboardService` flow);
  - guest → redirect to `route('pricing')` (new).
- **`/pricing` page (new):** minimal Blade view on the existing app layout containing only
  `<x-plans.all calculate-saving-rates="true" show-default-product="1"/>` and
  `<x-products.all />` (the components the old home's `#pricing` section used), a short
  heading, and login/register links. `sitemapped` middleware on it. No hero, testimonials,
  features, or FAQ sections.
- **Remove duplicate marketing surfaces:**
  - Delete the `home` view and its route name usages (grep for `route('home')` and point
    them at `/` which still exists, so named-route references keep working — keep the route
    name `home` on the new redirect route).
  - Remove the backend **blog** routes (`/blog`, `/blog/category/{slug}`, `/blog/{slug}`)
    and **roadmap** routes (`/roadmap/*`) plus their nav/footer links. The Astro site owns
    all content marketing. Controllers/models stay (dead code removal is optional cleanup);
    only the public routes and links go, so the Filament admin blog tooling remains
    harmless. Any Blade/Filament link pointing at `route('blog')`/`route('roadmap')` must
    be found and removed in the same change (grep `route('blog`, `route('roadmap`).
  - Sitemap shrinks automatically: only routes tagged `sitemapped` are listed, and after
    this change that is `/` (redirect — remove `sitemapped` from it) and `/pricing` plus
    any checkout/legal pages already tagged.
- **Untouched:** `Auth::routes()`, `/plan/start`, all `/checkout/*` and `/subscription/*`
  routes, both Filament panels, webhooks in `routes/api.php`.

**Error handling:** none new — redirects and an existing-component page.

## Component 3 — Root Docker dev environment

**What it does:** `docker compose up` at the repo root boots backend (app, MySQL, Redis,
Mailpit) and the frontend dev server together. Dev only.

**Design:**
- **Root `compose.yml`** using Compose `include:` (requires Docker Compose ≥ 2.20):
  ```yaml
  include:
    - path: backend/compose.yml
  services:
    frontend:
      image: node:22-alpine
      working_dir: /app
      command: sh -c "npm install && npm run dev -- --host 0.0.0.0"
      environment:
        PUBLIC_APP_URL: 'http://localhost:8080'
      volumes:
        - ./frontend:/app
      ports:
        - '4321:4321'
  ```
- **Backend compose adjustments** (`backend/compose.yml`):
  - Move the `ngrok` service behind a compose **profile** (`profiles: [ngrok]`) and drop
    it from `laravel.test`'s `depends_on`, so booting doesn't require `NGROK_AUTHTOKEN`.
  - Everything else (mysql, redis, mailpit, Sail app container) stays as-is.
- **Env:** backend `.env` uses `APP_PORT=8080`, `APP_URL=http://localhost:8080`,
  Sail service hostnames (`DB_HOST=mysql`, `REDIS_HOST=redis`, `MAIL_HOST=mailpit`).
  Document `WWWUSER`/`WWWGROUP` exports (Sail convention) in the root README section.
- **First-run flow** (documented in root `CLAUDE.md`/README):
  ```bash
  docker compose up -d
  docker compose exec laravel.test composer install
  docker compose exec laravel.test php artisan key:generate
  docker compose exec laravel.test php artisan migrate:fresh --seed
  docker compose exec laravel.test npm install && docker compose exec laravel.test npm run dev  # Vite (optional hot reload)
  ```
- Horizon runs inside the app container when needed
  (`docker compose exec laravel.test php artisan horizon`) — required for Plan 2's queues;
  not a separate service for now.

**Trade-off noted:** `include:` keeps the backend compose usable standalone (Sail
conventions intact) while giving a single root entrypoint. The alternative — one merged
root compose — was rejected because it forks Sail's file and breaks `sail` CLI usage.

## Data flow (dev)

Browser → `localhost:4321` (Astro dev) → CTA links → `localhost:8080` (Laravel) →
register/checkout/dashboard. Emails land in Mailpit (`localhost:8025`).

## Testing

- Backend: feature tests for `/` redirects (guest → pricing, auth → dashboard), `/pricing`
  200 + renders plan components, blog/roadmap routes return 404. `php artisan test --compact`.
- Frontend: `npm run check` passes; build succeeds with and without `PUBLIC_APP_URL`.
- Manual: `docker compose up` from clean checkout following the documented first-run flow;
  verify both hosts respond and a register → checkout click-through works.
