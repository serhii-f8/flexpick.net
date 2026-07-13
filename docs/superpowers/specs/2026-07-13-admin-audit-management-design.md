# Admin Audit Management & Mailcoach Email Platform — Design

**Date:** 2026-07-13
**Status:** Approved design, pending implementation plans
**Scope:** Spec 4 of 4 from the 2026-07-13 feature sprint decomposition. Two bounded workstreams that become **two implementation plans**: (A) admin audit management, (B) Mailcoach email platform. Each ships and tests independently; A's "email failures" widget stat reads the table created in B and renders 0 until B lands.
**Builds on:** the admin `AuditRequestResource` (list/view + retry/launch/unlock/mark-handled actions), `AuditPipeline`, `ClaudeAnalyzer`, `ConfigService`-backed settings pages, and the audit mailables in `app/Mail/Audit/`.

## Problem

Admins can view audit requests and re-trigger runs, but cannot edit audit input data or status, cannot influence the analysis prompt (hardcoded in `ClaudeAnalyzer::buildPrompt()`), cannot correct generated results, have no processing logs or timing, no audit statistics on the admin dashboard, and no visibility into the 10 audit email types sent fire-and-forget via `Mail::`.

## Decisions made during brainstorming

| Question | Decision |
|---|---|
| Prompt management | Global prompt template on a new Audit Settings page (blank = built-in default) + per-audit `admin_context` appended to the prompt |
| Email tracking | Standalone Mailcoach 8.1 as a separate service + Filament API bridge; local `audit_email_logs` mirror table keeps the admin UX native |
| Mailcoach 9 core package | Rejected — requires Laravel ≤12 / Livewire 3 / Filament 4, incompatible with this backend (Laravel 13 / Livewire 4 / Filament 5) |
| Mailcoach unreachable | `AuditMailer` falls back to direct `Mail::` send and logs the fallback — audit email never silently stops |
| Single sign-on into Mailcoach | Deferred — admin user-menu external link; Mailcoach keeps its own login with a seeded admin account |
| Results override | Edit `AuditReport.payload` as validated JSON via a view-page action |
| "Search by company" | Maps to the requester's tenant name (shown on view page, searchable through the user relation) — audits have no company column |

---

# Workstream A — Admin audit management

## A1. Audit resource upgrades

`app/Filament/Admin/Resources/AuditRequests/` gains an **Edit page** (`canEdit` becomes true):

- Editable: `status` (select over all `AuditRequestStatus` values), `repo_url`, `name`, `email`, `message`, and the new `admin_context` (A2).
- List search expands to `name`, `email`, `repo_url`; filters: status (multi-select), submitted date range — satisfying "search by user, project, URL, email, status, submission date".
- View page additions: requester's tenant name(s) ("company"), the exact prompt the next run will use (A2 preview), pipeline log and timing (A3), and the results-override action (A3).
- Existing actions (retry, launch, grant unlock, mark handled) unchanged.

## A2. Prompt template and per-audit context

- **Audit Settings** admin page (`app/Filament/Admin/Pages/AuditSettings.php`), persisting via `ConfigService` like `GeneralSettings`:
  - `audit.prompt_template` — textarea. Blank = use the built-in default; the page displays the built-in text as reference. The template must contain the placeholders the builder injects (`{metrics}`, `{excerpts}`); saving validates their presence.
- New nullable `admin_context` text column on `audit_requests` (migration), editable on the Edit page.
- `ClaudeAnalyzer::buildPrompt()` refactors to: resolve template (setting override or built-in) → substitute metrics/excerpts blocks → append `admin_context` when present. A `promptFor(AuditRequest $request): string` method exposes the exact composed prompt for the view-page preview.

## A3. Results override, processing logs, timing

- **Edit results** action on the view page: modal with the `AuditReport.payload` as pretty-printed JSON. Validation: must decode to an array and contain `scores.overall`. Saving updates the payload; the hosted web report reads payload live, so no regeneration is needed. PDF stays stale until a re-run — noted in the modal help text.
- New `pipeline_log` JSON column on `audit_requests`: `AuditPipeline` appends `{step, message, at}` entries at each stage boundary (analysis started, clone finished, metrics collected, AI analysis finished, report stored, failure with message). `AuditRequestService::markFailed()` also appends. Rendered as a timeline on the view page — "errors and processing logs".
- New nullable `analysis_started_at` / `analysis_completed_at` timestamps on `audit_requests`, set by the pipeline at run start / report completion → powers average processing time (A4).

## A4. Admin dashboard widgets

On the admin `Dashboard` page (alongside `MetricsOverview`), plus one stat surfaced on the audits list:

- **`AuditAdminStatsWidget`** (stats overview): total audits; submitted today / this week / this month; pending (`new`+`queued`+`pending_verification`); analyzing; completed (`report_ready`+`sent`); failed; requiring manual action (`needs_followup`+`awaiting_access`+`awaiting_payment`); average processing time (mean of `analysis_completed_at - analysis_started_at`, dash when no data); email failures (count of `failed` rows in `audit_email_logs` — renders 0 until Workstream B lands).
- **`AuditsByPlanWidget`**: this month's audits grouped by the requester's active plan (Audit Starter / Growth / Scale / free) — "usage grouped by product or plan". Free = requester has no active audit-plan subscription.
- **Queue status**: audit queue depth (Redis `llen` on the configured `audit` queue via the Horizon/queue connection) as a stat tile linking to `/horizon` — "current analysis queue or processing status".

