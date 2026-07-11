# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Layout

This is a **monorepo containing two independent applications** that do not import from each other and are built, tested, and deployed separately:

- **`frontend/`** — The public marketing/landing site. Static **Astro 6 + Tailwind CSS 4**, no server runtime. Currently positioned as a "vibecoded-codebase rescue" landing page (see `frontend/docs/superpowers/specs/`).
- **`backend/`** — The SaaS product itself: **SaaSykit Tenancy**, a multi-tenant Laravel SaaS starter kit on the **TALL stack** (Tailwind, Alpine.js, Laravel, Livewire) with a **Filament** admin panel and user dashboard.

Each app has its own git-tracked config, `package.json`, and lockfiles. **Always `cd` into the relevant app directory before running commands** — there is no root-level build or task runner.

Per-app deep documentation (read these when working in an app):
- `frontend/CLAUDE.md` — Astro architecture, config system, blog, theming.
- `backend/AGENTS.md` — full Laravel architecture, services, models, coding standards, Boost/Herd rules. (`backend/CLAUDE.MD` simply delegates to it.)

## frontend/ — quick reference

Node.js >= 22.12.0. Run from `frontend/`:

```bash
npm run dev      # dev server at localhost:4321
npm run build    # static build → frontend/dist/
npm run check    # astro check + eslint + prettier (CI gate)
npm run fix      # auto-fix eslint + prettier
```

Static output only. `~/` aliases `src/`. Site-wide settings live in `src/config.yaml`, exposed at build time as the virtual module `flexpick:config` (not hardcoded in components). See `frontend/CLAUDE.md` for the full picture.

## backend/ — quick reference

PHP 8.4. Run from `backend/`:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # bootstrap DB with demo/testing seeders

php artisan serve                    # dev server
npm run dev                          # Vite asset compilation (separate process)
php artisan horizon                  # Redis-backed queue worker/monitor

php artisan test --compact                       # run tests (preferred)
php artisan test --filter=TestName               # single test / filter
vendor/bin/phpstan analyse                       # Larastan static analysis
vendor/bin/pint                                  # code formatter (PSR-12)
```

Deploy: `php dep deploy` (Deployer, see `backend/deploy.php`).

### Key architecture notes

- **Two Filament panels**: `app/Filament/Admin/` (SaaS operator admin) and `app/Filament/Dashboard/` (tenant/customer dashboard). Prefer Filament resources and Livewire components over custom controllers.
- **Multi-tenancy** is core — the domain is organized around `Tenant` plus subscriptions, plans, products, discounts, referrals, and orders. Tenant logic lives in `app/Services/` (e.g. `TenantService`, `TenantSubscriptionService`, `TenantPermissionService`).
- **Payments** are provider-abstracted under `app/Services/PaymentProviders/`, driven by webhooks registered in `routes/api.php` (Stripe, Paddle, LemonSqueezy, Polar, Creem). `routes/web.php` is Livewire/Filament UI; `routes/api.php` is almost entirely webhooks.
- **Domain flow is event-driven**: `app/Events/` (Order, Subscription, Tenant, Referral, User) dispatched to `app/Listeners/`.
- Business logic belongs in **Services**, not controllers or models. Use `php artisan make:*` to scaffold; use factories for test data.

### Version note

`backend/composer.json` is the source of truth and pins **Laravel 13, PHP 8.4, Filament 5, Livewire 4, Larastan 3**. Some prose in `backend/AGENTS.md` still references older versions (Laravel 12 / Filament 4 / PHP 8.2) — trust `composer.json`.
