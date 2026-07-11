# Audit Intake + Automated Repository Health Report

**Date:** 2026-07-11
**Status:** Approved
**Scope:** Plan 2 of 2 (builds on `2026-07-11-monorepo-split-docker-design.md`)

## Goal

The landing page promises: *"You receive a health report with risks ranked by impact and a
concrete plan of what to fix first."* This plan delivers that end-to-end, fully automated:

1. The landing's audit-request modal submits to a real backend endpoint.
2. A queued pipeline clones the submitted repository, collects metrics, has Claude produce
   a structured analysis, renders a PDF health report, and emails it to the client with a
   signed link to a web copy — with **no human gate**.
3. Requests that can't be auto-analyzed (private/missing/oversized repo) fall back to an
   automated "give us access" email and are flagged for follow-up.

All new code lives in `backend/` except the small fetch wiring in the landing modal.

## Guardrails (non-negotiable)

- **Cloned code is never executed.** No `composer install`, no `npm install`, no running
  repo scripts. Static inspection only.
- Shallow clone (`git clone --depth 1`), hard limits: clone timeout (120 s), max repo size
  (500 MB), max analyzed files sent to the AI (~50 excerpts). Clone dir is a per-request
  temp directory deleted in a `finally` step even on failure.
- Because the report is sent unreviewed, the template is conservative: every score and
  finding is derived from collected metrics; AI prose is framed as assessment, not
  guarantees, and the email invites a reply to discuss.

## Data model

**`audit_requests`** — one row per contact-form submission.
- `id`, `uuid`, `name`, `email`, `repo_url` (nullable), `message` (nullable)
- `status` enum: `new → queued → analyzing → report_ready → sent | failed | needs_followup`
- `failure_reason` (nullable string), `meta` JSON (IP, user agent), timestamps.

**`audit_reports`** — one row per generated report.
- `id`, `uuid` (public identifier in URLs), `audit_request_id` FK
- `payload` JSON — the structured analysis (see AI contract below)
- `pdf_path` (on the `local` disk under `storage/app/audit-reports/`)
- `user_id` nullable FK — linked when a user with a matching email exists at generation
  time, or on later registration (listener on the existing `User` registered event).
- timestamps.

Models: `AuditRequest`, `AuditReport` with factories. Business logic in services per repo
convention: `app/Services/AuditRequestService`, `app/Services/AuditReport/…` (pipeline
stages below).

## Component 1 — Intake endpoint

`POST /api/audit-requests` in `backend/routes/api.php` (alongside the webhooks).

- **Validation** (FormRequest): `name` required ≤120; `email` required valid email;
  `repo_url` nullable URL; `message` nullable ≤2000; honeypot field `website` must be empty.
- **Abuse controls:** `throttle:5,1` per IP + per-email dedupe (reject if same email
  submitted < 10 min ago with 429/422).
- **CORS:** allow origins `https://flexpick.net` and `http://localhost:4321` for this route
  (Laravel CORS config), since the static site posts cross-origin.
- **Behavior:** create `AuditRequest` (`status=new`), send the client a confirmation
  Mailable ("request received, report usually within the hour"), notify the team
  (`MAIL_ADMIN` address), then:
  - `repo_url` present → dispatch `GenerateAuditReport` job chain (`status=queued`);
  - no `repo_url` → send the automated "share repo access" email, `status=needs_followup`.
- **Response:** `201 {"id": "<uuid>"}`. Errors: standard 422 validation shape.

**Frontend wiring:** `ContactModal.astro` replaces its localStorage stub with a `fetch`
POST to `{productApp.url}/api/audit-requests`, mapping the existing fields
(`link` → `repo_url`). Success → existing "sent" state; failure → inline error message
with retry, form data preserved.

## Component 2 — Analysis pipeline

A chained queue job (Horizon, dedicated `audit` queue) with stages as invokable service
classes under `app/Services/AuditReport/`. Status transitions on the request row at each
stage; any stage failure sets `failed` + `failure_reason`, notifies the team, and emails
the client a soft "we hit a snag, we'll follow up personally" message. Retries: 2 per
stage for transient errors (network, AI API); none for deterministic failures (repo not
found).