---

# Workstream B — Mailcoach email platform

## B1. Standalone Mailcoach service

- `backend/spatie-Mailcoach-8.1.0.zip` (standalone Laravel 12 app skeleton pulling `spatie/laravel-mailcoach ^8.13` from `satis.spatie.be`) unpacks into a new top-level **`mailcoach/`** directory — the monorepo's third app with its own composer stack, so backend framework versions are irrelevant.
- New `mailcoach` service in the root `compose.yml`: PHP container serving on **`localhost:8090`**, reusing the existing MySQL container (own `mailcoach` database, created via the same init-script mechanism as the testing DB) and Redis (separate DB index), plus its own Horizon queue worker process.
- **License handling:** `satis.spatie.be` composer credentials (Spatie account email + the provided license key) live in `mailcoach/auth.json`, which is **gitignored** — never committed. Setup documented in `mailcoach/README.md`; the key is supplied out-of-band.
- Sending: dev via the existing Mailpit SMTP (statuses cap at "sent"); production via an ESP (SES/Postmark/Resend/Mailgun) configured inside Mailcoach — that is when *delivered/bounced* statuses become real. The Filament bridge treats missing delivery data gracefully (status stays "sent").
- A seeded Mailcoach admin account is created during setup (documented credentials via env).

## B2. Backend sends audit email through Mailcoach

- Backend requires `spatie/mailcoach-sdk-php` (public Packagist, framework-agnostic HTTP client — Laravel-13-safe). Config: `MAILCOACH_API_TOKEN`, `MAILCOACH_ENDPOINT` in `backend/.env` (`services.mailcoach.*`).
- New **`AuditMailer`** service: single entry point for all audit email. For each of the 10 mailables in `app/Mail/Audit/`, it renders subject + HTML from the mailable, sends via Mailcoach's transactional API, and records a row in the new **`audit_email_logs`** table:

| Column | Purpose |
|---|---|
| `audit_request_id` | FK to the audit request the email concerns (set for all 10 mailables, including the admin notification); nullable for future non-request email |
| `mailable` | class basename, e.g. `AuditReportReady` — "notification type" |
| `recipient` | email address |
| `mailcoach_uuid` | transactional mail UUID from the API, nullable (null = fallback send) |
| `status` | `pending` → `sent` / `failed`; `delivered`/`bounced` when the API reports it |
| `attempts` | increments on every send/resend |
| `last_error` | latest exception/API error message, nullable |
| `sent_at` | last attempt timestamp |

- **Fallback:** if the Mailcoach API call fails (connection/4xx/5xx), `AuditMailer` sends the mailable directly via `Mail::` and logs the row with `mailcoach_uuid = null` and the error noted — audit email never silently stops.
- All `Mail::to(...)->send(new Audit*)` call sites in `AuditRequestService` and `AuditReportService` switch to `AuditMailer`. Non-audit mail (orders, invitations, subscriptions) is untouched.

## B3. Filament bridge — manage from the FlexPick admin panel

- **Audit Emails** admin resource over `audit_email_logs`: columns — recipient, notification type, status badge, attempts, last attempt, latest error (truncated, full on view). Filters: status, type; search: recipient. Read-only rows (no edit page).
- **Resend** action per row: confirmation modal stating when the email was last sent and to whom ("duplicate-send safeguard"), then: rows with a `mailcoach_uuid` resend via the Mailcoach API; fallback rows re-dispatch through `AuditMailer`. Attempts increment; status resets to `pending` then updates.
- **Refresh statuses** header action: pulls current transactional statuses from the Mailcoach API for the visible page of rows and updates `status` (sent → delivered/bounced where the ESP reported it).
- Admin user-menu external link **"Open Mailcoach"** → the Mailcoach app URL. True SSO deferred (documented decision).

---

# Testing

**Workstream A:**
- Edit page: status/input/context changes persist; non-admins cannot access (existing admin gate).
- Prompt composition unit tests: built-in default when template blank; template override applied; `admin_context` appended; placeholder validation on the settings page.
- Results override: invalid JSON rejected; missing `scores.overall` rejected; valid payload saved and served by the hosted report.
- Pipeline log/timing: a full pipeline run appends the expected step sequence and sets both timestamps; a failed run appends the failure entry.
- Widgets: counts, averages, and by-plan grouping asserted against seeded audits (Spec 1 demo data where convenient); queue-depth stat mocked at the Redis level.

**Workstream B:**
- `AuditMailer` with a faked Mailcoach HTTP API (`Http::fake`): successful send creates a `sent` log row with UUID; API failure falls back to `Mail::` (asserted via `Mail::fake`) and logs the fallback; each call site sends the right mailable.
- Resend: API resend called for UUID rows, re-dispatch for fallback rows, attempts increment, confirmation required.
- Status refresh: API statuses map onto log rows correctly, unknown/missing data leaves `sent` untouched.
- The Mailcoach app itself is vendor software — not unit-tested here; container boot, login, and end-to-end send are verified in the manual pass.

# Out of scope

- True single sign-on into Mailcoach.
- Marketing campaigns / newsletter flows in Mailcoach (future project; the service being stood up is a prerequisite).
- Provider webhook ingestion into the backend (delivery data flows through Mailcoach's own ESP integration and is read via its API).
- PDF regeneration after a results override (stale until re-run; noted in the UI).
