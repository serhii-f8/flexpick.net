# AGENTS.md

This file provides guidance to AI agents working in this repository (`flexpick.net`).

## Project Overview & Repository Layout

This repository is a **monorepo containing two independent applications** that are built, tested, and deployed separately:

- **`frontend/`** — Static marketing and landing page built with **Astro 6 + Tailwind CSS 4**. Positioned as a "vibecoded-codebase rescue" landing page. Output is static HTML (no server runtime).
- **`backend/`** — The SaaS product itself: **SaaSykit Tenancy**, a multi-tenant Laravel 13 SaaS boilerplate on the **TALL stack** (Tailwind CSS 4, Alpine.js 3, Laravel 13, Livewire 4) with **Filament 5** admin panel and user dashboard. The core product feature layered on top is an **Automated Code-Audit Service** (`AuditPipeline`).

Each sub-application has its own `package.json`, dependencies, configuration, and developer documentation:
- **`frontend/CLAUDE.md`** — Detailed Astro architecture, config system (`src/config.yaml`), component hierarchy, and blog system.
- **`backend/AGENTS.md`** — Comprehensive Laravel 13 architecture, Filament 5 guidelines, domain models, services, Boost/Herd rules, and coding standards.

> **Note**: Always `cd` into the relevant application directory (`frontend/` or `backend/`) before executing application-specific commands. There is no root task runner.

---

## Docker Development Environment

A single Docker Compose file at the root boots both frontend and backend services:

```bash
# Initial Setup (First time only)
cp backend/.env.example backend/.env   # Ensure APP_PORT=8080 and correct WWWUSER/WWWGROUP
docker compose up -d                   # Boots backend (:8080), frontend (:4321), Mailpit (:8025)

# Backend Initialization inside container
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test npm install && docker compose exec laravel.test npm run build
```

### Useful Docker Workflows:
- **Backend Vite HMR**: `docker compose exec laravel.test npm run dev`
- **Queue Worker (Horizon)**: `docker compose exec laravel.test php artisan horizon`
- **Webhook Testing (ngrok)**: `docker compose --profile ngrok up -d` (requires `NGROK_AUTHTOKEN` in `backend/.env`)

---

## Frontend (`frontend/`) — Quick Reference

- **Stack**: Astro 6, Tailwind CSS 4, TypeScript, Node.js >= 22.12.0
- **Commands** (run from `frontend/`):
  - `npm run dev` — Dev server at `http://localhost:4321`
  - `npm run build` — Production static build to `frontend/dist/`
  - `npm run check` — Astro check + ESLint + Prettier validation (CI gate)
  - `npm run fix` — Auto-fix ESLint and Prettier formatting
- **Key Concepts**:
  - `flexpick:config`: Site settings managed via `src/config.yaml` and loaded via a custom Vite plugin (`vendor/integration/`).
  - Path alias: `~/` maps to `src/`.
  - Blog content lives under `src/data/post/` with schemas defined in `src/content.config.ts`.

---

## Backend (`backend/`) — Quick Reference

- **Stack**: PHP 8.4, Laravel 13, Filament 5, Livewire 4, Alpine.js 3, Tailwind CSS 4
- **Commands** (run from `backend/`):
  - `composer install && npm install` — Install PHP & JS dependencies
  - `php artisan migrate:fresh --seed` — Reset & seed database with demo data
  - `php artisan serve` — Local PHP dev server
  - `npm run dev` — Asset compilation with HMR
  - `php artisan test --compact` — Run test suite (PHPUnit 11)
  - `php artisan test --filter=TestName` — Run specific test class or method
  - `vendor/bin/phpstan analyse` — Larastan static analysis
  - `vendor/bin/pint --dirty --format agent` — Auto-format modified PHP files before finishing
- **The Audit Pipeline (`app/Services/AuditReport/`)**:
  1. `RepositoryCloner` — Preflights and clones target repository into temp directory.
  2. `MetricsCollector` — Gathers codebase metrics and excerpts (includes `DependencyAuditor`).
  3. `ScoreCalculator` — Computes scores stored on the `AuditRequest`.
  4. `AiAnalyzer` — Analyzes repository via `ClaudeAnalyzer` (implements `AiAnalyzer` interface) with prompts constructed by `PromptComposer`.
  5. `AuditReportService` — Persists `AuditReport` and dispatches email via `AuditMailer`.
- **Panels & Routing**:
  - `app/Filament/Admin/` — SaaS operator administration.
  - `app/Filament/Dashboard/` — Customer/tenant dashboard.
  - `routes/web.php` — Livewire/Filament UI routes.
  - `routes/api.php` — Payment webhooks (Stripe, Paddle, LemonSqueezy, etc.).

---

## Agent Guidance & Rules

1. **Keep App Context Isolated**: `frontend/` and `backend/` are independent. Do not attempt to cross-import dependencies or blend build tools between them.
2. **Run Quality Verification**:
   - For backend changes, run `php artisan test --compact` and `vendor/bin/pint --dirty --format agent`.
   - For frontend changes, run `npm run check` (Astro check + ESLint + Prettier).
3. **Follow Architectural Conventions**:
   - Keep business logic inside **Service classes** (`app/Services/`), not in controllers or Eloquent models.
   - Use **PHPUnit** (`TestCase` classes under `tests/`) for backend automated tests. Translate any Pest snippet examples to PHPUnit syntax.
   - Use Filament Resources and Livewire components rather than custom routes/controllers wherever possible.
   - Maintain configuration in `src/config.yaml` for frontend properties instead of hardcoding text/metadata in Astro components.
