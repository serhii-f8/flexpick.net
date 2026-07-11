# Audit Intake Rework: Verification, Invite Flow & Freemium Monetization — Design

**Date:** 2026-07-11
**Status:** Approved design, pending implementation plan
**Builds on:** `2026-07-11-audit-report-pipeline-design.md` (the pipeline this reworks is complete on branch `new-goals`)

## Problem

The current audit intake auto-runs the full pipeline for any submitted public repo URL, with no email verification, no consent capture, no way to audit private repos, and no monetization. This design adds:

1. **Email verification gate** — nothing runs until the requester confirms their email.
2. **Private-repo invite flow** — requesters invite a dedicated GitHub account; reports are launched manually from the admin panel.
3. **Marketing consent** — an optional opt-in checkbox on the form.
4. **Freemium model** — 3 free basic reports per verified email (lifetime); detailed reports unlocked via a $5 one-time purchase or a subscription (5/$10, 20/$30, 50/$60 per month).
5. **Growth extras** — sample report on the landing page, benchmark percentile, re-audit trends for subscribers, referral bonus (+1 free analysis).

## Decisions made during brainstorming

| Question | Decision |
|---|---|
| Invite flow scope | Manual launch for private repos; public repos still auto-run after verification |
| Invite identity | Dedicated GitHub account `flexpick-audit`; GitHub-only instructions, other hosts fall back to "email us" |
| Verification gate position | Before anything runs — no pipeline, no quota use, no admin notification until verified |
| Consent checkbox | Optional marketing opt-in; report delivery is transactional and unaffected |
| Pricing curve | $10/5, $30/20, $60/50 per month ($2.00 → $1.50 → $1.20 per analysis); $5 one-time unlock |
| Free quota scope | 3 per verified email, lifetime |
| Purchase flow | Registration required; existing SaaSykit checkout (OneTimeProduct + Plans with usage metering) |
| Report delivery | Hosted web report page with locked sections; PDF becomes a paid perk |
| $5 product scope | Unlocks one *existing* report only — does not buy a 4th analysis run (see Deliberate simplifications) |

## 1. Intake flow

### Form changes (frontend `ContactModal.astro`)

- Existing fields kept: name, email, repo URL (optional), message, honeypot.
- **New:** optional checkbox *"Send me occasional tips and product updates"* → `marketing_consent`.
- **New copy:** info line — *"We'll send a confirmation link — you must confirm your email to receive your free report. Your first 3 audits are free."*
- **New copy:** collapsible hint near the repo field — *"Private repo? Invite `flexpick-audit` on GitHub as a read-only collaborator, then paste the repo URL here."*

### Request lifecycle

New statuses added to `AuditRequestStatus`: `PENDING_VERIFICATION`, `AWAITING_ACCESS`, `AWAITING_PAYMENT`.

```
submit → PENDING_VERIFICATION ──(verification link clicked)──┐
                                                             ▼
                             preflight git ls-remote on repo_url
                             ├─ reachable (public) + free quota → QUEUED → auto-run
                             ├─ reachable, quota exhausted      → AWAITING_PAYMENT (email: subscription options)
                             ├─ unreachable (private)           → AWAITING_ACCESS (email: invite instructions)
                             └─ no repo_url                     → NEEDS_FOLLOWUP (as today)
```

- **Verification link:** signed URL, 48-hour expiry, idempotent. No token column. Until clicked: no admin notification, no pipeline run, no quota consumption.
- **Purge:** unverified requests deleted after 7 days (scheduled command).
- **`AWAITING_ACCESS`:** launched manually from Filament via a "Launch report" action, once the admin has accepted the GitHub collaborator invite.
- **Private cloning:** PAT of the `flexpick-audit` account (`audit.github_token` config), injected into clone URLs for `github.com` hosts only. Existing credential redaction in error messages covers the token.

### Data model — `audit_requests`

| Column | Type | Purpose |
|---|---|---|
| `email_verified_at` | nullable timestamp | verification gate |
| `marketing_consent` | boolean, default false | opt-in flag |
| `consented_at` | nullable timestamp | when consent was given (IP already in `meta`) |
| `free_run` | boolean, default false | set when a run consumes free quota; retries never double-count |

**Quota rule:** free runs used = count of `free_run = true` requests for that email. Limit is 3 (config), plus `bonus_free_audits` for registered users (see Referral).

## 2. Monetization & entitlements

### Products (existing SaaSykit rails, seeded via admin/seeders — no new billing code)

| Offer | Mechanism | Price |
|---|---|---|
| Full-report unlock (one report) | `OneTimeProduct` | $5 one-time |
| Starter — 5 analyses/mo | `Plan` + usage meter | $10/mo |
| Growth — 20 analyses/mo | `Plan` + usage meter | $30/mo |
| Scale — 50 analyses/mo | `Plan` + usage meter | $60/mo |

### $5 unlock flow

