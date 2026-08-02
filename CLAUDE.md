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

## Docker dev environment

One command boots both apps (requires Docker Compose >= 2.20):

```bash
cp backend/.env.example backend/.env   # first time only; ensure APP_PORT=8080, WWWUSER/WWWGROUP set
docker compose up -d                   # backend :8080, frontend :4321, Mailpit UI :8025
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test npm install && docker compose exec laravel.test npm run build
```

- The backend needs its Vite assets built at least once (`npm run build` above) or `/pricing` and other pages 500 with "Vite manifest not found". For live asset editing use HMR instead (next bullet).
- Backend Vite HMR (optional, replaces the one-off `npm run build` above): `docker compose exec laravel.test npm install && docker compose exec laravel.test npm run dev`
- Queue worker (needed for emails/report pipeline): `docker compose exec laravel.test php artisan horizon`
- ngrok (webhook testing, needs `NGROK_AUTHTOKEN` in `backend/.env`): `docker compose --profile ngrok up -d`
- Frontend-only or backend-only workflows still work from each app directory as before.
- If `FORWARD_DB_PORT` (3306), `FORWARD_REDIS_PORT` (6379), or `FORWARD_MAILPIT_PORT`/`FORWARD_MAILPIT_DASHBOARD_PORT` (1025/8025) collide with another project already running on your machine, override them in `backend/.env` before `docker compose up`.
- The `frontend` service runs as `WWWUSER:WWWGROUP` (default `1000:1000`) so `npm install` inside the container doesn't leave root-owned files in the bind-mounted `frontend/node_modules`. If your host UID differs, export `WWWUSER`/`WWWGROUP` before `docker compose up` (same variables the backend already uses).

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
vendor/bin/pint --dirty --format agent           # format only changed files (run before finalizing)
```

Deploy: `php dep deploy` (Deployer, see `backend/deploy.php`).

The suite is **PHPUnit** (`^11`, classic `TestCase`-based classes under `backend/tests/`; there is no `Pest.php`). The Filament/Livewire test snippets in `backend/AGENTS.md` are written in Pest syntax from the Boost guidelines — translate them to PHPUnit before use, and create tests with `php artisan make:test --phpunit {name}`.

**Domain skills**: `backend/.claude/skills/` holds curated skills (`laravel-best-practices`, `livewire-development`, `configuring-horizon`, `socialite-development`, `tailwindcss-development`, `debug-using-debugbar`). Per the Boost guidelines in `AGENTS.md`, activate the relevant one when working in its domain.

### The Audit pipeline (the product built on top of the boilerplate)

SaaSykit is the *boilerplate*; the actual product is a **code-audit service** layered on top — the backend counterpart to the frontend's "vibecode rescue" landing. A visitor submits a `repo_url` (`AuditRequest`, UUID-keyed, claimable by email before signup via `scopeForUser`); a pipeline clones and analyzes the repo and emails back a gated report. Almost all of this lives in `app/Services/AuditReport/`, orchestrated by `AuditPipeline::run()`, whose stages are:

1. `RepositoryCloner` — `preflight()` then `clone()` into a temp path (cleaned up in `finally`).
2. `MetricsCollector::collect()` — returns `metrics` + code `excerpts`; runs `DependencyAuditor` internally.
3. `ScoreCalculator::calculate($metrics)` — computed scores, stored on the request *before* the AI step.
4. `AiAnalyzer::analyze($metrics, $excerpts, $adminContext)` — `AiAnalyzer` is an **interface**; `ClaudeAnalyzer` is the impl (bound in `AppServiceProvider`), and it composes prompts via `PromptComposer` and returns a `ReportPayload`-shaped array.
5. `AuditReportService::create()` then `send()` — persists the `AuditReport` and emails it.

Failures throw `AuditNotAnalyzableException` → `AuditRequestService::markNeedsFollowup()`. Note `AuditDeltaService` / `AuditBenchmarkService` are **not** part of this run — they're applied at report *view* time in `AuditReportController`.

- Access is gated by `AuditEntitlementService`: a free-run quota (`config('audit.free_reports_limit')` + per-user `audit_bonus_free_runs` bonus) and a subscription allowance (`audit_analyses_per_month` from the plan's product metadata, metered per calendar month for `source = dashboard` runs).
- Delivery via `app/Services/AuditMail/` (`AuditMailer`, which logs every message to `AuditEmailLog` and sends directly through the framework mailer); the acquisition funnel is tracked through `AuditFunnelRecorder` / `AuditFunnelEvent`.
- Models: `AuditRequest`, `AuditReport`, `AuditEmailLog`, `AuditFunnelEvent`, `AuditSchedule`.
- Scheduled in `routes/console.php`: `app:run-scheduled-audits` (daily, `withoutOverlapping()->onOneServer()`), `app:purge-unverified-audit-requests`, `app:send-audit-verification-reminders`, `app:send-audit-unlock-reminders` — these need Horizon running. When touching the audit flow, trace it here rather than through the generic subscription/order services.

### Observability (Phase 9A-1)

`spatie/laravel-health` owns check execution and result storage; alert dispatch is ours
(`app:health-alerts`, scheduled every five minutes in `routes/console.php`) because Spatie's
built-in notifications cannot satisfy three requirements at once: a throttle that fails
*open* (sending, not suppressing, when the cache is down), band-aware messages, and
guaranteed recovery notifications.

- Checks: `app/Health/Checks/` (three custom — `OldestPendingAuditCheck`,
  `AuditPipelineFailureRateCheck`, `MailFailureRateCheck`) plus Spatie built-ins, registered in
  `app/Providers/HealthServiceProvider.php`.
- Thresholds and severity bands: the `flexpick` block of `config/health.php`. Every check
  must have a band — a test enforces this. Only `critical`/`high` bands page (`paging_bands`);
  `medium` (the default) is reported in the body and alerted in-app only.
- Alerting: three notification channel classes under `app/Notifications/Channels/` —
  `MailAlertChannel`, `TelegramChannel`, `SlackWebhookChannel` — plus the `OperationsAlert`
  notification. `MailAlertChannel` is a custom self-guarding channel, **not** Laravel's built-in
  `mail` channel: the built-in rethrows on failure, which would kill the remaining channels and
  every subsequent check's alert.
- Endpoints: `/up` liveness, `/health/ready` readiness (never calls an external dependency),
  `/health` token-guarded monitoring. `/health` returns 503 when a **critical- or high-band**
  check is `failed`/`crashed`, or when results are stale — the stale arm is the dead-man's
  switch for a dead scheduler. Medium-band checks never affect the status code.
- `php artisan app:smoke` is the post-deploy gate (eight assertions; four are production-gated
  by design so it stays safe to run locally); its exit code is the contract.
- Error tracking is self-hosted Bugsink via the Sentry SDK. `app/Support/Sentry/TokenScrubber.php`
  is mandatory, not optional — see §15.1/§18.4. It scrubs message, extra, tags, exceptions,
  breadcrumbs, request, and contexts; the exceptions coverage matters because
  `captureException()` stores the message in `ExceptionDataBag::$value`, a field separate from
  `Event::getMessage()`.

### Key architecture notes

- **Two Filament panels**: `app/Filament/Admin/` (SaaS operator admin) and `app/Filament/Dashboard/` (tenant/customer dashboard). Prefer Filament resources and Livewire components over custom controllers.
- **Multi-tenancy** is core — the domain is organized around `Tenant` plus subscriptions, plans, products, discounts, referrals, and orders. Tenant logic lives in `app/Services/` (e.g. `TenantService`, `TenantSubscriptionService`, `TenantPermissionService`).
- **Payments** are provider-abstracted under `app/Services/PaymentProviders/`, driven by webhooks registered in `routes/api.php` (Stripe, Paddle, LemonSqueezy, Polar, Creem). `routes/web.php` is Livewire/Filament UI; `routes/api.php` is almost entirely webhooks.
- **Domain flow is event-driven**: `app/Events/` (Order, Subscription, Tenant, Referral, User) dispatched to `app/Listeners/`.
- Business logic belongs in **Services**, not controllers or models. Use `php artisan make:*` to scaffold; use factories for test data.

### Version note

`backend/composer.json` is the source of truth and pins **Laravel 13, PHP 8.4, Filament 5, Livewire 4, Larastan 3**. Some prose in `backend/AGENTS.md` still references older versions (Laravel 12 / Filament 4 / PHP 8.2) — trust `composer.json`.