**Stage 1 — `CloneRepository`.** Validates the URL resolves to a clonable public git repo
(pre-flight `git ls-remote` with timeout). Private/nonexistent → `needs_followup` + the
"share access" email (not `failed` — it's a lead, not an error). Clones shallow into
`storage/app/audit-workdirs/{uuid}` honoring the guardrail limits.

**Stage 2 — `CollectMetrics`.** Pure-PHP/CLI static collection into a metrics array:
- language & LOC breakdown, file count, largest files (top 20 by LOC)
- duplication heuristic (normalized line-hash comparison across files)
- test presence (test dirs/files ratio), CI config presence, README/docs presence
- dependency manifests (composer.json / package.json etc.): counts + lockfile presence
- secret-pattern scan (regexes for common keys/tokens) — reported as counts + file paths,
  never the matched values
- git signals available at depth 1 (default branch, last-commit age)

**Stage 3 — `AnalyzeWithClaude`.** One Claude API call (model configured via
`config/services.php`, default `claude-opus-4-8`, overridable via `AUDIT_AI_MODEL`;
key in `.env` `ANTHROPIC_API_KEY`).
Input: metrics digest + up to ~50 short excerpts of the highest-signal files (largest,
most duplicated, entry points). Output: **structured JSON enforced via a tool/JSON schema**:

```json
{
  "summary": "...",
  "scores": {"structure": 0-100, "duplication": ..., "testing": ...,
              "dependencies": ..., "security_hygiene": ..., "overall": ...},
  "risks": [{"title", "impact": "high|medium|low", "evidence", "recommendation"}],
  "fix_first_plan": [{"step", "why", "effort": "S|M|L"}]
}
```

Response is validated against the schema before persisting to `audit_reports.payload`;
invalid → retry once with a corrective prompt, then fail the stage. The HTTP client is
wrapped in an `AiAnalyzer` interface so tests fake it.

**Stage 4 — `RenderAndSendReport`.** Blade view → PDF via `barryvdh/laravel-dompdf`
(pure PHP, no headless browser in the container). Saves PDF, creates `AuditReport`
(`status=report_ready`), deletes the clone workdir, then emails the client the report
Mailable: PDF attached + a **signed URL** (`URL::signedRoute`, 30-day expiry) to the web
copy. On successful send, `status=sent` — so a crash between render and send is visible
as `report_ready` and recoverable via the admin Retry action.

## Component 3 — Report web copy & dashboard

- `GET /reports/{uuid}` (web route, `signed` middleware): renders the same Blade layout as
  the PDF from `payload`. Expired/invalid signature → friendly 403 page suggesting a reply
  to the report email.
- Authenticated users see their linked reports: a simple Filament Dashboard-panel page
  listing `AuditReport`s where `user_id = auth user`, with PDF download and web-view links.

## Component 4 — Admin observability

Filament **Admin** panel resource for `AuditRequest` (relation to report): list with status
badges/filters, detail view (message, failure reason, payload), and two actions:
**Retry pipeline** (re-dispatch chain) and **Mark handled** (for `needs_followup`).
Read-mostly; no report editing (the flow is fully automated by decision).

## Config & env

`config/audit.php`: queue name, clone limits, excerpt limits, model name, signed-URL TTL.
New env: `ANTHROPIC_API_KEY`. Requires `git` in the app container (present in Sail images).

## Testing

- **Feature:** endpoint validation/throttle/honeypot/CORS; 201 creates request + dispatches
  chain; no-repo path sends access email and sets `needs_followup`; signed report route
  (valid/expired); dashboard listing scoped to owner.
- **Unit:** each stage against fixture repos (tiny git repos in `tests/Fixtures`), fake
  `AiAnalyzer` returning canned/invalid JSON (schema-retry path), Mailable assertions
  (`Mail::fake`), workdir cleanup on failure.
- **Manual (Docker):** submit from `localhost:4321` modal with a small public repo →
  watch Horizon → receive report email in Mailpit with PDF and working signed link.