Report page "Unlock full report" → register/login (email prefilled, already verified) → SaaSykit checkout with report UUID in order metadata → `OrderCompleted` listener sets `unlocked_at` + `unlock_order_id` on the report → unlock-confirmation email. Instant: the full analysis is always stored; unlock is pure rendering entitlement.

### Subscription flow

Subscribers launch new audits (and re-audits) from the dashboard. Each run consumes 1 metered unit for the month and produces a fully unlocked report. Dashboard runs skip the email-verification gate (account is trusted).

### Entitlement model

One field decides rendering: **`audit_reports.unlocked_at`**.

- Free run → null → basic rendering (numbers + issue titles; descriptions locked)
- $5 purchase → set by the `OrderCompleted` listener
- Subscription-metered run → set at report creation
- Admin comp → set via Filament "Grant unlock" action

The report view never queries subscriptions or orders — entitlement is resolved once, at write time.

### Data model — `audit_reports`

| Column | Type | Purpose |
|---|---|---|
| `unlocked_at` | nullable timestamp | single source of entitlement truth |
| `unlock_order_id` | nullable FK to orders | audit trail for purchases |

### Deliberate simplifications

- The $5 product unlocks an existing report only; it does **not** buy a 4th analysis run. Post-quota users subscribe (cancel-after-one-month ≈ "$10 for 5 more"). A general "audit credit" (usable to unlock *or* run) would need a custom credits ledger alongside SaaSykit metering — deferred until the funnel shows demand.

## 3. Report page & growth extras

### Web report page

New Blade route `GET /reports/{uuid}` (signed URL; replaces the PDF link in the report-ready email; also reachable from the user dashboard for linked accounts).

- **Header:** repo name, audit date, health score, benchmark percentile line.
- **Metrics grid:** basic numbers (files, LOC, duplication, secret findings, …) — always visible.
- **Issues list:** severity badge + title always visible; description/recommendation blurred with a lock overlay when locked, with "Unlock full report — $5" and "or subscribe from $10/mo" CTAs.
- **Unlocked:** everything visible + "Download PDF" button. PDF is generated/kept only for unlocked reports.

### Sample report

Static demo payload rendered through the same Blade view at `/reports/sample` (unlocked, "SAMPLE" banner). Linked from the landing page. Shared template = zero drift.

### Benchmark percentile

Computed from health scores across completed reports; cached 1 hour. Hidden below a minimum sample size (config, default ~20 audits) so early percentiles don't look fake.

### Re-audit trends (subscribers)

Dashboard reports page groups reports by normalized repo URL, shows score-history sparkline, offers "Re-run audit" (consumes 1 metered unit).

### Referral bonus

SaaSykit's existing referral reward event → listener increments `bonus_free_audits` on the referrer's `users` row. Applies only to registered users; anonymous emails stay at flat 3.

## 4. Emails

| Mailable | Status | Content |
|---|---|---|
| Verification link | **new** | signed confirm URL, 48h expiry note |
| Free quota exhausted | **new** | `AWAITING_PAYMENT` — subscription options (no report exists yet, so the $5 unlock doesn't apply) |
| Unlock confirmation | **new** | full report is ready, link |
| Invite instructions | rewritten | GitHub collaborator steps for `flexpick-audit` |
| Report ready | rewritten | links to web report page instead of PDF |
| Request received / failed / admin notification | unchanged | — |

## 5. Filament admin

- AuditRequest resource: new statuses, verification + consent columns/filters, "Launch report" action on `AWAITING_ACCESS` (and retry, as today).
- AuditReport: "Grant unlock" action.
- New config: `audit.github_account` (shown in copy), `audit.github_token` (PAT), `audit.free_reports_limit`, `audit.benchmark_min_sample`.

## 6. Testing

Feature tests per gate, on top of the existing suite (427 passing, must stay green):

- Verification: unverified request runs nothing, consumes no quota, notifies no admin; expired/invalid signature rejected; verify is idempotent.
- Quota: only `free_run = true` counts; 4th verified request → `AWAITING_PAYMENT`; bonus audits extend the limit.
- Routing: public repo auto-runs; private repo → `AWAITING_ACCESS`; no URL → `NEEDS_FOLLOWUP`.
- Cloning: PAT injected for github.com only; token never appears in failure reasons.
- Unlock: `OrderCompleted` with report UUID metadata sets `unlocked_at` exactly once; wrong/missing metadata is a no-op.
- Rendering: locked vs unlocked sections; PDF route denied for locked reports; sample page renders.
- Admin: launch action transitions `AWAITING_ACCESS → QUEUED` and dispatches the job.
- Referral: reward event increments `bonus_free_audits`.

## Future work (out of scope)

- General "audit credit" purchasable à la carte (unlock or run).
- Marketing drip sequence for consented emails (consent is captured now; sending is later).
- GitLab/Bitbucket invite instructions.
- GitHub App integration (auto-detect accepted invites, PR-diff audits).
- Shareable health-score README badge.
