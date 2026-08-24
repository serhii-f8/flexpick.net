# FlexPick — Platform Specification

**Date:** 2026-07-27 (revised 2026-08-01)
**Status:** Specification for review
**Scope:** The complete FlexPick platform — marketing site, product application, automated code-audit pipeline, tiered audit products, freemium monetization, growth/retention loops, administrative tooling, and transactional email infrastructure.

**Revision 2026-08-01 — two owner decisions incorporated throughout:**

- **D1 — Standalone email platform removed.** The licensed transactional email service (Mailcoach) is dropped from the architecture: its license expired 2025-10-15, composer access to the private repository is closed, and the live path was never exercised. All audit email is sent directly from the product application through the framework mailer, keeping the single mailer entry point and the per-message email log. Delivery/open/click tracking moves to a deferred phase via an email-provider's event webhooks (§4.2, §5.8). The Mailcoach client code currently in the repository is scheduled for removal (§17, Phase 10).
- **D2 — Tiered audit products.** The single automated pipeline becomes tier 1 of three sellable products per the 2026-08 company pitch: **Automated Health Report ($49)** built on open-source scanners, **Deep AI Code Review ($199)** adding AI source review of the riskiest files, and **Expert Audit (from $999)** adding a human review stage with its own workflow status and operator tooling. The free funnel remains as a free diagnostic entry step. New §5.12; roadmap Phases 10–13; Appendix A pricing superseded.
**Supersedes in role (not in detail):** the seven feature-scoped design specs under `docs/superpowers/specs/` and `frontend/docs/superpowers/specs/`. Those remain the authoritative record of individual feature decisions; this document is the single overarching description of what the system is and how it must be structured.

## How to read this document

Requirements are marked so that confirmed obligations are never confused with proposals:

| Marker | Meaning |
| --- | --- |
| **[R]** | **Requirement.** Confirmed and binding. Derived from an approved design decision and reflected in the system's intended behavior. |
| **[REC]** | **Recommendation.** A technical proposal made by this document. Not yet a decision. Adopt, reject, or replace. |
| **[A]** | **Assumption.** Taken as true for the purposes of this specification. Must be confirmed before it is relied upon. |
| **[Q]** | **Open question.** Requires a decision from the product or engineering owner. Every one is restated in §19. |

Unmarked prose is descriptive context, not a requirement.

---

## 1. Executive Summary

### 1.1 Overview

FlexPick is a SaaS platform that audits codebases built primarily by AI coding agents and returns a prioritized, evidence-backed engineering health report. It operates as two public surfaces over one domain model: a static marketing site that captures audit requests, and a multi-tenant product application that runs the audits, gates the results behind a freemium entitlement model, and hosts the customer and operator dashboards.

The platform's positioning is *rescue*, not *replacement*: "Your AI-built product works. Let's keep it that way." **[R]** All product copy must remain pro-AI and non-shaming — vibe coding is framed as the correct decision to ship quickly, with engineering discipline as the natural next step.

### 1.2 Problem

Teams that shipped a working product with AI coding agents hit a predictable wall. The product functions, but:

- New features break existing features, and nobody can predict which ones.
- Velocity decays as the codebase accumulates duplication, dead abstractions, and untested paths.
- No one on the team has a defensible picture of where the real risk sits — only a vague sense that the foundation is soft.
- Traditional consulting audits are slow, expensive, and priced for enterprises. A founder who wants to know whether their codebase is salvageable has no cheap, fast, honest option.
- Generic static-analysis tools emit thousands of undifferentiated warnings. They answer "what is technically imperfect", not "what will hurt you first, and what should I fix on Monday".

### 1.3 Solution

A fully automated audit pipeline that a visitor can trigger in under a minute with nothing but a repository URL and an email address.

The system clones the repository read-only, measures it statically, computes deterministic health scores from those measurements, has a large language model produce a ranked risk narrative and a "fix first" plan grounded in the measured facts, and delivers a hosted report plus a PDF. Basic findings — scores, metrics, risk titles — are free. Detailed evidence and recommendations are unlocked by a small one-time payment or a subscription that also grants recurring audit runs and score trends over time.

**[R]** The pipeline must be fully automated end to end. No human review gate may sit between submission and delivery. Because reports are sent unreviewed, every score and finding must be traceable to a collected metric, and AI prose must be framed as assessment rather than guarantee.

### 1.4 Primary value proposition

> A specific, ranked, evidence-backed answer to "what is actually wrong with my AI-built codebase, and what do I fix first" — delivered in minutes, for free to start, with no sales call and no lock-in.

Differentiators the system must preserve:

| Differentiator | Requirement |
| --- | --- |
| **Speed** | **[R]** Zero human gate; report delivered by email within minutes of verification. |
| **Honesty** | **[R]** The platform must be willing to report that a codebase is healthy and that FlexPick cannot help. No fabricated urgency. |
| **Evidence** | **[R]** Scores are computed deterministically from measured metrics, not asserted by the model. Every risk carries an `evidence` field. |
| **Trust** | **[R]** Cloned code is never executed. Detected secrets are reported as counts and file paths only — never as matched values. |
| **Low commitment** | **[R]** Free tier requires no account and no payment method. Registration is deferred until the user chooses to pay. |

---

## 2. Project Goals

### 2.1 Business goals

| # | Goal | Requirement |
| --- | --- | --- |
| B1 | Convert marketing-site traffic into verified audit requests at a measurable rate. | **[R]** Every funnel stage must be instrumented (§8.7). |
| B2 | Convert free audit recipients into paying customers via a low-friction one-time purchase. | **[R]** The unlock path must not require an account before checkout (§5.4). |
| B3 | Convert one-time purchasers into recurring subscribers. | **[R]** Subscription tiers must be presented at every quota-exhaustion and unlock touchpoint. |
| B4 | Retain subscribers through recurring value rather than inertia. | **[R]** Score deltas and scheduled re-audits must be first-class features (§5.7). |
| B5 | Keep unit cost per audit predictable. | **[R]** Hard limits on clone size, clone depth, and AI input volume are mandatory (§6.1). |
| B6 | Establish a legally sound basis for marketing contact. | **[R]** Marketing consent must be captured as an explicit, optional opt-in, separate from transactional delivery. |

### 2.2 Product goals

- **P1 [R]** A visitor must be able to request an audit with no account, providing only name, email, and repository URL.
- **P2 [R]** Nothing consequential — no pipeline run, no quota consumption, no operator notification — may occur before the requester confirms ownership of their email address.
- **P3 [R]** A report must be substantively useful at the free tier. Locked content increases depth; it must not withhold the headline verdict.
- **P4 [R]** Private repositories must be supportable through an explicit, auditable access-grant flow.
- **P5 [R]** Requests that cannot be analyzed automatically must be captured as leads, not discarded as errors.
- **P6 [R]** Registered users must be able to see in-flight, failed, and completed audits, not only finished reports.
- **P7 [R]** Operators must be able to inspect, correct, re-run, and manually launch any audit, and to influence the analysis prompt without a deployment.
- **P8 [R]** The marketing site and the product application must be visually and narratively continuous.

### 2.3 Technical goals

- **T1 [R]** Cloned third-party code must never be executed. Static inspection only.
- **T2 [R]** Entitlement must be resolved once, at report-write time, and stored as a single field. Rendering must never re-derive entitlement from orders or subscriptions.
- **T3 [R]** Every long-running operation must be queued, retryable, idempotent, and observable.
- **T4 [R]** Business logic must live in service classes, not controllers, models, or Livewire components.
- **T5 [R]** Payment integration must remain provider-abstracted; no provider-specific logic may leak into audit or entitlement code.
- **T6 [R]** Tenant-scoped data must be unreachable across tenant boundaries through any query path.
- **T7 [R]** Transactional email must never silently stop. Every audit message goes out through one mailer entry point and produces a log row recording the outcome; a send failure is recorded with its reason, never swallowed.
- **T8 [R]** Secrets — API keys, repository access tokens, license credentials — must never be committed, logged, or echoed in user-facing error text.

### 2.4 Measurable success criteria

**[REC]** Targets are proposals. Baselines do not yet exist, so these should be treated as instrumentation requirements first and thresholds second. **[Q]** Confirm or replace every threshold below (§19).

| Metric | Instrumentation | Proposed target |
| --- | --- | --- |
| Submission → email verification | Funnel stages `submitted` → `verified` | **[REC]** ≥ 55% |
| Verification → report delivered | `verified` → `report_sent` | **[REC]** ≥ 90% |
| Report delivered → report viewed | `report_sent` → `report_viewed` | **[REC]** ≥ 60% |
| Report viewed → unlock checkout started | `report_viewed` → `unlock_started` | **[REC]** ≥ 8% |
| Unlock started → unlock paid | `unlock_started` → `unlock_paid` | **[REC]** ≥ 40% |
| Pipeline success rate | Terminal status distribution | **[R]** ≥ 95% of verified, reachable public repositories reach `sent` |
| Median time to report | `analysis_started_at` → report `created_at` | **[REC]** ≤ 5 minutes for repositories under 50k LOC |
| Free → paid conversion (any) | Distinct emails with any paid artifact | **[REC]** ≥ 5% |
| Subscriber month-2 retention | Active subscriptions surviving one renewal | **[REC]** ≥ 70% |
| Audit email delivery failures | `failed` rows in the email log | **[R]** < 1% of sends |

---

## 3. Target Users and Use Cases

### 3.1 Primary user types

| # | User | Context | Primary need |
| --- | --- | --- | --- |
| U1 | **Non-technical or semi-technical founder** | Shipped a product with AI agents; no senior engineer on staff. | An honest, plain-language verdict on whether the codebase is a liability, and what to do about it. |
| U2 | **Technical lead inheriting an AI-built codebase** | Joined or acquired a vibe-coded product. | A fast, objective baseline to prioritize stabilization and justify it to stakeholders. |
| U3 | **Agency or contractor** | Evaluating or maintaining client codebases. | Repeatable audits across many repositories, with trends over time. |
| U4 | **Recurring subscriber** | Already stabilizing; wants ongoing signal. | Scheduled re-audits and score movement, per repository. |
| U5 | **FlexPick operator (admin)** | Runs the platform. | Full visibility and control over every audit, prompt, result, and email. |

### 3.2 Needs and pain points

| User | Pain point | System response |
| --- | --- | --- |
| U1 | Cannot evaluate technical risk; fears being upsold. | **[R]** Free tier with no account; explicit willingness to report a healthy codebase. |
| U1 | Distrusts handing over source code. | **[R]** Read-only shallow clone, never executed, deleted after the run; secrets never echoed. |
| U2 | Needs prioritization, not a warning dump. | **[R]** Risks ranked by impact; a bounded `fix_first_plan` with effort sizing. |
| U2 | Needs to defend conclusions internally. | **[R]** Deterministic scores plus per-risk evidence; downloadable PDF. |
| U3 | Private repositories are the norm. | **[R]** Collaborator-invite flow with a dedicated audit identity. |
| U3 | Per-audit cost must scale down with volume. | **[R]** Tiered subscriptions with decreasing per-analysis price. |
| U4 | A one-time snapshot loses value immediately. | **[R]** Score deltas versus the previous audit; scheduled re-runs. |
| U5 | Model output is occasionally wrong or oddly framed. | **[R]** Validated results override; configurable prompt template; per-audit context. |
| U5 | Fire-and-forget email is invisible when it fails. | **[R]** Every audit email logged with status, attempts, error, and a resend action. |

### 3.3 Main user scenarios

- **S1 — Free public audit.** Anonymous visitor submits a public repository, verifies email, receives a partially locked report.
- **S2 — Quota exhausted.** A visitor who has used the free allowance is offered a prepaid single run or a subscription.
- **S3 — One-time unlock.** A report recipient pays to reveal full detail on that one report.
- **S4 — Private repository.** Requester grants read access to the dedicated audit identity; an operator launches the run manually.
- **S5 — Subscriber dashboard run.** An authenticated subscriber launches an audit from the dashboard against their monthly allowance; the report is unlocked on creation.
- **S6 — Scheduled re-audit.** A subscriber's saved schedule triggers a run; the report highlights movement since the last audit.
- **S7 — Referral bonus.** A registered user's successful referral grants an additional free run.
- **S8 — Unanalyzable submission.** Repository is missing, oversized, or unreachable; the requester receives guidance and the request becomes a tracked lead.
- **S9 — Operator intervention.** An operator edits inputs, adds context, previews the prompt, re-runs, corrects results, or grants an unlock.

### 3.4 End-to-end workflows

**W1 — Anonymous free audit (S1)** **[R]**

1. Visitor opens the audit modal on the marketing site and submits name, email, repository URL, optional message, and optional marketing consent.
2. The intake endpoint validates input, rejects bot submissions via honeypot, enforces per-IP throttling and per-email deduplication, and creates the request in `pending_verification`.
3. A verification email containing a signed, time-limited confirmation link is sent. Nothing else happens.
4. Visitor clicks the link. The request is marked verified exactly once (idempotent) and is redirected to a live status page.
5. Post-verification routing runs a lightweight reachability check on the repository and selects a branch: reachable and quota available → queued; reachable but quota exhausted → awaiting payment; unreachable → awaiting access; no repository URL → needs follow-up.
6. On the queued branch a job clones, measures, scores, analyzes, persists, and emails the report.
7. The status page polls until the run finishes, then links to the hosted report.
8. The report shows scores, metrics, repository facts, benchmark position, and risk titles. Risk detail and the plan are locked, with unlock and subscription calls to action.

**W2 — One-time unlock (S3)** **[R]**

1. From the locked report the recipient follows a signed unlock link.
2. Because possession of the signed link proves inbox control, the system resolves an acting account: the current session if authenticated; otherwise a new account created and signed in for that email — **but only if no account already exists for it**. If one exists, the visitor is redirected to sign in with the destination preserved.
3. The purchase intent is recorded against the acting user and the visitor is sent to checkout for the one-time unlock product.
4. On order completion, the intent is consumed and the report is marked unlocked, exactly once per order.
5. An unlock confirmation email links back to the now-complete report, and the PDF becomes downloadable.

**W3 — Prepaid run when quota is exhausted (S2)** **[R]**

1. The quota-exhausted email offers a single prepaid run and a subscription alternative.
2. The prepaid link is signed and resolves an acting account by the same rule as W2.
3. A run intent is recorded and the visitor is sent to the same one-time product checkout.
4. On order completion the request is marked prepaid, moved to queued, and dispatched. The resulting report is created already unlocked.

**W4 — Private repository (S4)** **[R]**

1. Reachability check fails; the request moves to awaiting access.
2. The requester receives instructions to add the dedicated audit account as a read-only collaborator.
3. Once access is granted, an operator triggers the launch action, moving the request to queued.
4. The cloner injects the audit account's access token for supported hosts only. The token must never appear in stored failure reasons or user-facing text.

**W5 — Subscriber dashboard run and schedule (S5, S6)** **[R]**

1. An authenticated subscriber with remaining monthly allowance launches an audit from the dashboard. The email-verification gate does not apply — the account is already trusted.
2. The run consumes one metered unit for the calendar month; the resulting report is unlocked on creation.
3. The subscriber may save a weekly or monthly schedule per repository. A daily scheduled command runs due schedules, respecting the remaining allowance.
4. Report pages and the ready email surface score movement against the previous audit of the same repository.

---

## 4. Project Scope

### 4.1 Included

**Marketing site** **[R]**

- Single-page rescue-positioning landing page with hero, services, process, trust, health-report visual, FAQ, and closing call to action.
- Audit-request modal posting cross-origin to the product application, with specific error messaging per failure class and analytics event hooks.
- Privacy policy and terms pages, linked from the footer and the consent control.
- SEO and social surface: metadata, Open Graph image, `FAQPage` and `Organization` structured data, sitemap, RSS.
- Session-aware navigation: the primary call to action reflects whether the visitor is signed in to the product application.
- Sample report link placed immediately after the primary call to action.

**Product application** **[R]**

- Public intake API, signed verification, live status page and status JSON endpoint.
- Audit pipeline: preflight, clone, metrics collection, dependency vulnerability audit, deterministic scoring, AI analysis, report persistence, delivery.
- Hosted report page with locked and unlocked rendering, sample report, PDF download as a paid entitlement.
- Freemium entitlement: lifetime free allowance per email, referral bonus, one-time unlock, prepaid single run, subscription allowance metered per calendar month.
- Provider-abstracted checkout and webhook-driven order fulfilment.
- Customer dashboard: audit list and detail, usage statistics, recent audits, launch, schedules, repository trends.
- Operator admin: audit resource with list, view, and edit; retry, manual launch, grant unlock, mark handled, and validated results override; prompt-template settings and per-audit context; pipeline step log and timing; funnel report; audit statistics and by-plan usage widgets; email log with resend *(status refresh removed by D1)*.
- Recovery and retention: verification reminder, abandoned-unlock reminder, unverified-request purge, scheduled re-audits.
- Unified dark visual identity across all backend-served public pages and the customer dashboard.

**Email infrastructure** **[R]** *(revised by D1)*

- All audit email sent directly from the product application through the framework mailer; no separate email application.
- A single mailer entry point through which all audit email is routed, with per-message logging of the rendered subject, body, and send outcome.

### 4.2 Later phases

**[R]** Explicitly out of scope for the current phase, and recorded as approved deferrals:

| Item | Rationale |
| --- | --- |
| General-purpose audit credit purchasable à la carte (usable to unlock *or* run) | Requires a custom credit ledger alongside subscription metering. Deferred until funnel data shows demand. |
| Marketing drip sequences for consented contacts | Consent is captured now; campaign tooling is a separate project. |
| Repository-host invite instructions beyond the primary supported host | Other hosts fall back to a contact-us path. |
| Deep host-app integration (automatic invite detection, pull-request diff audits) | Substantial third-party integration surface. |
| Shareable health-score badge | Growth feature dependent on public score semantics. |
| Delivery, open, and click tracking via an email provider's event webhooks | Requires an ESP with event webhooks (e.g. Postmark, Resend, SES). Delivered/bounced/opened/clicked states would populate the existing email log and feed the funnel. Deferred until after launch (D1). |
| PDF regeneration after a results override | The hosted report reads results live; the PDF remains stale until a re-run. Must be stated in the override interface. |
| Churn-versus-complexity risk-map visualization | Hotspot lists ship now; the grid is a later design task. |
| Full custom admin panel theme | Operator panel remains stock; only the customer dashboard is brand-aligned. |

### 4.3 Explicitly excluded

**[R]** These are not deferrals. They are permanent constraints.

- **Executing cloned code.** No dependency installation, no build, no test run, no script execution — ever.
- **Storing secret values.** Detected credentials are reported as pattern counts and file paths. Matched values are never persisted, logged, or displayed.
- **Fabricated social proof.** No invented testimonials, client names, customer counts, or return-on-investment figures anywhere in the product or marketing surfaces.
- **Human review gate in the delivery path.** Reports are automated by design; the template's conservatism is the compensating control.
- **Marketing content in the product application.** Blog and roadmap surfaces belong to the marketing site. The product application serves only pricing, checkout, auth, legal, status, reports, and dashboards.
- **Retaining cloned source.** Working directories are deleted after every run, including on failure.

### 4.4 Assumptions

- **[A]** Repositories submitted for audit are ones the requester is authorized to share. The platform does not verify ownership beyond email confirmation and, for private repositories, an explicit access grant.
- **[A]** The two public surfaces are served from separate hosts — marketing at the apex domain, product at an application subdomain — so cookies and sessions remain host-scoped and no path-based proxying is required.
- **[A]** Free allowance is counted per email address for the lifetime of that address. Determined abuse via disposable addresses is accepted at the current scale; see §11.8.
- **[A]** Payment providers are configured with live credentials by the operator at deployment. Seeded catalog identifiers are clearly marked test placeholders.
- **[A]** The analytics measurement identifier is supplied by the operator at deploy time. All client code must function with it unset.
- **[A]** Report language is English only in this phase, though user-facing strings are translation-ready.

### 4.5 Constraints

- **[R]** Cloned repositories are limited by size, clone timeout, clone depth, and the volume of content submitted to the model. Every limit must be configuration-driven, not hardcoded at call sites.
- **[R]** The dedicated audit queue must use a connection whose visibility timeout exceeds the maximum job runtime, to prevent concurrent re-delivery of a still-running job.
- **[R]** Report generation must be idempotent with respect to retries. A retry must never produce a duplicate report, and must never revoke an entitlement already granted.
- **[R]** Git must be available in the application runtime.
- **[R]** PDF rendering must not require a headless browser in the application container.
- **[R]** *(revised by D1)* Outbound email uses whatever transport is configured by environment (SMTP or an email service provider). No licensed self-hosted email software is part of the stack.
- **[R]** All static analysis must be implemented without executing repository code, which constrains metric fidelity: coverage is approximated by test-file ratio rather than measured, and duplication is heuristic rather than semantic.

---

## 5. Functional Requirements

Grouped by module. Each group states behavior, business rules, access control, and failure handling.

### 5.1 Intake

**Purpose.** Capture an audit request from an unauthenticated visitor and hold it inert until email ownership is proven.

**F5.1.1 — Submission endpoint** **[R]**
A public endpoint accepts a request containing: name (required, bounded length), email (required, valid format), repository URL (optional, valid URL), message (optional, bounded length), marketing consent (optional boolean), and a honeypot field that must be empty.

Behavior:
- Validation failure returns a structured validation error.
- A non-empty honeypot is rejected.
- Requests are throttled per client address.
- A submission from an email address that submitted within a short recent window is rejected as a duplicate, with a response distinguishable from a validation failure so the client can show "check your inbox".
- On success the request is created in `pending_verification`, a verification email is sent, and a funnel `submitted` event is recorded.
- The response returns the request's public identifier.

**F5.1.2 — Cross-origin access** **[R]**
The endpoint must be reachable from the marketing origin. Allowed origins must be configuration-driven and must include both the production marketing host and the local development host. Allowed methods must cover both the submission request and the credentialed session-status request (§5.9).

**F5.1.3 — Consent capture** **[R]**
Marketing consent is optional and independent of delivery. When given, both the flag and a consent timestamp are stored; the submitting address is retained in request metadata. Report delivery is transactional and must proceed regardless of consent.

**F5.1.4 — Verification gate** **[R]**
Until the email is confirmed:
- No pipeline run may be dispatched.
- No free allowance may be consumed.
- No operator notification may be sent.

The verification link must be a signed URL with a bounded lifetime. Confirmation must be idempotent — a second click must not re-trigger routing. No verification token column may be introduced; the signature is the token.

**F5.1.5 — Post-verification routing** **[R]**
On first confirmation, the system performs a lightweight remote reachability check and routes:

| Condition | Resulting state | Action |
| --- | --- | --- |
| Repository reachable, allowance available | `queued` | Dispatch the pipeline job; record `queued`. |
| Repository reachable, allowance exhausted | `awaiting_payment` | Send the quota-exhausted email including a prepaid-run link; record `awaiting_payment`. |
| Repository unreachable | `awaiting_access` | Send access-grant instructions. |
| No repository URL | `needs_followup` | Send guidance requesting repository access. |

**F5.1.6 — Purge of unconfirmed requests** **[R]**
A scheduled task deletes requests that remain unverified beyond a configured retention window.

**Edge cases and failures**

| Case | Required behavior |
| --- | --- |
| Verification link expired or tampered | Friendly rejection page; no state change. Offer a path to resubmit. |
| Duplicate submission within the dedupe window | Reject with a distinguishable status; do not create a second request; preserve submitted form data client-side. |
| Reachability check times out | **[R]** Treat as unreachable and route to `awaiting_access` — a lead, never a failure. |
| Repository URL points to an unsupported host | **[R]** Route to `awaiting_access` with contact-us guidance rather than host-specific invite steps. |
| Honeypot filled | Reject silently from the visitor's perspective; do not create a request. |

### 5.2 Audit pipeline

**Purpose.** Turn a verified request with a reachable repository into a persisted, delivered report.

**F5.2.1 — Orchestration** **[R]**
A single orchestrating service executes the stages in fixed order and owns all state transitions:

1. Mark the request `analyzing`; stamp an analysis start time; append a pipeline log entry.
2. **Preflight** the repository URL.
3. **Clone** shallowly into a per-request temporary directory; append a log entry.
4. **Collect metrics**, which internally includes the dependency vulnerability audit; returns measured metrics plus a bounded set of code excerpts.
5. **Compute scores** deterministically from metrics; store metrics including computed scores on the request; append a log entry.
6. **Analyze** with the model, supplying metrics, excerpts, and any operator-supplied per-audit context; append a log entry.
7. **Override** the model's score block with the computed scores. The model narrates; measurement decides the numbers.
8. **Create** the report and **send** it; stamp an analysis completion time; append a log entry.
9. **Always** delete the working directory, including on failure.

**F5.2.2 — Analyzability failures** **[R]**
A dedicated "not analyzable" condition — repository missing, private, oversized, or otherwise unclonable — must be caught distinctly and route the request to `needs_followup` with the reason recorded and a log entry appended. These are leads, not errors.

**F5.2.3 — Transient failures and retries** **[R]**
The pipeline job must retry a bounded number of times with increasing backoff for transient conditions. On final exhaustion the request is marked `failed` with a failure reason, a failure log entry is appended, a funnel `failed` event is recorded, and the requester receives a soft "we hit a snag, we will follow up" message.

**F5.2.4 — Guardrails** **[R]**

- Shallow clone only, to a bounded depth sufficient for history-derived insights.
- Enforced clone timeout, preflight timeout, and maximum repository size.
- Bounded number of excerpt files and bounded bytes per excerpt.
- No execution of any repository content.
- Working directory deleted in all paths.

**F5.2.5 — Metrics contract** **[R]**
Metrics collection must produce at least: file count; total lines of code; language breakdown; largest files; a duplication percentage; test file count and ratio; continuous-integration configuration presence; readme presence; dependency manifest summaries with lockfile presence; development tooling detection (error monitoring, linter, static analysis, formatter, environment example, containerization); dependency vulnerability findings; secret-pattern findings as counts and paths; repository git facts (default branch, last commit time, commits analyzed, contributor count, top-contributor share); and change hotspots correlating churn with size.

**F5.2.6 — Secret detection** **[R]**
A pattern set covering at minimum: cloud access keys, private key blocks, generic API-key assignments, host provider tokens in their several forms, chat and messaging tokens, payment live keys, mail provider keys, search and model provider keys, package registry tokens, and credentialed URLs. Findings are reported as `{count, files}` only. Matched values must never be stored or displayed. Patterns must be linear-time on large files.

**F5.2.7 — Dependency vulnerability audit** **[R]**
Dependency manifests and lockfiles are resolved to package coordinates and submitted in batch to an external vulnerability database. Results are attached to metrics. Failure of the external service must degrade gracefully: the audit contributes no findings and the pipeline continues. Unparseable lockfiles yield zero packages rather than aborting.

**F5.2.8 — Deterministic scoring** **[R]**
Scores are computed from measured metrics across six dimensions — structure, duplication, testing, dependencies, security hygiene, and an overall figure. The computed block replaces whatever the model returns. Formulas must be documented and stable, since scores are compared across time and across the population.

**F5.2.9 — AI analysis contract** **[R]**
The analyzer is defined as an interface with a concrete implementation and a test double. Its output must validate against a fixed payload contract before persistence:

| Field | Requirement |
| --- | --- |
| `summary` | Required string. |
| `scores` | Required integers for all six dimensions. |
| `risks` | Required array; each entry requires a title, an impact of high, medium, or low, evidence, and a recommendation. |
| `fix_first_plan` | Required array; each entry requires a step, a rationale, and an effort of S, M, or L. |

Validation failure must be surfaced as an analysis error, not persisted. A single corrective retry is permitted before failing the stage. This same validator must be the only definition of a valid payload, reused by every writer including the operator override (§5.8).

**F5.2.10 — Prompt composition** **[R]**
Prompts are composed from a template that may be overridden by an operator setting. A blank override means use the built-in default. The template must contain the placeholders the composer substitutes; a saved override missing them must be rejected. Per-audit operator context, when present, is appended. The exact prompt the next run would use must be previewable in the operator interface.

**F5.2.11 — Model configuration** **[R]**
The model identifier must be configuration-driven with an environment override, and the credential must come from the environment. **[Q]** The pinned model default and its cost/latency profile should be reviewed against currently available models before launch (§19).

### 5.3 Report delivery and access

**F5.3.1 — Persistence** **[R]**
A report row stores a public identifier, its owning request, an optional owning user, the validated payload, an optional PDF path, an unlock timestamp, and an optional reference to the order that unlocked it.

**F5.3.2 — Hosted report** **[R]**
A signed, time-bounded URL renders the report from the stored payload. Always visible: repository identity, audit date, overall and per-dimension scores, the measured metrics grid, repository facts, benchmark position where available, score movement where available, and risk titles with severity. Locked when not unlocked: risk evidence and recommendations, and the fix-first plan — presented as visibly obscured content with unlock and subscription calls to action, not as absent sections.

**F5.3.3 — PDF** **[R]**
Rendered server-side without a headless browser, from the same template family as the hosted view. Download is available only for unlocked reports. A locked report's download route must be refused.

**F5.3.4 — Sample report** **[R]**
A static demonstration payload renders through the same template, always unlocked and clearly labelled as a sample, reachable without a signature and linked from the marketing site. A missing or corrupt fixture must produce a not-found response, not an unhandled error.

**F5.3.5 — Link expiry** **[R]**
An expired or invalid signature must render a friendly explanation with a route to recovery, never a raw error.

**F5.3.6 — Ownership linking** **[R]**
A report is associated with a user when an account exists for the request's email at creation time, and retroactively when an account is later registered for that email. The retroactive linking must also backfill request ownership, so that pre-registration audits remain visible after signup.

**F5.3.7 — Idempotent regeneration** **[R]**
Re-running an audit for a request that already has a report must not create a duplicate. If the existing report was unlocked, the replacement must carry the unlock forward — a re-run must never silently revoke a paid entitlement — and must regenerate the PDF.

**F5.3.8 — Live status** **[R]**
A signed status page shows the current stage in plain language and polls a signed JSON endpoint returning status, a human label, completion and failure flags, and a report URL when available. The status link must outlive the verification link, since it is the requester's durable handle on the run. Email deep links must point at it.

### 5.4 Entitlement and monetization

**F5.4.1 — Single source of entitlement truth** **[R]**
Exactly one field on the report — its unlock timestamp — determines whether full detail renders. It is set by: a completed one-time purchase, a subscription-metered dashboard run, a prepaid run, or an operator grant. The report view must never query orders or subscriptions to decide what to render.

**F5.4.2 — Free allowance** **[R]**
A configured number of free runs per email address, for the lifetime of that address, plus any per-user bonus runs. Consumption is marked on the request that consumed it, so retries never double-count. Only runs flagged as free count against the allowance.

**F5.4.3 — Referral bonus** **[R]**
A successful referral grants the referring registered user an additional free run. Anonymous email addresses receive the flat allowance only. Bonus accrual is money-adjacent and must be safe under concurrent referrals for the same referrer.

**F5.4.4 — One-time unlock** **[R]**
A single one-time product unlocks exactly one existing report. It does not purchase an additional analysis run. The distinction must be explicit in all copy.

**F5.4.5 — Guest-friendly unlock** **[R]**
The unlock route is signature-protected rather than authentication-gated. Possession of the signed link, delivered to a verified address, is treated as proof of inbox control, so the system may create and sign in an account for that address. If an account already exists for the address, the system must never sign in automatically; it must redirect to login with the destination preserved. A shared report link must not grant access to an existing account.

**F5.4.6 — Prepaid single run** **[R]**
When the free allowance is exhausted, the requester may purchase a single run through the same one-time product. A run intent is recorded, and on order completion the request is marked prepaid, queued, and dispatched. The resulting report is created unlocked. A stale or dangling run intent must not swallow the fallback unlock behavior.

**F5.4.7 — Subscription allowance** **[R]**
Subscription tiers grant a monthly analysis allowance, read from plan product metadata rather than hardcoded. Allowance is metered per calendar month and counts only dashboard-sourced runs. Reports produced by allowance-metered runs are created unlocked. Dashboard runs bypass the email-verification gate.

**F5.4.8 — Order fulfilment idempotency** **[R]**
Order-completion handling must be idempotent at the order level. A duplicated provider webhook must not grant two entitlements or dispatch two runs. Intent consumption must delete the intent, and a consumed-but-dangling intent must short-circuit rather than falling through to the generic latest-locked-report path.

**F5.4.9 — Catalog** **[R]**
The purchasable catalog — the one-time unlock product and the subscription tiers with their prices, display order, feature lists, and popularity flags — must be seeded idempotently, keyed on stable natural keys, safe to re-run. Payment-provider identifiers in seed data must be unmistakably marked as test placeholders.

**Business rules** **[R]**

- Free-tier reports are always fully computed and stored. Locking is a rendering concern only; unlocking never triggers re-analysis.
- PDF availability is a paid entitlement.
- Benchmark position is suppressed below a configured minimum population, so early percentiles do not mislead.

### 5.5 Customer dashboard

**F5.5.1 — Ownership scope** **[R]**
A single query scope defines the authorization boundary: audits belonging to the signed-in user by identifier **or** by matching email address. The email clause is required so that pre-registration audits remain visible. Every list, detail view, widget, and action must flow through this scope. Requesting another user's audit must yield not-found, not a permission error page. The scope's clauses must be grouped so that composing additional conditions cannot widen it.

**F5.5.2 — Audit list** **[R]**
Read-only, newest first, showing repository, status badge, overall score, source, submitted time, and completion time, with status and date-range filters. A create-new-audit affordance appears only when allowance remains.

**F5.5.3 — Audit detail** **[R]**
Read-only, in three parts: project information; status and timeline with a plain-language description per status, failure reason when failed, and an action callout for states requiring the user to act; and results, rendered only when a report exists, with scores, risk counts by impact, and links to the hosted report and PDF. Locked reports still link out — the report page owns locked rendering and the unlock path, which must not be duplicated here.

**F5.5.4 — Dashboard widgets** **[R]**
A statistics tile row showing remaining analyses this period (subscription allowance where present, otherwise remaining free runs, each clearly labelled), in-progress count, completed count, and failed count; plus a recent-audits table. Both hide entirely when the user has neither audits nor allowance.

**F5.5.5 — Launch, schedules, trends** **[R]**
Subscribers may launch a run, save a weekly or monthly schedule per repository, and view score history grouped by normalized repository URL. Navigation to the audit section must be visible to an entitled subscriber even before their first report exists.

**Access control** **[R]** Dashboard access requires authentication. No dashboard surface may expose another user's audit, report, or metrics.

### 5.6 Operator administration

**F5.6.1 — Audit resource** **[R]**
List with search across name, email, and repository URL, and filters by status and submission date. Detail view showing the request, requester organization affiliation, per-audit operator context, the exact prompt the next run will use, the pipeline step log with timing, and the report. Edit page permitting changes to status, repository URL, name, email, message, and operator context.

**F5.6.2 — Actions** **[R]**
Retry the pipeline; manually launch an access-pending audit; grant an unlock; mark a follow-up handled; and override results. Existing actions must remain behaviorally unchanged as the resource evolves.

**F5.6.3 — Results override** **[R]**
Presents the stored payload as formatted JSON for editing. The submitted value must be validated by the same canonical payload validator used by the analysis stage — not by a bespoke spot-check. Rejected input must produce a field-level error and must never be saved. The interface must state that the hosted report updates immediately while the PDF remains stale until a re-run.

**F5.6.4 — Prompt settings** **[R]**
A settings page persists the global prompt template through the same configuration mechanism as other operator settings, displays the built-in default for reference, and validates that required placeholders are present. Blank means use the default. Access must be gated by the same permission as sibling settings pages.

**F5.6.5 — Pipeline observability** **[R]**
Every stage boundary appends a structured entry with step, message, and timestamp. Failures append an entry before the status change. Analysis start and completion timestamps are recorded and power the average-processing-time statistic.

**F5.6.6 — Funnel report** **[R]**
A report showing counts per funnel stage over seven-day and thirty-day windows, with each stage's share of submissions, and a note distinguishing the primary conversion path from side branches. Access must be permission-gated.

**F5.6.7 — Statistics widgets** **[R]**
Total audits; submitted today, this week, this month; pending; analyzing; completed; failed; requiring manual action; average processing time (dash when no data); email failures; and audit queue depth linking to the queue monitor. Plus current-month audits grouped by the requester's active plan, with a free/no-plan bucket. Widget queries must not assume the presence of tables owned by other modules; a missing table must render zero rather than error.

**F5.6.8 — Email log** **[R]**
Read-only rows showing recipient, notification type, status, attempts, last attempt, and latest error, filterable by status and type and searchable by recipient. A per-row resend action must state when and to whom the message was last sent before proceeding — a duplicate-send safeguard. *(Revised by D1: the delivery-status refresh action and the external email-platform link are removed with the platform; delivered/bounced states return only if provider webhooks are later integrated per §4.2.)*

**Access control** **[R]** The entire operator panel is gated at the panel level by administrator status. Because of that gate, operator queries are deliberately not ownership-scoped — unlike the customer dashboard. This asymmetry is intentional and must be preserved rather than "fixed".

### 5.7 Growth, conversion, and retention

**F5.7.1 — Funnel instrumentation** **[R]**
Ten ordered stages must be recorded: submitted, verified, queued, awaiting payment, report sent, report viewed, unlock started, unlock paid, run purchased, failed. Each event records the stage, the related request where applicable, and optional metadata. Stage statistics must zero-fill every stage so that absent stages read as zero rather than missing.

Web-only stages must not be inflated by dashboard-sourced or scheduled runs. **[R]** Stage recording must distinguish acquisition-funnel events from internal runs.

**F5.7.2 — Client analytics and error specificity** **[R]**
The audit modal emits open and submit events when an analytics vendor is configured, and must function with none configured. Error messaging must be specific per failure class — duplicate recent submission, validation failure, and generic failure are distinct messages. A stale error must be cleared when the modal is reopened.

**F5.7.3 — Recovery emails** **[R]**
A reminder to requesters who have not confirmed their email after roughly a day, and a reminder to recipients who began an unlock checkout but did not complete it. Reminder markers must be per-report or per-request, so that one reminder can never collide with another. A batch run must survive a single bad row and continue.

**F5.7.4 — Report substance** **[R]**
The report must surface measured repository facts, real dependency vulnerability findings, expanded secret detection, tooling detection, deterministic scores, and history-derived hotspot and bus-factor insights — in the hosted view, the PDF, and the sample.

**F5.7.5 — Retention loops** **[R]**
Score deltas against the previous audit of the same repository, shown in the report page and the ready email; and scheduled weekly or monthly re-audits that respect the subscriber's remaining allowance and do not overlap.

**F5.7.6 — Marketing trust surface** **[R]**
Privacy and terms pages; a real social preview image; structured data; a product-path section connecting the free audit to the paid unlock and the subscription tiers. All monetary and quota claims on the marketing site must match backend configuration exactly.

### 5.8 Email delivery

*(Section revised by D1, 2026-08-01. The previously specified standalone transactional email platform is removed. The single-entry-point mailer and the per-message log — the parts that carry the accountability guarantee — are retained unchanged. The Mailcoach client, its configuration gate, and the status-refresh feature are to be deleted from the codebase in Phase 10.)*

**F5.8.1 — Single entry point** **[R]**
All audit email — every one of the ten message types, including the operator notification and both reminder types — must be sent through one mailer service. No audit message may be dispatched directly. Non-audit messages (orders, invitations, subscription notices) are out of scope for this routing and must remain untouched.

**F5.8.2 — Send behavior** **[R]**
For each message the mailer renders subject and body, records a pending log row, sends through the framework mailer using the environment-configured transport, and updates the row to sent. On a send failure the row is marked failed with the error recorded, and the failure propagates to the caller's normal retry/failure handling. Audit email must never silently stop: every attempt leaves a log row with an outcome.

**F5.8.3 — Transport configuration** **[R]**
The mail transport (SMTP host or email service provider) is configured entirely by environment. Local development uses the mail catcher. No code path may depend on which transport is configured.

**F5.8.4 — Log contract** **[R]**
Each row records the related request (nullable, to allow future non-request mail), the message type, the recipient, the rendered subject and body (retained so a resend needs no re-render), a status progressing from pending to sent or failed, an attempt counter, the latest error, and the last attempt time. The delivered and bounced statuses remain defined in the vocabulary but are populated only if provider webhooks are later integrated (§4.2); a provider-message-identifier column may be retained nullable for that future use.

**F5.8.5 — Resend** **[R]**
Resend reconstructs the message from the stored subject and body rather than re-rendering, so the resent message matches what was originally sent. Attempts increment. Resend requires explicit operator confirmation showing the last recipient and send time.

**F5.8.6 — Message set** **[R]**
Verification link; request received; repository access needed; quota exhausted; report ready; report unlocked; request failed; verification reminder; unlock reminder; operator notification of a new request.

**Edge cases**

| Case | Required behavior |
| --- | --- |
| Transport unreachable or rejects the message | Mark the row failed with the error; the caller's queue retry policy governs any retry; resend remains available to the operator. |
| Rendering fails | **[REC]** A render failure currently prevents any log row from existing. Rendering should be attempted before the row is created so that a render failure is itself recorded (§18). |
| Resend of a stale row | Reconstruct from the stored subject and body rather than re-rendering. |

### 5.9 Public site and session awareness

**F5.9.1 — Configurable product URL** **[R]**
Every marketing-site route into the product must be built from a single configured product-application URL, overridable by environment, never hardcoded.

**F5.9.2 — Session-status endpoint** **[R]**
A read-only endpoint reports whether the caller's session is authenticated. It must return a boolean and nothing more — no identity, no roles, no counts. It must be no-store, requires no anti-forgery token, and must permit credentialed cross-origin requests from the configured marketing origin only. Wildcard origins are forbidden. In production the session cookie domain must be scoped so the cookie is visible to requests initiated from the marketing origin.

**F5.9.3 — Progressive navigation** **[R]**
The marketing site renders the signed-out state by default — correct for the majority and free of a wrong-state flash — and swaps to the signed-in state only on a successful affirmative response. Any error or timeout leaves the default intact.

**F5.9.4 — Post-authentication destination** **[R]**
Sign-in, registration, and verification must land the user in the application. An external or marketing-page referrer must never be adopted as the intended destination. Administrators land in the operator panel; others land in the dashboard. A genuine deep link to a protected page must be preserved. The framework's default home destination must be the dashboard.

**F5.9.5 — Product application surface** **[R]**
The product application serves only: a root that redirects to pricing for guests and the dashboard for authenticated users; a lean pricing page composed from catalog components; authentication and checkout flows; legal pages; the audit status page; report pages; and the two panels. Marketing content routes must not exist.

### 5.10 Visual identity

**F5.10.1 — Shared design language** **[R]**
A single dark visual identity — near-black canvas, cream text, gold primary accent, coral for warnings and errors, with a display face for headings, a body face, and a monospace face for labels — must be defined once as design tokens and consumed by both the marketing site and every backend-served public page.

**F5.10.2 — Layout coverage** **[R]**
The application, focus, and centered-focus layouts carry effectively all public pages. Restyling those three plus shared form and control styling must restyle the site without touching routes, controllers, or component logic. Slot names and component contracts must not change.

**F5.10.3 — Customer dashboard branding** **[R]**
Brand alignment only, through supported theming hooks: primary color, brand name and mark, dark default appearance, and typography. No custom panel theme stylesheet. The operator panel remains unstyled stock.

**F5.10.4 — Deliberate exceptions** **[R]**
The hosted report page and its expiry page remain light — they are standalone documents. Email templates remain unchanged.

**F5.10.5 — Accessible controls** **[R]**
Custom-styled controls must retain the native input in the document for accessibility and form submission, with default, hover, focus-visible, checked, and disabled states all represented.

### 5.11 Cross-cutting behavior

**F5.11.1 — Status vocabulary** **[R]**
A single closed enumeration defines every audit state: `new`, `pending_verification`, `queued`, `analyzing`, `report_ready`, `sent`, `failed`, `needs_followup`, `handled`, `awaiting_access`, `awaiting_payment`. Display mapping — label and color — must be defined once and reused everywhere. Every consumer must handle every case; an unmapped state is a defect.

**F5.11.2 — Terminal-state recoverability** **[R]**
`report_ready` exists specifically so that a crash between persistence and delivery is visible and recoverable through the retry action, rather than appearing as a success.

**F5.11.3 — Localization readiness** **[R]**
All user-facing strings in the product application must pass through the translation layer.

**F5.11.4 — Failure messaging** **[R]**
No user-facing failure may expose stack traces, internal identifiers, credentials, or repository access tokens.

### 5.12 Tiered audit products *(added by D2, 2026-08-01)*

**Purpose.** Turn the single automated pipeline into three sellable tiers per the 2026-08 company pitch, with the existing free funnel retained as the free diagnostic entry step. Target pricing (all **[Q]** pending cost-per-audit validation, §19 Q5): Automated Health Report $49; Deep AI Code Review $199 (flagship); Expert Audit from $999. Subscription grid per pitch: Starter $59 / Growth $149 / Agency $499 / Enterprise from $1,500 monthly.

**F5.12.1 — Tier attribute** **[R]**
Every audit request carries a tier — `diagnostic` (free funnel), `automated`, `deep_ai`, or `expert`. The tier determines pipeline composition, resource budgets (excerpt limits, token budget, scanner set), prompt template, report rendering, and price. All budgets are configuration-driven per tier, never hardcoded.

**F5.12.2 — Tier 1: scanner platform** **[R]**
The automated tier replaces or augments the home-grown heuristics with established open-source analyzers, all executed **without running repository code** (§4.3 stands). The set below is **decided** for the first release, optimized for cost-to-quality: every tool is a stateless CLI binary — no analyzer servers to operate — so per-run cost is compute-seconds, and the model call remains the dominant cost, bounded by grouping.

**Committed scanner set, in fixed execution order** (cheap and universal first, expensive last, so early output feeds later stages and an early failure loses the least):

| # | Tool | License | Produces | Bound by |
| --- | --- | --- | --- | --- |
| 1 | **scc** | MIT | Size, language breakdown, per-file complexity estimate | Seconds; always first — its output sizes the budgets for the rest |
| 2 | **Gitleaks** | MIT | Secret findings — supersedes the in-house pattern set; same counts-and-paths-only contract (F5.2.6) | Own timeout |
| 3 | **OSV querybatch** *(existing integration, retained as-is)* | — | Dependency vulnerabilities | Existing degrade-to-zero behavior |
| 4 | **jscpd** | MIT | Cross-language duplication — supersedes the heuristic | Own timeout; capped file set |
| 5 | **Semgrep CE** | LGPL-2.1 engine | Quality and security SAST findings | Own timeout; the most expensive scanner, runs last. **Only permissively-licensed rulesets or in-house rules** — the Semgrep Registry license forbids use in competing commercial products |

Existing collectors that stay: git facts, hotspots, tooling detection, manifest summaries.

**Deferred, recorded as deliberate deferrals:** SonarQube Community Build (a server to operate; its signal overlaps Semgrep+jscpd — revisit only if report quality shows a gap); Trivy (IaC/Docker misconfig and license scanning — additive, not core); import-graph/SCIP indexing (needed only for tier-2 file selection, moved to Phase 12 as an optional enhancement); Lizard (scc's complexity estimate suffices initially). CodeQL is excluded permanently: its license prohibits use in a commercial service.

- **[R]** All scanner output is normalized into one internal findings model (SARIF as the interchange format where the tool supports it), deduplicated, and **grouped into problem families** (rule family × directory) before analysis. The AI receives metrics plus the top problem groups ranked by severity×count — never the raw finding list — and narrates each group: what it is, what it affects, and what fixing it buys the client. One lint error must never become one report item, and grouping is also the prompt-size cost control.
- **[R]** Each tool runs under its own timeout inside the pipeline's existing guardrails; a failed scanner contributes no findings and is recorded in the pipeline log; it never fails the run on its own.
- **[R]** Deterministic scoring (F5.2.8) continues to own the numbers; scanner findings feed the formulas and the narrative, not the other way around.

**F5.12.3 — Tier 2: deep AI review** **[R]**
Everything in tier 1, plus AI review of the source of the 20–40 riskiest files only — never the whole repository:

- **[R]** Risk-file selection is deterministic and logged. First release uses three signals the pipeline already has or gets cheaply: churn×size hotspots (existing), scanner-finding density (from Phase 11's findings model), and sensitive-domain path heuristics (authentication, authorization, payments, uploads, secrets handling). Import-graph centrality is a deferred fourth signal — added only if selection quality proves insufficient, so tier 2 does not wait on graph tooling.
- **[R]** The review examines file contents with cross-module context and returns findings bound to files, covering business logic, authorization, and architectural risks, each with evidence, recommendation, and effort sizing — validated by an extension of the canonical payload contract (F5.2.9).
- **[R]** A per-run token budget bounds cost; exceeding it truncates the file list, never the contract.

**F5.12.4 — Tier 3: expert review workflow** **[R]**
Everything in tiers 1–2, plus a human review stage:

- **[R]** For expert-tier runs only, the pipeline stops after persistence in a new status (working name `expert_review`, added to the closed enumeration in F5.11.1 with display mapping). The report is **not** auto-sent.
- **[R]** The operator panel gains an expert-review queue: reports awaiting review, where a reviewer edits findings through the canonical payload validator, removes false positives, adjusts priorities, and fills a dedicated expert section of the payload (expert summary, review notes, reviewed-by, reviewed-at). A publish action sends the report, regenerates the PDF, and transitions the status to sent.
- **[R]** Reviewing requires a reviewer permission distinct from full administrator rights.
- **[R]** The report template renders the expert section and a human-verified marker for this tier only. §4.3's "no human review gate" exclusion is hereby narrowed: it continues to apply to the diagnostic, automated, and deep-AI tiers; the expert tier's review gate is the product being sold.

**F5.12.5 — Catalog and entitlement rework** **[R]**
The purchasable catalog is rebuilt around the tiers: three one-time tier products plus the pitch subscription grid, seeded idempotently per F5.4.9. The free diagnostic keeps the existing freemium mechanics (allowance, locked rendering, $-unlock deprecated or repositioned **[Q]** — decide whether the legacy $5 unlock survives as a diagnostic-tier upsell or is retired). Subscription allowances meter tier-1 runs; higher-tier runs and any included Deep AI credits are read from plan metadata. Marketing copy, checkout, and quota claims must continue to mirror backend configuration exactly (F5.7.6).

**F5.12.6 — Cost telemetry** **[R]**
Every run records its direct cost drivers — model tokens in/out, scanner wall time, repository size — so cost per audit is measurable per tier from the first paid runs (pitch requires validation on the first 20–30).

---

## 6. Non-Functional Requirements

### 6.1 Performance

| # | Requirement |
| --- | --- |
| N1 | **[R]** Intake submission must respond without waiting on any repository network operation. Reachability checking belongs to post-verification routing, not to the submission request. |
| N2 | **[R]** The pipeline job must carry an execution timeout well above expected runtime, and the queue's visibility timeout must exceed that job timeout. |
| N3 | **[R]** Clone timeout, preflight timeout, repository size ceiling, clone depth, excerpt file count, and excerpt byte ceiling must all be enforced and configuration-driven. |
| N4 | **[REC]** Median time from verification to delivered report: ≤ 5 minutes for repositories under 50k lines. |
| N5 | **[R]** Secret-detection patterns must be linear-time on multi-megabyte files. |
| N6 | **[R]** Benchmark computation must be cached rather than recomputed per report view. |
| N7 | **[REC]** Dashboard and operator list views should keep per-render query counts bounded; several widgets currently issue one query per tile and some relations are not eagerly loaded (§18). |
| N8 | **[R]** The static marketing site must ship as pre-rendered output with long-lived immutable caching on fingerprinted assets. |

### 6.2 Scalability

- **[R]** Audit execution must be horizontally scalable by adding queue workers. No pipeline stage may depend on local state surviving between jobs.
- **[R]** Audit work must run on a dedicated queue so that a burst of audits cannot starve transactional email or other application jobs.
- **[R]** Scheduled tasks that fan out must not overlap themselves and must run on a single node in a multi-node deployment.
- **[REC]** Working directories should be provisioned on storage sized for the configured maximum repository size multiplied by expected worker concurrency.
- **[A]** Current expected volume is comfortably served by a single application node plus workers. Read-replica and sharding strategies are out of scope.

### 6.3 Availability

- **[R]** Marketing-site availability must not depend on the product application. A product outage must degrade the site to its signed-out state, not break it.
- **[R]** Report viewing must remain available during a queue outage; only new analyses are affected.
- **[R]** Email-platform unavailability must not prevent transactional delivery (§5.8.2).
- **[R]** External vulnerability-database unavailability must not fail a pipeline run.
- **[REC]** Target 99.5% monthly availability for the product application; the static site should target higher, as its hosting model allows.

### 6.4 Reliability

- **[R]** Retries must never produce duplicate reports or duplicate entitlements.
- **[R]** Order fulfilment must be idempotent at the order level.
- **[R]** Reminder emails must not double-send for the same subject.
- **[R]** Working directories must be cleaned up on every path, including unhandled failure.
- **[R]** A crash between report persistence and delivery must leave a recoverable, visibly non-terminal state.
- **[R]** Every terminal failure must carry a stored, human-readable reason.

### 6.5 Security

Detailed in §11. Headline obligations:

- **[R]** Cloned code is never executed.
- **[R]** Repository access tokens are injected only for supported hosts and are redacted from all stored and displayed error text.
- **[R]** All report, status, unlock, and prepaid-run routes are signature-protected with bounded lifetimes where appropriate.
- **[R]** Automatic account creation from a signed link occurs only when no account exists for that address.
- **[R]** Secret findings never include matched values.
- **[R]** The session-status endpoint exposes a boolean only, to a configured origin only, with credentials and no wildcard.
- **[R]** Operator surfaces are gated at panel level; customer surfaces are ownership-scoped at query level.

### 6.6 Privacy

- **[R]** Marketing consent is opt-in, timestamped, and independent of transactional delivery.
- **[R]** Cloned source code is transient. Only derived metrics, bounded excerpts submitted for analysis, and the resulting payload persist.
- **[R]** Detected secrets are recorded as counts and paths, never values.
- **[R]** Published privacy and terms documents must accurately describe what is collected, what is sent to third parties, and what is retained.
- **[R]** Unverified requests are purged on a schedule.
- **[Q]** A defined retention period for verified requests, reports, funnel events, and email logs — including body retention in the email log — is not yet specified (§19).
- **[Q]** A subject-access and deletion procedure is not yet specified (§19).
- **[A]** Bounded code excerpts are transmitted to the model provider. This must be disclosed in the privacy policy.

### 6.7 Maintainability

- **[R]** Business logic in services; controllers and components stay thin.
- **[R]** One definition per concept: one status enumeration, one display mapper, one payload validator, one ownership scope, one entitlement field, one mailer entry point, one design-token source.
- **[R]** Framework-provided scaffolding and resource patterns must be preferred over bespoke controllers.
- **[R]** Code formatting must be enforced by the project formatter before every commit.
- **[R]** Static analysis must be run, and any new error *category* introduced by a change must be resolved or explicitly justified. A known, accepted baseline of pre-existing framework-inference errors is tolerated; growth in that baseline must be attributable and category-stable.
- **[REC]** Duplicated column, status-description, and visibility logic across list views, detail views, and widgets is a known consolidation candidate (§18).

### 6.8 Observability

- **[R]** Every pipeline run must produce a step-level log with timestamps, readable in the operator interface.
- **[R]** Analysis start and completion times must be recorded per run.
- **[R]** Every audit email must produce a log row with status, attempts, and latest error.
- **[R]** Funnel events must be recorded at every stage transition.
- **[R]** Queue depth for the audit queue must be visible to operators.
- **[REC]** Application error tracking, uptime checks, and alerting are not yet specified and should be (§15, §19).

### 6.9 Accessibility

- **[R]** Custom controls retain native inputs and expose all interaction states including a visible focus indicator.
- **[R]** The dark palette's text-on-surface combinations must meet contrast requirements for body text and interactive elements.
- **[R]** Locked report content must remain comprehensible to assistive technology as "locked, purchase to reveal" rather than presenting as unreadable noise.
- **[REC]** Target WCAG 2.1 AA for the marketing site and all public product pages. **[Q]** Confirm the conformance target (§19).
- **[REC]** Report status changes on the polling status page should be announced to assistive technology rather than silently swapped.

### 6.10 Compatibility

- **[R]** The marketing site must function without JavaScript for all content; JavaScript enhances the modal and the navigation swap only.
- **[R]** Current evergreen desktop and mobile browsers must be supported. The dark public pages are dark-only with no theme toggle.
- **[R]** Report PDFs must render without a headless browser.
- **[R]** Repository support must cover, at minimum, the primary hosted git provider for private-access flows, and any reachable public git remote for public flows.
- **[R]** Language and dependency-manifest coverage must include at least the ecosystems detected by the metrics collector, and unknown ecosystems must degrade to zero findings rather than failing.

---

## 7. System Architecture

### 7.1 High-level shape

Two cooperating applications over shared infrastructure, deployed as one repository with independent build and release paths. *(Revised by D1: the third application — the standalone transactional email service — is removed; outbound mail goes directly to the environment-configured transport.)*

```
                 ┌──────────────────────────────┐
   Visitor ─────▶│  Marketing site (static)     │
                 │  apex domain                 │
                 └───────────┬──────────────────┘
                             │ audit submission (cross-origin POST)
                             │ session status (credentialed GET)
                             │ CTA links
                             ▼
   Customer ────▶┌──────────────────────────────┐◀──── Operator
   Subscriber    │  Product application         │
                 │  app subdomain               │
                 │  ┌────────────────────────┐  │
                 │  │ HTTP: intake, verify,  │  │
                 │  │ status, reports,       │  │
                 │  │ unlock, checkout       │  │
                 │  ├────────────────────────┤  │
                 │  │ Panels: customer       │  │
                 │  │ dashboard, operator    │  │
                 │  ├────────────────────────┤  │
                 │  │ Services: pipeline,    │  │
                 │  │ entitlement, funnel,   │  │
                 │  │ mailer, payments       │  │
                 │  └────────────────────────┘  │
                 └──┬────────┬────────┬─────────┘
                    │        │        │
        ┌───────────┘        │        └────────────┐
        ▼                    ▼                     ▼
  ┌───────────┐      ┌──────────────┐      ┌──────────────┐
  │ Relational│      │ Queue /      │      │ Object /     │
  │ database  │      │ cache        │      │ file storage │
  └───────────┘      └──────┬───────┘      └──────────────┘
                            │
                     ┌──────▼───────┐
                     │ Queue workers│──▶ git remote (clone, read-only)
                     │ (audit queue)│──▶ model provider API
                     └──────┬───────┘──▶ vulnerability database API
                            │
                            ▼
                   mail transport (SMTP / email service provider,
                   environment-configured; every send logged)
```

### 7.2 Components and responsibilities

| Component | Owns | Must not |
| --- | --- | --- |
| **Marketing site** | Positioning, content, SEO surface, audit-request capture UI, legal pages. Static output only. | Hold business logic, talk to the database, or render report content. |
| **Product application (HTTP)** | Intake validation, verification, status, report rendering, unlock and prepaid flows, checkout entry, webhook receipt. | Contain business rules; those belong to services. |
| **Product application (panels)** | Customer dashboard and operator administration. | Bypass the ownership scope (customer) or the panel gate (operator). |
| **Domain services** | Pipeline orchestration, cloning, metric collection, scoring, prompt composition, analysis, report lifecycle, entitlement, funnel recording, benchmarking, delta computation, scheduling, mail routing. | Depend on HTTP or panel context. |
| **Queue workers** | Executing pipeline jobs and routing jobs off the dedicated audit queue. | Retain state between jobs. |
| **Scheduler** | Firing purge, reminder, and scheduled-audit tasks. | Perform work inline; it dispatches. |
| **Relational database** | All durable domain state. | Store secret values or cloned source. |
| **Queue and cache store** | Job transport and cached derived values such as benchmark position. | Be the source of truth for anything. |
| **File storage** | Generated PDFs and transient clone working directories. | Retain working directories past a run. |
| **Mail transport (external SMTP/ESP)** | Physical message delivery. | Hold any domain state; the product application's email log is the sole record of audit mail. |
| **Payment providers** | Checkout and billing lifecycle. | Be referenced directly by audit or entitlement logic. |

### 7.3 Communication

| Path | Mode | Notes |
| --- | --- | --- |
| Marketing site → intake | Synchronous HTTP, cross-origin | **[R]** Explicit origin allowlist; honeypot and throttling at the boundary. |
| Marketing site → session status | Synchronous HTTP, cross-origin with credentials | **[R]** Boolean only; no-store; no wildcard origin. |
| HTTP → domain services | In-process | **[R]** Controllers delegate; no logic inline. |
| Verification → routing | **Asynchronous** | **[R]** Confirmation dispatches a routing job; the click response must not block on reachability. |
| Routing → pipeline | **Asynchronous** | **[R]** Dedicated audit queue, bounded retries with backoff. |
| Pipeline → git remote | Synchronous, outbound | **[R]** Shallow read-only clone under timeout and size limits. |
| Pipeline → model provider | Synchronous, outbound | **[R]** Bounded input; validated structured output; one corrective retry. |
| Pipeline → vulnerability database | Synchronous, outbound, batched | **[R]** Failure degrades to zero findings. |
| Application → mail transport | Synchronous send via framework mailer | **[R]** Every send produces a log row with its outcome; failure is recorded, never swallowed. |
| Payment provider → application | **Asynchronous** inbound webhook | **[R]** Order-level idempotency mandatory. |
| Scheduler → work | **Asynchronous** | **[R]** Non-overlapping; single-node for fan-out tasks. |
| Domain events → listeners | In-process events | **[R]** Order completion, referral reward, and user registration drive entitlement and linking through listeners, not inline branches. |

### 7.4 Data flow — the primary path

1. **Capture.** Submission validated at the boundary; request persisted in `pending_verification`; funnel `submitted`; verification email queued through the mailer.
2. **Prove.** Signed confirmation marks verification once; funnel `verified`; routing dispatched asynchronously; requester redirected to the durable status page.
3. **Route.** Reachability probed; branch selected; on the success branch the pipeline job is dispatched and funnel `queued` recorded.
4. **Measure.** Clone into a temporary directory; collect metrics including dependency vulnerabilities; compute deterministic scores; persist metrics with computed scores onto the request.
5. **Narrate.** Compose the prompt from template, metrics, excerpts, and operator context; call the model; validate the payload; replace its score block with the computed scores.
6. **Persist and deliver.** Create the report — unlocked when the run was dashboard-sourced, prepaid, or carrying a prior unlock; render the PDF when entitled; send the ready email; record funnel `report_sent`; stamp completion; delete the working directory.
7. **Convert.** Report view records `report_viewed`; unlock start records `unlock_started`; paid order records `unlock_paid` or `run_purchased` and sets the entitlement.
8. **Retain.** Deltas computed at view time against the previous audit of the same repository; benchmark position resolved from the cached population distribution; schedules fire subsequent runs.

**[R]** Two computations are deliberately *not* part of a run: score deltas and benchmark position. Both are derived at report-view time so that a report's stored payload remains an immutable record of that run.

### 7.5 Multi-tenancy

- **[R]** The product application is multi-tenant. Subscriptions, plans, orders, and billing are tenant-scoped; a user belongs to a tenant.
- **[R]** Subscription allowance is resolved per tenant. Metered consumption is counted per user within the calendar month.
- **[R]** Audit ownership is resolved per **user** — by identifier or email — not per tenant, because audits originate before any account or tenant exists. This asymmetry is deliberate and must be preserved.
- **[R]** The customer dashboard's authorization boundary is the ownership scope. The operator panel's boundary is the panel gate.
- **[Q]** Whether audits should become visible to all members of a tenant (team visibility) is undecided (§19).

### 7.6 Deployment architecture

| Surface | Model | Requirement |
| --- | --- | --- |
| Marketing site | Static hosting or CDN | **[R]** Pre-rendered output; immutable caching on fingerprinted assets; no server runtime. |
| Product application | Application server behind TLS | **[R]** Deployed by a repeatable release process with zero-downtime symlink switching and post-release migration and cache steps. |
| Queue workers | Long-running processes | **[R]** Must run continuously; audit features are non-functional without them. |
| Scheduler | Cron-driven single entry | **[R]** One scheduler entry per environment. |
| Local development | Container composition | **[R]** One command must boot every surface plus database, cache, and a mail catcher. Container-created files must not be root-owned on the host. |

### 7.7 Architectural patterns and rationale

| Pattern | Where | Why |
| --- | --- | --- |
| **Pipeline with explicit stages** | Audit execution | Each stage is independently testable, and the stage boundary is the natural place to log, time, and fail. |
| **Interface plus adapter** | AI analysis | Lets the entire pipeline be tested deterministically with a fake, and lets the provider change without touching orchestration. |
| **Contract validator as single authority** | Report payload | Every writer — model output and operator override alike — passes the same gate, so no path can persist a malformed customer-facing payload. |
| **Write-time entitlement resolution** | Unlock state | Rendering stays trivial and fast, and entitlement cannot drift when a subscription later lapses. A report that was paid for stays paid for. |
| **Query scope as authorization boundary** | Customer dashboard | Filtering at the single query every surface flows through leaves no unscoped resolution path, which a per-action policy check would. |
| **Signed URL as capability** | Reports, status, unlock, prepaid run | Removes the account requirement from the free funnel while still proving inbox control. |
| **Intent record plus idempotent listener** | Purchase fulfilment | Survives duplicate webhooks and out-of-order delivery without provider-specific logic. |
| **Facade with graceful fallback** | Email routing | One entry point makes coverage auditable, and the fallback makes the dependency non-critical. |
| **Provider abstraction** | Payments | Keeps five providers from leaking into domain code. |
| **Event-driven side effects** | Registration, orders, referrals | Keeps entitlement grants and ownership linking out of request handlers. |
| **Configuration-driven limits** | Guardrails and pricing facts | One authoritative value per fact, consumable by backend logic, operator interface, and marketing copy alike. |
| **Design tokens as single source** | Visual identity | One palette and type scale, consumed by two independently built applications. |
| **Deferred derivation** | Deltas, benchmark | Keeps the stored payload an immutable record while allowing comparative context to improve over time. |

**Rejected alternatives, recorded** **[R]**

- *Human review before delivery* — rejected; speed is the product. Compensated by deterministic scoring and conservative templating.
- *Credit ledger for runs and unlocks* — rejected for now; subscription metering plus a single-purpose one-time product covers the demonstrated need without a bespoke ledger.
- *Recomputing entitlement at render time* — rejected; couples rendering to billing state and can revoke paid access.
- *Per-action authorization policies on the customer dashboard* — rejected in favor of query scoping, which has no bypass path.
- *Embedding the email platform as a library* — rejected at design time for framework conflicts; the separate-application alternative was then itself superseded by D1 (2026-08-01), which removed the email platform entirely in favor of direct framework sending.
- *Merging container definitions into one root file* — rejected; it forks the upstream convention and breaks the standard tooling. Composition by inclusion keeps both usable.
- *A marker cookie for signed-in navigation state* — rejected; it goes stale after sign-out and introduces a second source of truth.

---

## 8. Modules and Components

Each module states purpose, responsibilities, operations, inputs and outputs, dependencies, data ownership, and failure handling.

### 8.1 Intake

- **Purpose.** Convert an anonymous submission into an inert, verifiable request.
- **Responsibilities.** Boundary validation; abuse rejection; request creation; verification dispatch; confirmation; post-verification routing; unverified purge.
- **Operations.** `submit`, `verify`, `route`, `markFailed`, `markNeedsFollowup`, `statusUrl`, `purchaseRunUrl`, `purge`.
- **Inputs.** Submission payload; signed confirmation; repository reachability result; entitlement decision.
- **Outputs.** Persisted request; state transitions; funnel events; verification, received, access-needed, quota-exhausted, failure, and operator-notification messages; signed status and prepaid-run URLs.
- **Dependencies.** Entitlement (allowance decision), cloner (preflight only), funnel recorder, mailer.
- **Owns.** The audit request record and its lifecycle, including verification, consent, source, free-run and prepaid flags, failure reason, operator context, pipeline log, and timing fields.
- **Failure handling.** Reachability failure and absent repository are routed as leads. Unexpected failure marks the request failed with a stored reason, logs a failure entry, records a funnel failure, and sends the soft failure message. **[R]** Reachability probing must occur off the confirmation request path.

### 8.2 Repository acquisition

- **Purpose.** Obtain a read-only snapshot within hard limits, without executing anything.
- **Responsibilities.** Preflight reachability; shallow bounded clone; access-token injection for supported hosts; size and timeout enforcement; cleanup.
- **Operations.** `preflight`, `clone`, `cleanup`.
- **Inputs.** Repository URL; request identifier for directory naming; configured limits and access token.
- **Outputs.** A local path, or a not-analyzable condition.
- **Dependencies.** Git binary; configuration; file storage.
- **Owns.** The working directory lifecycle. Owns no database state.
- **Failure handling.** **[R]** Any unreachable, oversized, or unclonable repository raises the not-analyzable condition rather than a generic error. **[R]** Access tokens must be redacted from every error message that can be stored or displayed. **[R]** Cleanup must run unconditionally.

### 8.3 Measurement

- **Purpose.** Produce the factual basis for scores, report content, and model input.
- **Responsibilities.** File and language inventory; duplication heuristic; test presence; tooling and configuration detection; manifest summarization; secret pattern scanning; git-derived facts; hotspot correlation; excerpt selection; delegation to dependency auditing.
- **Operations.** `collect`.
- **Inputs.** A local repository path.
- **Outputs.** A metrics structure (§5.2.5) and a bounded excerpt set.
- **Dependencies.** Dependency auditor; git binary.
- **Owns.** The metrics contract shape. Owns no database state; the request stores the result.
- **Failure handling.** **[R]** Individual detectors must degrade to absent or zero rather than aborting collection. **[R]** Secret matches must never leave the module as values. **[REC]** Repeated git invocations should be consolidated (§18).

### 8.4 Dependency auditing

- **Purpose.** Convert declared dependencies into real vulnerability findings.
- **Responsibilities.** Manifest and lockfile discovery; coordinate extraction; batched external query; result normalization.
- **Operations.** `audit`.
- **Inputs.** Repository path; configured endpoint.
- **Outputs.** Normalized findings attached to metrics.
- **Dependencies.** External vulnerability database.
- **Owns.** Nothing durable.
- **Failure handling.** **[R]** Endpoint failure yields zero findings and never fails the run. **[R]** Unparseable lockfiles yield zero packages. **[REC]** Retry behavior in the error path should not impose real delays on the pipeline (§18).

### 8.5 Scoring

- **Purpose.** Turn measurements into comparable numbers.
- **Responsibilities.** Compute six documented dimension scores from metrics.
- **Operations.** `calculate`.
- **Inputs.** Metrics.
- **Outputs.** A score block.
- **Dependencies.** None.
- **Owns.** The scoring formulas — which must be documented and stable, because scores are compared over time and across the population.
- **Failure handling.** **[R]** Missing metrics must yield defined defaults, never a division error or a null score. Every score must be an integer, since the payload contract requires it.

### 8.6 Analysis

- **Purpose.** Produce the human-readable narrative — summary, ranked risks with evidence, and a prioritized plan.
- **Responsibilities.** Prompt composition from template, metrics, excerpts, and operator context; provider invocation; structured-output enforcement; payload validation; corrective retry; exposing the composed prompt for preview.
- **Operations.** `analyze`, `compose`, `preview`, `validate`.
- **Inputs.** Metrics; excerpts; optional operator context; template setting; model identifier and credential.
- **Outputs.** A validated payload.
- **Dependencies.** Model provider; configuration service for the template override.
- **Owns.** The payload contract and its validator — the single authority reused by the operator override.
- **Failure handling.** **[R]** Invalid output triggers one corrective retry, then fails the stage. **[R]** A template override missing required placeholders must be rejected at save time, not discovered at run time. **[R]** Provider credentials must never appear in logs or user-facing text.

### 8.7 Funnel recording

- **Purpose.** Make conversion measurable.
- **Responsibilities.** Append stage events; aggregate per-stage counts over windows with zero-fill.
- **Operations.** `record`, `counts`.
- **Inputs.** Stage identifier; optional request; optional metadata.
- **Outputs.** Event rows; zero-filled aggregate maps.
- **Dependencies.** None.
- **Owns.** Funnel event rows. Append-only; no update timestamp.
- **Failure handling.** **[R]** Recording must never break the flow it observes. **[R]** Acquisition-funnel stages must not be inflated by dashboard-sourced or scheduled runs. **[REC]** Readers must tolerate absent metadata (§18).

### 8.8 Entitlement

- **Purpose.** Be the single authority on what a given requester may run and see.
- **Responsibilities.** Free allowance limit and usage; allowance consumption marking; subscription allowance resolution; monthly metered usage; remaining dashboard runs; access predicate for navigation and widgets.
- **Operations.** `freeRunsLimit`, `freeRunsUsed`, `hasFreeRun`, `consumeFreeRun`, `subscriptionAllowance`, `dashboardRunsUsedThisMonth`, `remainingDashboardRuns`, `hasAuditAccess`.
- **Inputs.** Email address; user; tenant; plan product metadata; configuration.
- **Outputs.** Counts, limits, and booleans.
- **Dependencies.** Subscription and plan data; user bonus counter.
- **Owns.** The allowance rules. Does **not** own the report unlock field — that belongs to the report lifecycle.
- **Failure handling.** **[R]** Absent subscription or plan metadata must resolve to zero allowance, never to unlimited. **[R]** Consumption must be idempotent per request so retries cannot double-count.

### 8.9 Report lifecycle

- **Purpose.** Persist, entitle, render, and deliver results.
- **Responsibilities.** Creation with entitlement resolution; PDF rendering for entitled reports; signed URL issuance; delivery; unlock application; retry-safe regeneration.
- **Operations.** `create`, `send`, `unlock`, `signedUrl`.
- **Inputs.** Request; validated payload; optional order.
- **Outputs.** Report row; PDF artifact; signed URL; ready and unlocked messages; funnel events.
- **Dependencies.** Mailer; file storage; PDF renderer; funnel recorder.
- **Owns.** The report record, including the unlock field and its order reference — the single entitlement authority for rendering.
- **Failure handling.** **[R]** Regeneration must carry forward an existing unlock and regenerate the PDF. **[R]** A locked report's download must be refused. **[R]** Delivery failure must leave the recoverable pre-delivery state rather than a false success. **[REC]** Carrying an unlock forward currently restamps the unlock time rather than preserving the original (§18).

### 8.10 Comparative context

- **Purpose.** Give a score meaning relative to the population and to the repository's own history.
- **Responsibilities.** Population percentile with a minimum-sample suppression rule and caching; per-repository delta against the previous audit.
- **Operations.** `percentileFor`, `deltaFor`.
- **Inputs.** A score; a report and its repository identity.
- **Outputs.** A percentile or nothing; a delta set.
- **Dependencies.** Completed report population.
- **Owns.** Nothing durable; derived at view time by design.
- **Failure handling.** **[R]** Below the configured minimum sample, the percentile must be suppressed entirely rather than shown as a small-sample artifact. **[R]** Absence of a prior audit must render as no-comparison, not as zero movement.

### 8.11 Scheduling

- **Purpose.** Turn one-off audits into a recurring habit.
- **Responsibilities.** Persist per-repository schedules; run due schedules against remaining allowance; record last-run time.
- **Operations.** `setSchedule`, `runDue`.
- **Inputs.** User; tenant; repository URL; frequency; allowance state.
- **Outputs.** Dispatched runs; updated last-run times.
- **Dependencies.** Entitlement; pipeline dispatch; scheduler.
- **Owns.** Schedule records.
- **Failure handling.** **[R]** Insufficient allowance must skip the run without consuming anything and without failing the batch. **[R]** The task must not overlap itself and must run on one node only. **[REC]** Uniqueness per user and repository is not enforced at the database level (§18).

### 8.12 Mail routing

- **Purpose.** Guarantee every audit message is both sent and accounted for.
- **Responsibilities.** Render; log; send through the framework mailer; record outcome; support resend from the stored rendering. *(Revised by D1: platform delivery and status refresh removed.)*
- **Operations.** `send`, `resend`.
- **Inputs.** A message object; recipient; related request.
- **Outputs.** Delivered mail; a log row.
- **Dependencies.** Mail transport.
- **Owns.** Email log rows.
- **Failure handling.** **[R]** A send failure marks the row failed with the reason recorded. **[REC]** Render failure should be recorded rather than preventing a row from existing; the resend path should have explicit exception handling; the attempt counter should be incremented atomically (§18).

### 8.13 Customer dashboard

- **Purpose.** Give an owner visibility and control over their own audits.
- **Responsibilities.** Ownership-scoped list and detail; statistics and recent-audit widgets; launch, schedule, and trends; navigation visibility.
- **Operations.** Read-only browse; launch; schedule.
- **Inputs.** Authenticated user; ownership scope; entitlement state.
- **Outputs.** Rendered surfaces; dispatched runs; saved schedules.
- **Dependencies.** Ownership scope; entitlement; report lifecycle for links.
- **Owns.** No domain state; a strict consumer.
- **Failure handling.** **[R]** A foreign identifier must yield not-found. **[R]** Widgets and navigation must hide entirely — not render empty — when the user has neither audits nor allowance. **[R]** Every status must have a plain-language description; an unmapped status is a defect.

### 8.14 Operator administration

- **Purpose.** Give operators complete control without a deployment.
- **Responsibilities.** Audit search, inspection, and editing; lifecycle actions; validated results override; prompt settings; pipeline log and timing display; funnel report; statistics; email log with resend and refresh.
- **Operations.** Browse, edit, retry, launch, grant unlock, mark handled, override results, save settings, resend, refresh statuses.
- **Inputs.** Administrator session; audit and email-log records; configuration.
- **Outputs.** Mutated records; dispatched runs; granted entitlements; resent mail.
- **Dependencies.** Pipeline dispatch; report lifecycle; analysis module's validator and prompt preview; configuration service; mail routing; funnel statistics.
- **Owns.** The operator prompt-template setting and per-audit operator context (through the request record).
- **Failure handling.** **[R]** Override input must be rejected by the canonical validator before any write. **[R]** Settings pages must be permission-gated, and that gating must be exercised by tests at the route level, not only at the component level. **[R]** Statistics that read another module's table must tolerate its absence. **[R]** Resend must require explicit confirmation showing last recipient and time.

### 8.15 Marketing site

- **Purpose.** Establish positioning and capture requests.
- **Responsibilities.** Content; audit modal with specific error handling and analytics hooks; legal pages; SEO and social surface; session-aware navigation; product-path section; sample-report link.
- **Operations.** Static render; submit; probe session.
- **Inputs.** Site configuration including the product URL; optional analytics identifier.
- **Outputs.** Static output; submissions; navigation state.
- **Dependencies.** Product application endpoints only.
- **Owns.** Content and presentation. No domain state.
- **Failure handling.** **[R]** Submission failure must show a class-specific message, preserve entered data, and allow retry. **[R]** Session probe failure must leave the signed-out default. **[R]** Absent analytics configuration must render no analytics code. **[R]** Every monetary and quota claim must match backend configuration.

---

## 9. Data Architecture

### 9.1 Entities

**Audit domain** **[R]**

| Entity | Purpose | Key attributes |
| --- | --- | --- |
| **Audit request** | The unit of work and the funnel subject. | Public identifier; requester name and email; repository URL; message; status; verification time; marketing consent and consent time; free-run flag; prepaid flag; source (web or dashboard); optional owning user; failure reason; operator context; pipeline log; analysis start and completion times; submission metadata; collected metrics. |
| **Audit report** | The deliverable and the entitlement subject. | Public identifier; owning request; optional owning user; validated payload; optional PDF path; unlock time; optional unlocking order. |
| **Audit funnel event** | Append-only conversion telemetry. | Optional related request; stage; optional metadata; creation time only. |
| **Audit schedule** | A recurring-run intent. | Owning user; tenant; repository URL; frequency; last run time. |
| **Audit email log** | Delivery accountability. | Optional related request; message type; recipient; rendered subject and body; platform identifier; status; attempts; last error; last attempt time. |

**Platform domain** (provided by the multi-tenant foundation) **[R]**

Users, tenants, subscriptions, plans and plan prices, products, one-time products, orders and order items, transactions, discounts, referrals, roles and permissions, user parameters, and configuration overrides. Two extensions are audit-specific: a per-user bonus free-run counter, and user parameters used to carry purchase intent across a checkout round trip.

### 9.2 Relationships

```
User ──1:N──▶ AuditRequest (nullable; set at creation or backfilled on registration)
User ──1:N──▶ AuditReport  (nullable; same rule)
User ──N:1──▶ Tenant ──1:N──▶ Subscription ──N:1──▶ Plan ──N:1──▶ Product
AuditRequest ──1:1(effective)──▶ AuditReport      (cascade on request delete)
AuditRequest ──1:N──▶ AuditFunnelEvent            (null on request delete)
AuditRequest ──1:N──▶ AuditEmailLog               (null on request delete)
AuditReport  ──N:1──▶ Order                       (null on order delete; unlock audit trail)
User ──1:N──▶ AuditSchedule ──N:1──▶ Tenant       (cascade on either delete)
Order ──1:N──▶ OrderItem ──N:1──▶ OneTimeProduct
```

**[R]** Deletion semantics are deliberate: a request's report is removed with it, while its funnel events and email logs survive as an audit trail with a null reference. **[R]** Consequently every reader of those tables must tolerate a null request reference.

### 9.3 Ownership boundaries

| Data | Owning module | Writers |
| --- | --- | --- |
| Audit request lifecycle, verification, consent, flags, failure reason | Intake | Intake, pipeline (status and timing), operator administration (edit) |
| Collected metrics | Pipeline | Pipeline only |
| Pipeline log and analysis timing | Pipeline | Pipeline, intake (failure entry) |
| Operator context and prompt template setting | Operator administration | Operator administration only |
| Report payload | Report lifecycle | Report lifecycle (from analysis), operator administration (validated override) |
| Report unlock state | Report lifecycle | Report lifecycle (creation, unlock), order listener, operator grant |
| Funnel events | Funnel recorder | Funnel recorder only |
| Email log | Mail routing | Mail routing, operator resend and refresh |
| Schedules | Scheduling | Scheduling |
| Allowance rules | Entitlement | No durable state; reads request flags, user bonus, subscriptions |
| Billing state | Payment abstraction | Provider webhooks only |

**[R]** No module may write another module's data directly. In particular, rendering must never write entitlement, and the customer dashboard must write nothing but schedules and launched runs.

### 9.4 Storage requirements

| Data | Store | Notes |
| --- | --- | --- |
| All domain entities | Relational database | **[R]** Payload, metrics, pipeline log, and event metadata as JSON columns. |
| Cloned repositories | Local ephemeral disk | **[R]** Per-request directory; deleted in all paths; sized for the configured maximum multiplied by worker concurrency. |
| Generated PDFs | File storage | **[R]** Retained for entitled reports; regenerated on re-run. |
| Cached derived values | Cache store | **[R]** Benchmark distribution cached with a bounded lifetime. |
| Queue payloads | Queue store | **[R]** Dedicated audit queue with a visibility timeout above the job timeout. |
| Rendered message bodies | Relational database | **[R]** Retained in the email log so resend reproduces the original. **[Q]** Retention period undefined (§19). |

**[REC]** The rendered-body column is the fastest-growing audit-domain column. Either cap its retention or move older bodies out of the primary table.

### 9.5 Lifecycle

| Entity | Created | Mutated | Terminal | Deleted |
| --- | --- | --- | --- | --- |
| Audit request | On submission | Verification, routing, every pipeline stage, operator edits | `sent`, `failed`, `handled`, `needs_followup` | **[R]** Purged if unverified past the window; **[Q]** no policy for verified requests |
| Audit report | On payload persistence | Unlock; validated override; regeneration on re-run | Delivered | **[R]** Cascades with its request |
| Funnel event | At each stage | Never — append-only | — | **[Q]** No policy |
| Email log | Per send attempt | Status, attempts, error, last attempt | Delivered or bounced | **[Q]** No policy |
| Schedule | On user save | Frequency change; last-run stamp | Removed by user | Cascades with user or tenant |
| PDF artifact | On entitled creation | Replaced on re-run | — | **[Q]** No orphan-cleanup policy |
| Working directory | On clone | — | — | **[R]** Deleted at end of every run |

### 9.6 Audit history

- **[R]** The pipeline log on each request is the per-run execution record: step, message, timestamp.
- **[R]** The funnel event table is the conversion record.
- **[R]** The email log is the communication record.
- **[R]** The unlocking order reference on a report is the monetary audit trail for an entitlement grant.
- **[REC]** Operator mutations — status edits, results overrides, unlock grants, context changes — are **not** currently attributed to an actor with a timestamp. For a surface that can alter customer-facing content and grant paid access, an actor-attributed change log should be added (§18, §19).

### 9.7 Retention and deletion

- **[R]** Unverified requests are purged after the configured window.
- **[R]** Cloned source is never retained.
- **[R]** Secret values are never stored.
- **[Q]** Retention for verified requests, reports, PDFs, funnel events, and email logs — including message bodies — must be decided (§19).
- **[Q]** A subject-access and erasure procedure must be defined, including how erasure interacts with the funnel and email audit trails and with the order-linked unlock reference (§19).
- **[REC]** Anonymize rather than delete on erasure where a financial or audit trail must survive: clear personal fields, retain the row and its references.

### 9.8 Caching and indexing

**Caching** **[R]**

| Value | Strategy |
| --- | --- |
| Benchmark distribution | Cached with a bounded lifetime; recomputed on expiry. |
| Configuration and routes | Cached at deploy; cache must be rebuilt as part of release. |
| Static assets | Immutable long-lived caching on fingerprinted filenames. |
| Session-status response | **[R]** Explicitly no-store. |

**Indexing** **[R]**

Required: unique indexes on both public identifiers; indexes on requester email, status, and the free-run flag; an index on funnel stage and on funnel creation time; indexes on email-log status and platform identifier; foreign-key indexes throughout.

**[REC]** Add composite indexes to match the actual read patterns: status with creation time (operator list default sort and the statistics buckets), owning user with creation time (dashboard list), and email with the free-run flag (allowance counting). **[REC]** Repository URL is queried for grouping and trends; it is a long column, so index a normalized form rather than the raw value if trend queries become slow.

### 9.9 Search

- **[R]** Operator audit search must cover requester name, email, and repository URL, with status and date-range filters.
- **[R]** Email-log search must cover recipient, with status and type filters.
- **[R]** Customer audit filtering must cover status and submission date within the ownership scope.
- **[REC]** Relational `LIKE`-based search is sufficient at current volume. A dedicated search index is unnecessary and should not be introduced until operator search latency is demonstrably a problem.
- **[R]** Report payload content is **not** searchable and is not required to be.

---

## 10. API and Integration Design

### 10.1 API groups

| Group | Audience | Auth model | Members |
| --- | --- | --- | --- |
| **Public intake** | Marketing site, unauthenticated | None; origin-allowlisted, honeypot, throttled | Submit audit request |
| **Session status** | Marketing site, unauthenticated or authenticated | Session cookie, credentialed cross-origin | Report authentication boolean |
| **Signed capability** | Email recipients | Signed URL, no session | Verify email; status page; status JSON; unlock; prepaid run; view report; download PDF |
| **Public** | Anyone | None | Sample report; pricing; legal |
| **Authenticated web** | Customers | Session | Checkout, subscription management, both panels |
| **Inbound webhooks** | Payment providers | Provider signature verification | Order and subscription lifecycle events |
| **Outbound integrations** | The platform itself | Per-provider credentials | Model provider; vulnerability database; mail transport; git remotes |

**[R]** The public HTTP API surface is deliberately minimal: one write endpoint and one read endpoint. Everything else is either signature-gated, session-gated, or an inbound webhook. **[R]** There is no general-purpose public API in this phase.

### 10.2 External integrations

| Integration | Direction | Purpose | Failure requirement |
| --- | --- | --- | --- |
| **Git remotes** | Outbound | Preflight and shallow clone | **[R]** Failure is a lead-generating not-analyzable condition, never a run failure. Access tokens redacted from all error text. |
| **Model provider** | Outbound | Narrative analysis | **[R]** Structured output enforced; one corrective retry on invalid payload; then stage failure. Credential from environment only. |
| **Vulnerability database** | Outbound, batched | Real dependency findings | **[R]** Degrades to zero findings; never fails a run. |
| **Mail transport (SMTP/ESP)** | Outbound | Transactional send | **[R]** A send failure marks the log row failed with the reason; resend is an explicit operator action. Audit email must never silently stop. |
| **Payment providers** (five, abstracted) | Outbound checkout, inbound webhooks | Billing | **[R]** Order-level idempotency; no provider specifics in domain code. |
| **Analytics vendor** | Client-side | Funnel events on the marketing site | **[R]** Absent configuration renders no code and breaks nothing. |

### 10.3 Authentication and authorization

**[R]** Four distinct models, each with a defined scope:

1. **Session authentication** for customers and operators. Post-authentication destination rules per §5.9.4.
2. **Signed URLs as capabilities.** Possession of a signed link delivered to a verified address is the authorization. Applies to verification, status, report view, PDF download, unlock, and prepaid run.
3. **Panel gate** for the operator surface — administrator status checked at panel level, before any resource.
4. **Query scope** for the customer surface — ownership enforced in the single query every read flows through.

**[R]** Signature lifetimes must differ by purpose: the verification link is short-lived; the status link must be durable, because it is the requester's long-term handle on the run; report links are bounded by a configured window.

**[R]** The auto-account rule is a security boundary, not a convenience: an account may be created and signed in from a signed link **only when no account exists for that address**. Where one exists, the visitor must be redirected to sign in.

### 10.4 Webhooks and event handling

- **[R]** Provider webhooks are the only mechanism by which billing state enters the system. Signature verification is mandatory.
- **[R]** Order completion drives three possible outcomes, resolved in a fixed order: consume an unlock intent; consume a run intent; otherwise fall back to unlocking the acting user's most recent locked report.
- **[R]** A consumed-but-dangling intent must short-circuit rather than falling through to the fallback.
- **[R]** Internal domain events must drive registration linking, referral bonus accrual, and order fulfilment — not inline branches in controllers.
- **[R]** Verification must dispatch routing as an event-driven job rather than performing it inline.

### 10.5 Idempotency

**[R]** Required at five points:

| Point | Mechanism |
| --- | --- |
| Email confirmation | Guarded on the verification timestamp; a second click is a no-op. |
| Order fulfilment | Order-level guard; a duplicate webhook grants nothing twice. |
| Report creation on retry | Existing report is replaced in place, carrying entitlement forward; never duplicated. |
| Free-allowance consumption | Marked on the consuming request; retries cannot re-consume. |
| Reminder sends | Per-report or per-request markers prevent double reminders. |

**[REC]** Two remaining races should be closed: intent read-then-delete is not transactional, and the reminder check-then-create is not atomic across overlapping scheduler runs (§18).

### 10.6 Rate limiting

- **[R]** The intake endpoint must be throttled per client address.
- **[R]** Per-email deduplication within a short window must reject rapid resubmission, with a response the client can distinguish so it can direct the visitor to their inbox.
- **[REC]** The session-status endpoint should also be throttled; it is unauthenticated and cheap to call in a loop.
- **[REC]** Outbound calls to the model provider and the vulnerability database should be bounded by worker concurrency, which is the natural throttle. If provider limits are hit, reduce worker concurrency rather than adding a bespoke limiter.
- **[Q]** No concrete threshold policy has been set for signed-URL brute forcing; identifiers are unguessable, so the risk is low, but a limit is cheap (§19).

### 10.7 Versioning

- **[R]** The intake endpoint is consumed only by the marketing site in the same repository. Both deploy from one source of truth, so no version negotiation is required in this phase.
- **[R]** Any breaking change to the intake contract must ship with the marketing-site change that consumes it.
- **[REC]** If a third-party client is ever admitted, introduce a version prefix at that point and freeze the current shape as version 1.
- **[R]** The report payload contract is a versioned-in-practice structure: stored payloads must remain renderable. Any change must either be additive or accompanied by a migration of stored payloads. **[REC]** Adding an explicit schema-version field to the payload would make that obligation enforceable (§18).

### 10.8 Retry and error handling

| Layer | Strategy |
| --- | --- |
| Pipeline job | **[R]** Bounded attempts with increasing backoff; final failure marks the request failed with a reason, appends a log entry, records the funnel failure, and notifies the requester softly. |
| Model analysis | **[R]** One corrective retry on contract violation, then fail the stage. |
| Vulnerability database | **[R]** Degrade to zero findings. No pipeline-visible retry delay. |
| Mail transport | **[R]** No bespoke retry inside the mailer; failure is recorded on the log row and surfaces to the caller's queue retry policy. Resend is an explicit operator action. |
| Repository operations | **[R]** No retry for deterministic failures (missing, private, oversized). Bounded retry for transient network conditions. |
| Provider webhooks | **[R]** Idempotent handling makes provider-side retries safe. |
| Client submission | **[R]** No automatic retry; a class-specific message with entered data preserved, so the visitor retries deliberately. |

**[R]** Two error taxonomies must remain distinct and must not be conflated: *not analyzable* (a lead — route to a follow-up state, guide the requester) and *failed* (an error — record the reason, notify softly, expose for retry).

---

## 11. Security Architecture

### 11.1 Authentication model

- **[R]** Session-based authentication for customers and operators, with the platform's standard registration, sign-in, password reset, email verification, and second-factor flows.
- **[R]** Signed URLs as bearer capabilities for email-delivered actions, with purpose-appropriate lifetimes (§10.3).
- **[R]** Automatic account creation from a signed link only when no account exists for the address; never a sign-in to an existing account.
- **[R]** An account created this way is marked email-verified — the signed link already proved inbox control — and receives a random password recoverable through the standard reset flow.
- **[R]** Post-authentication destination must never be taken from an external or marketing-page referrer.

### 11.2 Authorization and roles

| Principal | Boundary | Enforcement |
| --- | --- | --- |
| Anonymous visitor | Public content, intake, sample report | Route-level |
| Signed-link holder | Exactly the one request or report the signature names | Signature verification plus record binding |
| Authenticated customer | Own audits and reports; own tenant's billing | **[R]** Ownership query scope; tenant scoping for billing |
| Operator | Everything | **[R]** Administrator check at panel level |
| Permission-gated operator surfaces | Settings and funnel report | **[R]** Explicit permission check, exercised by route-level tests |

**[R]** The asymmetry is intentional: the customer surface is ownership-scoped at the query level because it is reachable by any authenticated user; the operator surface relies on the panel gate because no non-administrator can reach it at all.

### 11.3 Tenant isolation

- **[R]** Billing, subscriptions, and orders are tenant-scoped.
- **[R]** Audits are user-scoped, not tenant-scoped, because they predate accounts. The ownership scope is the isolation boundary.
- **[R]** The scope's identifier-or-email clauses must be grouped so that adding further conditions cannot widen it into a top-level disjunction.
- **[R]** A request for another user's audit must yield not-found, revealing nothing about existence.
- **[Q]** Team-wide visibility within a tenant is undecided; if adopted, the scope becomes the single change point (§19).

### 11.4 Secrets management

| Secret | Handling |
| --- | --- |
| Model provider credential | **[R]** Environment only. Never logged, never displayed. |
| Repository access token | **[R]** Configuration only; injected for supported hosts only; **redacted from every stored and displayed error message**. |
| Mail transport credential | **[R]** Environment only. |
| Payment provider credentials | **[R]** Environment only, per provider. |
| Application key, database and cache credentials | **[R]** Environment only. |
| Seeded provider identifiers | **[R]** Must be unmistakably marked as test placeholders so they can never be confused with live credentials. |

**[R]** No secret may appear in seed data, fixtures, committed configuration, log output, or user-facing error text.

### 11.5 Encryption

- **[R]** All public traffic over TLS. The session cookie must be secure-flagged, and in production its domain scoped to permit the credentialed cross-origin session probe.
- **[R]** Signed URLs must be tamper-evident; any modification invalidates them.
- **[REC]** Encryption at rest for the database and file storage should be provided at the infrastructure layer.
- **[REC]** Application-level encryption is not required for current data classes, since no secret values and no payment instruments are stored. If message bodies in the email log come to contain sensitive customer content, revisit.

### 11.6 Input validation

- **[R]** Intake input validated at the boundary with bounded lengths, format checks, and a honeypot.
- **[R]** Repository URLs validated as URLs; host support determined explicitly rather than inferred.
- **[R]** Model output validated against the payload contract before persistence.
- **[R]** Operator override input validated by that same canonical validator; a bespoke spot-check is not acceptable, because it would let a malformed payload reach a customer-facing page.
- **[R]** Prompt-template overrides validated for required placeholders at save time.
- **[R]** All rendered output escaped; repository URLs and requester-supplied text must be treated as untrusted in every surface, including operator interfaces and action attributes.
- **[R]** Metrics and payload JSON must be validated on read where shape is assumed, since payloads may have been overridden by an operator.

### 11.7 Audit logging

- **[R]** Pipeline step log per run; funnel events per stage; email log per send.
- **[R]** Unlock grants via order carry the order reference.
- **[REC]** Operator mutations are not attributed to an actor. Given that operators can rewrite customer-facing results and grant paid access, an actor-attributed, timestamped change log is a security control, not just an operational nicety (§18, §19).
- **[REC]** Sign-in events, permission changes, and administrator actions should be recorded.

### 11.8 Abuse prevention

| Vector | Control | Status |
| --- | --- | --- |
| Bot submissions | Honeypot field | **[R]** Implemented requirement |
| Submission flooding | Per-address throttle plus per-email dedupe window | **[R]** |
| Free-allowance farming via disposable addresses | Email verification gate; allowance keyed per address | **[R]** Partial mitigation only — **[A]** accepted at current scale |
| Analysis-cost abuse via huge repositories | Size, depth, timeout, and excerpt limits | **[R]** |
| Analysis-cost abuse via unverified submissions | Nothing runs before verification | **[R]** |
| Report-link sharing to reach an existing account | Never auto-sign-in when an account exists | **[R]** |
| Duplicate-webhook double grant | Order-level idempotency | **[R]** |
| Repository-token leakage through error messages | Mandatory redaction | **[R]** |
| Catastrophic regex input | Linear-time patterns required | **[R]** |
| Session-status endpoint abuse | **[REC]** Throttle; currently unthrottled | **[REC]** |
| Disposable-domain signups | **[REC]** Optional domain blocklist if farming is observed | **[REC]** |

### 11.9 Risks and mitigations

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| S1 | Cloned repository code is executed by a future change | Remote code execution on a worker | **[R]** Absolute prohibition; **[REC]** enforce with a test asserting no build, install, or script invocation exists in the pipeline path |
| S2 | Repository access token leaks into a stored failure reason | Third-party repository compromise | **[R]** Redaction at the cloner boundary; **[REC]** a test asserting the token never appears in a persisted failure reason |
| S3 | Operator override injects malicious or malformed content into a customer-facing report | Content injection, broken pages | **[R]** Canonical validation plus output escaping |
| S4 | Ownership scope is widened by a later query composition | Cross-customer data exposure | **[R]** Grouped clauses; single scope definition; **[R]** cross-user isolation tests |
| S5 | Signed report link forwarded or leaked | Unintended report disclosure | **[R]** Bounded lifetime; **[R]** never auto-sign-in to an existing account; **[A]** accepted residual risk of intentional sharing |
| S6 | Session-status endpoint scope creep | Information disclosure | **[R]** Boolean-only contract; **[REC]** a test asserting the response body contains nothing else |
| S7 | Wildcard cross-origin configuration introduced with credentials | Session theft | **[R]** Configured origin only; **[REC]** a test asserting no wildcard with credentials |
| S8 | Model prompt injection via repository content steering the report | Misleading customer output | **[R]** Deterministic scores override model scores; **[REC]** treat excerpts as untrusted data in the prompt and instruct the model accordingly |
| S9 | Excerpts containing customer secrets are transmitted to the model provider | Third-party exposure of customer credentials | **[R]** Disclose in the privacy policy; **[REC]** exclude files matching secret patterns from excerpt selection |
| S10 | Email log message bodies accumulate sensitive content indefinitely | Growing breach surface | **[Q]** Retention policy undefined (§19) |
| S11 | Unattributed operator action | No accountability for a paid-access grant or content change | **[REC]** Actor-attributed change log |

**[REC]** S9 deserves attention: the excerpt selector currently picks the largest, most-duplicated, and entry-point files. It is possible for a file containing detected secrets to be selected as an excerpt. Since the secret scanner already knows which files matched, excluding those files from excerpt selection is a small change with a real privacy benefit.

---

## 12. Background Jobs and Event Processing

### 12.1 Asynchronous workflows

| Workflow | Trigger | Work |
| --- | --- | --- |
| **Post-verification routing** | Email confirmation | Probe reachability; select branch; dispatch or route |
| **Audit generation** | Routing, operator launch, operator retry, paid run intent, dashboard launch, schedule | The full pipeline |
| **Email dispatch** | Any mailer call | Render, log, deliver |
| **Unverified purge** | Daily schedule | Delete requests unverified past the window |
| **Verification reminder** | Daily schedule | Remind unconfirmed requesters after ~24 hours |
| **Unlock reminder** | Daily schedule | Remind recipients with an abandoned unlock checkout |
| **Scheduled re-audits** | Daily schedule | Run due schedules within remaining allowance |

**[R]** Routing must be asynchronous so the confirmation click is not blocked on a remote probe.

### 12.2 Queues and events

- **[R]** Audit work runs on a dedicated queue with its own connection, whose visibility timeout exceeds the pipeline job timeout. This prevents a still-running job from being re-delivered concurrently.
- **[R]** Transactional email must not share a queue with audit work.
- **[R]** Domain events drive: order completion (entitlement grant or prepaid dispatch), referral reward (bonus accrual), user registration (report and request ownership backfill), and verification (routing dispatch).
- **[R]** Funnel events are database rows, not queue messages; they are a read model, not a transport.

### 12.3 Scheduling

| Task | Cadence | Constraints |
| --- | --- | --- |
| Unverified purge | Daily, off-peak | — |
| Verification reminder | Daily, business-hours | **[R]** Per-request marker |
| Unlock reminder | Daily, shortly after the above | **[R]** Per-report marker |
| Scheduled re-audits | Daily | **[R]** Non-overlapping and single-node |
| Platform maintenance tasks | Per platform defaults | — |

**[R]** Reminder tasks must run at a defensible local hour, not at an arbitrary time, because they are customer-facing.

### 12.4 Retries

- **[R]** Pipeline job: bounded attempts with increasing backoff. Transient conditions retry; deterministic conditions do not.
- **[R]** Analysis: one corrective retry on contract violation.
- **[R]** Email: no retry — immediate fallback, then explicit operator resend.
- **[R]** Vulnerability database: degrade rather than retry into the pipeline's latency budget.
- **[R]** A retry must never duplicate a report or revoke an entitlement.

### 12.5 Dead-letter handling

- **[R]** Exhausted pipeline jobs must invoke a failure handler that marks the request failed with a reason, appends a log entry, records the funnel failure, and notifies the requester.
- **[R]** A failed request must remain retryable by an operator; failure is never a dead end.
- **[REC]** Failed queue jobs should be retained in the platform's failed-job store, and their count should be alerted on. No alerting is currently specified (§15).
- **[REC]** Email-log rows in a failed state are the effective dead-letter queue for communication; the operator statistics surface should make a rising count visible, and it should be alerted on.

### 12.6 Duplicate prevention

**[R]** Per §10.5. **[REC]** Two remaining gaps to close: transactional intent read-and-delete, and atomic reminder marker creation (§18).

### 12.7 Monitoring

- **[R]** A queue monitor must be available to operators, with audit-queue depth surfaced as a statistic linking to it.
- **[R]** Per-run step logs and timings must be visible per audit.
- **[R]** Average processing time must be computed from recorded timings and must display an explicit no-data indicator rather than a misleading zero.
- **[REC]** Alerting on queue depth, failed-job count, oldest-pending age, and email failure rate is not yet specified (§15, §19).

---

## 13. User Interface Structure

### 13.1 Surfaces and navigation

**Marketing site** **[R]** — single-page primary route plus legal pages.

```
/                  Hero · Services · Process · Trust · Health report visual ·
                   Product path · FAQ · Closing CTA
                   Header: wordmark · anchors · primary CTA (Free audit)
                            + session-aware entry (Sign in ⇄ Dashboard)
                   Footer: legal · contact
/privacy  /terms   Legal
/404               Not found
Modal              Free audit request (name, email, repo URL, message,
                   consent, honeypot) → sent state / class-specific error
```

**Product application, public** **[R]**

```
/                        → pricing (guest) or dashboard (authenticated)
/pricing                 Catalog: one-time unlock + three subscription tiers
/login /register /...    Authentication and recovery flows
/checkout/*              Provider-abstracted checkout and thank-you pages
/terms /privacy          Legal
/audit-requests/{id}/verify        Signed → redirects to status
/audit-requests/{id}/status        Signed, durable, polling
/audit-requests/{id}/status.json   Signed polling endpoint
/audit-requests/{id}/purchase-run  Signed → checkout
/reports/sample                    Public sample
/reports/{id}                      Signed hosted report
/reports/{id}/download             Signed, entitled only
/reports/{id}/unlock               Signed → account resolution → checkout
```

**Customer dashboard** **[R]**

```
Dashboard      Statistics tiles · Recent audits · Account
Audits         List (read-only, filtered) → Detail (project · status ·
               timeline · results with report links)
Audit reports  Launch · Schedules · Repository trends
Subscription   Plan, billing, invoices
```

**Operator administration** **[R]**

```
Dashboard        Platform metrics · Audit statistics · Audits by plan
Audits           List (search, filters) → View (request · organization ·
                 operator context · next-run prompt preview · pipeline log
                 and timing · report · actions) → Edit
                 Actions: retry · launch · grant unlock · mark handled ·
                          override results
Audit emails     Log (read-only) · Resend
Audit funnel     Stage counts, 7 and 30 day, with conversion shares
Audit settings   Prompt template (validated) with built-in default shown
Platform         Users · tenants · plans · products · orders · subscriptions ·
                 transactions · discounts · referrals · roles · providers
```

### 13.2 Key flows

**[R]** Each flow must be completable without leaving the surface it starts on, except where a payment provider requires a redirect.

| Flow | Steps |
| --- | --- |
| Request an audit | Landing → modal → submit → "check your inbox" → email → status page → report |
| Unlock a report | Locked report → unlock CTA → account resolution → checkout → confirmation → unlocked report |
| Buy a run after quota | Quota email → prepaid link → account resolution → checkout → queued → report |
| Grant private access | Access email → add collaborator → operator launch → report |
| Subscribe | Pricing or locked report → checkout → dashboard with allowance |
| Run from dashboard | Audits → launch → status → unlocked report |
| Schedule re-audits | Audit reports → set frequency → recurring reports with deltas |
| Operator correction | Audits → search → view → edit or override → verify on hosted report |
| Operator email recovery | Audit emails → filter failed → inspect error → confirm resend |

### 13.3 Dashboard requirements

**Customer** **[R]**

- Four statistics tiles: remaining analyses this period, in progress, completed, failed.
- The remaining-analyses tile must label its basis explicitly — subscription allowance with usage percentage, or free runs remaining. A user must never be left guessing which number they are looking at.
- A recent-audits table linking into detail.
- Both widgets, and the audits navigation entry, hide entirely when the user has neither audits nor allowance.
- Navigation must appear for an entitled subscriber before their first report exists.

**Operator** **[R]**

- Volume: total, today, this week, this month.
- Health: pending, analyzing, completed, failed, requiring manual action.
- Throughput: average processing time, with an explicit no-data indicator.
- Communication: email failure count.
- Capacity: audit queue depth, linking to the queue monitor.
- Revenue context: current-month audits grouped by active plan, with a free bucket.
- **[REC]** Statistics buckets should reconcile to the total. A state that belongs to no bucket makes the tiles quietly inconsistent (§18).

### 13.4 State requirements

**[R]** Every surface must define all five states. Absence of a defined state is a defect, not an omission.

| State | Requirement |
| --- | --- |
| **Empty** | Audit list: explain that no audits exist yet and offer the primary action if allowance permits. Report with no risks: state plainly that no significant risks were found — this is a legitimate, valuable outcome and must never look like a failed render. Funnel with no events: zeros, not blanks. Email log empty: neutral message. |
| **Loading** | Status page: named current stage plus a progress indication, not a bare spinner. Modal submission: disabled control with progress. Dashboard widgets: skeleton or placeholder while hydrating. |
| **Error** | Modal: class-specific message — duplicate recent request, validation problem, or generic — with entered data preserved, retry available, and a contact fallback. Expired link: friendly explanation with a recovery route. Pipeline failure on the customer surface: the stored reason in plain language plus what happens next. Operator override rejection: field-level validation error, nothing saved. |
| **Locked** | Not an error state. Visibly obscured content with an explicit "unlock to reveal" affordance, price, and subscription alternative. **[R]** Must be comprehensible to assistive technology as locked content rather than as unreadable noise. |
| **Permission denied** | Foreign audit: not-found, revealing nothing about existence. Locked PDF download: refused. Unsigned access to a signed route: refused. Non-administrator at the operator panel: no navigation entry and no route access. Permission-gated operator page: hidden and unreachable. |

### 13.5 Content requirements

- **[R]** Every status must have a plain-language description written for a non-specialist.
- **[R]** States requiring user action must present an action callout, not merely a label: access-pending shows the collaborator-invite steps; follow-up-needed points to the email.
- **[R]** All monetary and quota figures shown anywhere must match backend configuration exactly.
- **[R]** No fabricated social proof anywhere.
- **[R]** Report language must be conservative: assessment, not guarantee, with an invitation to reply.

---

## 14. Infrastructure and Deployment

### 14.1 Environments

| Environment | Purpose | Requirements |
| --- | --- | --- |
| **Local** | Development | **[R]** One command boots every surface plus database, cache, and mail catcher. **[R]** Container-created files must not be root-owned on the host. **[R]** Writable directories must self-heal on container start. **[R]** A test database distinct from the development database, with the distinction documented prominently — destructive test commands must not be able to target development data by default. |
| **[REC] Staging** | Pre-release verification | Production-shaped, with payment providers in test mode and a real email-platform instance. Does not currently exist. |
| **Production** | Live | **[R]** Separate credentials, live providers, TLS everywhere, no debug output. |

**[REC]** A staging environment is the single largest infrastructure gap. Several flows — provider checkout, live email delivery, cross-origin credentialed session probing under a real cookie domain — cannot be fully verified locally.

### 14.2 Hosting model

| Surface | Model |
| --- | --- |
| Marketing site | **[R]** Static hosting or CDN. Pre-rendered output; no server runtime. |
| Product application | **[R]** Application server behind TLS. |
| Queue workers | **[R]** Long-running supervised processes. Non-optional. |
| Scheduler | **[R]** One cron entry per environment. |
| Database and cache | **[R]** Managed or dedicated instances. |
| Mail transport | **[R]** External SMTP or email service provider; no self-hosted mail application. |
| File storage | **[R]** Persistent for PDFs; ephemeral local disk for clone working directories. |

### 14.3 Containerization

- **[R]** Local development is fully containerized, composed by including the product application's own container definition rather than forking it, so standard tooling continues to work.
- **[R]** The frontend container must run as the host user so that installed dependencies are not root-owned on the host.
- **[R]** Optional services must sit behind profiles so that booting does not require credentials for tooling nobody is using.
- **[R]** Container entrypoints must repair ownership of writable directories on every start, scoped to mismatched files only. No world-writable permissions anywhere.
- **[R]** Container images must bake in fixes that would otherwise be lost on recreation. A runtime-only repair is not a fix.
- **[R]** Port assignments must be overridable per developer, because collisions with other local projects are routine.

### 14.4 CI/CD

- **[R]** Marketing site: type-check, lint, format check, and build must all pass before merge.
- **[R]** Product application: the full test suite must pass, the formatter must report no changes, and static analysis must introduce no new error category.
- **[R]** Production release must be a repeatable, scripted process with zero-downtime switching and post-release migration and cache-rebuild steps.
- **[REC]** The following are missing and should be added: automated deployment on merge to the default branch, a required staging deployment before production, a smoke-test gate after release, and a documented rollback procedure.
- **[R]** No release may proceed with a failing test suite. A known-flaky test must be identified as such with evidence, never used as a blanket excuse.

### 14.5 Configuration management

- **[R]** All environment-specific values via environment variables. No secrets in the repository.
- **[R]** Every operational limit and pricing fact must have exactly one authoritative configuration entry, consumed by backend logic, operator interfaces, and marketing copy.
- **[R]** Operator-adjustable settings must persist through the platform's configuration-override mechanism, and each such key must be explicitly registered as overridable.
- **[R]** Example environment files must document every required variable, using values that are obviously placeholders.
- **[R]** The marketing site's product-application URL must be environment-overridable at build time.

### 14.6 Backup and disaster recovery

**[REC]** Not currently specified. Proposed baseline:

| Asset | Strategy | Objective |
| --- | --- | --- |
| Database | Automated daily full plus continuous incremental; restore rehearsed quarterly | **[REC]** RPO ≤ 1 hour, RTO ≤ 4 hours |
| Report PDFs | Replicated storage | **[REC]** Regenerable from stored payloads, so lower priority |
| Configuration and secrets | Held in a secret manager with versioning | **[REC]** Recoverable without a database |
| Cloned repositories | **[R]** None — intentionally transient | — |
| Cache and queue | **[REC]** None; queue loss means re-dispatching in-flight audits, which is acceptable and operator-recoverable via retry |

**[Q]** Confirm recovery objectives and whether queue loss is genuinely acceptable (§19).

### 14.7 Scaling

**Horizontal** **[R]**

- Queue workers scale out; the pipeline holds no cross-job state.
- The application scales out behind a load balancer, given shared session and cache stores.
- Fan-out scheduled tasks must be pinned to a single node.

**Vertical** **[REC]**

- Worker memory and disk must accommodate the configured maximum repository size multiplied by concurrency.
- The database is the natural first bottleneck; the read patterns in §9.8 should be indexed before scaling hardware.

**[REC]** The pragmatic scaling order is: add workers, then index for the real read patterns, then add application nodes, then consider read replicas. Do not pre-build for volume that has not arrived.

### 14.8 Environment isolation

- **[R]** Separate databases, credentials, and provider accounts per environment.
- **[R]** The test database must be distinct from the development database, and destructive commands must not default to the development one.
- **[R]** Production must never use test payment credentials; seeded placeholder identifiers must be visibly non-live.
- **[R]** Development mail must be captured locally and must never reach real recipients.
- **[REC]** Production data must not be copied into development without anonymization.

---

## 15. Monitoring and Observability

**[R]** The domain-level observability in §12.7 exists by requirement. **[REC]** The infrastructure-level observability below does not yet exist and should be treated as a production-readiness prerequisite (§20).

### 15.1 Application logs

- **[R]** Structured application logs with environment-appropriate levels; no debug output in production.
- **[R]** No credentials, repository access tokens, or secret values in any log line.
- **[REC]** Centralized aggregation with retention and search. **[REC]** Correlate log lines to an audit request by its public identifier, which is the natural trace key across HTTP, queue, and mail.

### 15.2 Metrics

**[R]** Domain metrics already required: per-stage funnel counts; audit volume by period; status distribution; average processing time; email failure count; audit queue depth.

**[REC]** Add: pipeline duration distribution rather than only the mean; per-stage duration, derived from the existing step log; model provider latency, token consumption, and cost per audit; vulnerability-database failure rate; mail send failure rate; per-scanner failure rate once the tiered scanner set lands (§5.12); HTTP error rates by route class; queue oldest-pending age; worker utilization.

**[REC]** Cost per audit is the most commercially important missing metric. Unit economics for a freemium product with a per-run external model cost cannot be managed without it.

### 15.3 Distributed tracing

- **[REC]** Full tracing is not warranted at current scale. The audit request's public identifier, propagated through HTTP, job, and mail contexts, provides the practical equivalent for the one workflow that spans components.
- **[REC]** Revisit if a third component enters the request path.

### 15.4 Error tracking

- **[REC]** An error tracking service should capture unhandled exceptions with release and environment context, deduplicated and alertable. This is currently absent, and it is the single most valuable observability addition.
- **[R]** Domain failures already have durable homes: failure reasons on requests, pipeline log entries, and email log errors. Error tracking complements these; it does not replace them.
- **[REC]** Notably worth tracking distinctly: analysis contract violations, clone failures by category, and email fallback events — each indicates a different upstream problem.

### 15.5 Health checks

**[REC]** None currently defined. Proposed:

| Check | Verifies |
| --- | --- |
| Liveness | Process responds |
| Readiness | Database and cache reachable; configuration and routes cached |
| Worker health | A worker has processed or polled recently |
| Scheduler health | Scheduled tasks ran within their expected window |
| Dependency health | Model provider, vulnerability database, and mail transport reachable — reported, not gating |

**[R]** A dependency health check must never gate application readiness. Every external dependency has a defined degradation path, and the application must stay up when they are down.

### 15.6 Alerts

**[REC]** Proposed alert set, in priority order:

| Priority | Condition |
| --- | --- |
| **Critical** | Application unreachable; database unreachable; no worker has processed a job within a defined window; audit queue depth or oldest-pending age beyond threshold |
| **High** | Pipeline failure rate above threshold; email failure rate above threshold; model provider errors sustained; unhandled exception rate spike |
| **Medium** | Scheduled task missed; vulnerability database failing; sustained mail send failures; average processing time degraded |
| **Low** | Requests awaiting manual action above threshold; free-allowance consumption anomaly; benchmark sample below the display minimum |

**[R]** The "no worker processing" alert is the highest-value single alert in the system: without workers, audits silently never run, submissions still succeed, and no customer-facing error appears.

### 15.7 Operational dashboards

- **[R]** Operator statistics and funnel report exist by requirement.
- **[REC]** Add an operations view distinct from the business view: queue depth and age, worker status, pipeline duration distribution, external dependency error rates, and cost per audit. The existing statistics widgets answer "how is the business doing"; nothing yet answers "is the machine healthy".

---

## 16. Testing Strategy

### 16.1 Unit testing

**[R]** Required coverage:

- Scoring formulas across the metric space, including missing, zero, and extreme inputs.
- Payload validation: every required field, every enumerated value, and every malformed shape — including non-array and scalar values where arrays are expected.
- Prompt composition: default template when the override is blank, override applied, operator context appended, placeholder validation.
- Metrics collectors individually against fixture repositories, including empty repositories and unknown ecosystems.
- Secret patterns: true positives per pattern, and confirmation that matched values never appear in output.
- Entitlement arithmetic: allowance limits, bonus accrual, usage counting, remaining calculations, and the zero-allowance default when subscription data is absent.
- Status display mapping: every enumerated case, with no fallthrough to a blank.
- Delta and percentile computation, including the below-minimum-sample suppression rule.

### 16.2 Integration testing

**[R]** Required coverage:

- Intake: validation, honeypot rejection, throttling, deduplication, cross-origin headers, and the created-state contract.
- Verification gate: an unverified request runs nothing, consumes nothing, notifies nobody; expired and tampered signatures are rejected; confirmation is idempotent.
- Routing: each of the four branches selected correctly.
- Pipeline: full run against fixture repositories with a faked analyzer, asserting stage sequence, log entries, timing stamps, status transitions, metric persistence, and score override.
- Failure paths: not-analyzable routes to follow-up; transient failure exhausts retries and marks failed with a reason; working directory removal in every path.
- Cloning: access token injected for supported hosts only, and never present in a persisted failure reason.
- Entitlement and purchase: order completion sets the unlock exactly once; wrong or missing intent metadata is a no-op; duplicate webhooks grant nothing twice; prepaid run queues once and produces an unlocked report; retry preserves an existing unlock.
- Account resolution: a guest with no account is created and signed in; an existing account is never auto-signed-in; an unsigned request is refused.
- Rendering: locked versus unlocked sections; PDF refused for locked reports; sample renders; expired link renders the friendly page.
- Mail routing: every one of the ten message types routes through the mailer; a successful send logs as sent and a failure logs its reason; resend reproduces the stored rendering. *(Revised by D1.)*
- Reminders: sent once per subject; a batch survives a bad row.
- Scheduled tasks: purge removes only unverified requests past the window; scheduled audits respect remaining allowance.
- Customer dashboard: cross-user isolation, email-matched ownership, registration backfill, correct widget values across subscribed, free-quota, and exhausted users, and complete hiding for a user with neither audits nor allowance.
- Operator surfaces: edits persist; permission gating is exercised at the route level, not only at the component level; override rejects invalid payloads via the canonical validator and accepts valid ones; widgets compute correctly against seeded data and tolerate a missing sibling table.
- Layout: shared layout markers render on every public page, catching a page that silently loses its layout.

**[R]** The test suite must be deterministic. Two known hazards must be actively managed: tests that share a database within a class must scope every assertion to records they created, and time-sensitive assertions must freeze the clock rather than tolerate a one-second race.

### 16.3 End-to-end testing

**[REC]** No automated browser coverage currently exists, and several requirements — the navigation swap, the polling status page, locked-content presentation, custom control states, and widget hydration — are only verifiable in a real browser. Proposed minimum set:

1. Submit from the marketing site through to a delivered report in a local environment.
2. Sign in and confirm the marketing-site navigation swaps to the signed-in state.
3. View a locked report, follow the unlock path through a test-mode checkout, and confirm the unlocked state and PDF availability.
4. Sign in as a subscriber and confirm both dashboard widgets render with real values.
5. Sign in as an operator, override a result, and confirm the hosted report reflects it.
6. Confirm the status page advances through stages and links to the finished report.

**[R]** Until automated browser coverage exists, any claim that a visual or client-side behavior works must be labelled as unverified rather than asserted.

### 16.4 Security testing

**[R]** Required:

- Assert no execution of repository content exists anywhere in the pipeline path.
- Assert repository access tokens never appear in persisted failure reasons.
- Assert cross-user audit access yields not-found.
- Assert an existing account is never auto-signed-in from a signed link.
- Assert unsigned access to every signed route is refused.
- Assert the session-status response contains a boolean and nothing more.
- Assert no wildcard cross-origin configuration coexists with credentials.
- Assert the operator override cannot persist an invalid payload.
- Assert secret findings contain no matched values.

**[REC]** Add: dependency vulnerability scanning in continuous integration for the platform's own dependencies; a periodic secret scan over the repository history; and a review of the model prompt for injection resistance given that repository content enters it.

### 16.5 Performance testing

**[REC]** None currently exists. Proposed:

- Pipeline duration against a repository corpus spanning small, medium, and at-limit sizes, establishing the baseline behind the median-time target.
- Confirmation that the size, depth, and timeout limits actually bind — a repository designed to exceed each must be rejected as expected.
- Secret-pattern timing against multi-megabyte files, to hold the linear-time requirement.
- Query counts per render for dashboard and operator list views, to catch relation-loading regressions.
- Worker concurrency versus disk headroom, to confirm the storage sizing rule.

### 16.6 Acceptance testing

**[R]** Every feature must be verified against the acceptance criteria in §20 before it is considered done. Verification must be evidence-based: a command was run and its output observed, or a page was loaded and its content confirmed. **[R]** Where evidence cannot be obtained — most commonly because no browser is available in the execution environment — the gap must be stated explicitly rather than glossed.

### 16.7 Test data management

- **[R]** Factories for every domain entity, with named states covering locked, unlocked, verified, failed, and prepaid.
- **[R]** Fixture repositories committed as small real repositories, covering: a healthy codebase, a duplication-heavy codebase, one with no tests, one with vulnerable dependencies, one containing planted non-live secret patterns, and an empty repository.
- **[R]** The catalog seeder must be idempotent, keyed on stable natural keys, and safe to re-run.
- **[R]** A demonstration seeder must create one user per entitlement state — each subscription tier, trialing, cancelled, expired, no subscription with partial free usage, and fully exhausted — each with audits spread across statuses.
- **[R]** Planted secrets in fixtures must be obviously non-live and must never resemble a real credential.
- **[R]** Test runs must never target the development database. This must be enforced by configuration, not by discipline.

---

## 17. Development Roadmap

**[R]** The phases below are ordered by dependency. Each is independently shippable and testable, and each ends in a state where the system is coherent.

### Phase 1 — Foundations

Repository structure separating the marketing site from the product application; one-command local environment with database, cache, and mail capture; product application reduced to its required surface with marketing routes removed; configurable product-application URL exposed to the marketing site; visual identity tokens defined once.

**Delivers:** two independently buildable applications and a working development environment.
**Depends on:** nothing.
**Blocks:** everything.

### Phase 2 — Core audit pipeline

Status enumeration and display mapping; request and report entities; intake endpoint with abuse controls and cross-origin support; cloner with preflight and guardrails; metrics collector; analyzer interface with a concrete implementation, a test double, and payload validation; PDF rendering; signed hosted report; pipeline job with failure branches; operator audit resource with retry; marketing modal wired to the endpoint.

**Delivers:** an anonymous visitor can obtain a real report.
**Depends on:** Phase 1.
**Blocks:** Phases 3 onward.
**Milestone M1 — First automated report delivered end to end.**

### Phase 3 — Verification, access, and monetization

Verification gate with signed confirmation and idempotent routing; unverified purge; private-repository access flow with token injection and manual launch; entitlement service with free allowance; catalog seeding; one-time unlock with intent and idempotent fulfilment; locked and unlocked rendering; PDF as a paid entitlement; sample report; benchmark position; referral bonus; subscription allowance with monthly metering; consent capture.

**Delivers:** a monetized funnel with no cost exposure to unverified traffic.
**Depends on:** Phase 2.
**Blocks:** Phase 4's conversion work.
**Milestone M2 — First paid unlock completed.**

### Phase 4 — Growth, conversion, and retention

Funnel instrumentation and operator funnel report; client analytics hooks and class-specific error messaging; guest-friendly signed unlock; prepaid single run; live status page with polling; pipeline retry policy; verification and abandoned-unlock reminders; report substance work — repository facts, real dependency vulnerability audit, expanded secret detection, tooling detection, deterministic scoring, history-derived insights; score deltas; scheduled re-audits; marketing trust surface with legal pages, social preview, structured data, and the product-path section.

**Delivers:** a measurable, recoverable funnel and a substantively stronger report.
**Depends on:** Phase 3.
**Milestone M3 — Full funnel instrumented and a complete conversion path measurable.**

### Phase 5 — Foundations hardening and unified identity

Container permission self-healing; authentication redirect correctness and session-aware navigation; catalog wired into the default seed; demonstration users covering every entitlement state; accessible custom controls; dark identity applied across every backend-served public page and the customer dashboard.

**Delivers:** one continuous product experience and a development environment that supports the remaining work.
**Depends on:** Phase 1; benefits from Phase 3's catalog.
**Milestone M4 — Visual and navigational continuity across both applications.**

### Phase 6 — Customer dashboard

Ownership scope and registration backfill; read-only audit list and detail; statistics and recent-audit widgets; navigation visibility rules.

**Delivers:** visibility into in-flight and failed audits, not only finished reports.
**Depends on:** Phase 3's entitlement service; Phase 5's demonstration data for verification.

### Phase 7 — Operator control

Operator context, pipeline log, and analysis timing; configurable prompt template with validation and per-audit context; audit editing with prompt preview, processing log, and validated results override; audit statistics and by-plan usage widgets.

**Delivers:** full operator control without a deployment.
**Depends on:** Phase 2's pipeline and validator.

### Phase 8 — Email infrastructure *(revised by D1)*

Single mailer entry point with logging; operator email log with resend; complete routing of all ten message types. *(As originally executed, this phase also delivered a standalone email service and its transactional client; D1 removes them — see Phase 10. The mailer, log, and routing guarantee are unchanged and remain delivered.)*

**Delivers:** communication accountability.
**Depends on:** Phase 2's message set; independent of Phases 6 and 7.
**Milestone M5 — Every audit message accounted for.**

### Phase 9 — Production readiness

Error tracking; health checks; alerting; operations dashboard; backup and recovery with a rehearsed restore; a staging environment; automated deployment with a smoke gate and a documented rollback; retention and erasure policies implemented; automated browser coverage for the flows in §16.3; performance baseline established.

**Delivers:** an operable production service.
**Depends on:** Phases 1–8.
**Milestone M6 — Production readiness criteria in §20.3 satisfied.**

### Phase 10 — Email simplification *(added by D1)*

Remove the Mailcoach application directory, its compose services, database bootstrap, and environment variables; remove the platform client, its exception, the configuration gate, and the status-refresh admin action; simplify the mailer to log-then-direct-send; drop or repurpose the platform-identifier column; update tests currently asserting the platform API contract against fakes. The email log, resend from stored rendering, and the ten-message routing guarantee are untouched.

**Delivers:** one less application to operate; the specified §5.8 shape matches the code.
**Depends on:** nothing; small and immediately executable.

### Phase 11 — Scanner platform and findings model *(added by D2)*

Tier attribute on audit requests with per-tier configuration; scanner harness executing the committed F5.12.2 set in its fixed order inside the pipeline's existing guardrails; SARIF/native output normalization into one findings model; deduplication and problem-family grouping; prompt and template rework so the AI narrates groups; scoring formulas extended to consume scanner signal (versioned per Q14); cost telemetry per run (F5.12.6); catalog rebuilt around tier products and the pitch subscription grid; marketing pricing surface updated to match. SonarQube, Trivy, and graph tooling are explicitly out of this phase (deferred per F5.12.2).

**Delivers:** the sellable Automated Health Report ($49) and the new commercial baseline.
**Depends on:** Phases 2 and 9 (launchable operations); supersedes Appendix A pricing.
**Milestone M7 — First paid tier-1 report sold at the new price.**

### Phase 12 — Deep AI review *(added by D2)*

Risk-file selection (hotspots × finding density × sensitive-domain paths; graph centrality deferred), logged and deterministic; file-content AI review with cross-module context under a per-run token budget; payload contract extension for file-bound findings; report rendering for the deep section; Deep AI product and plan-metadata credits.

**Delivers:** the flagship Deep AI Code Review ($199).
**Depends on:** Phase 11's findings model.
**Milestone M8 — First paid tier-2 report delivered within its token budget.**

### Phase 13 — Expert review workflow *(added by D2)*

`expert_review` status with display mapping everywhere; delivery hold for expert-tier runs; operator review queue with canonical-validator editing, false-positive removal, and the expert payload section; reviewer permission; publish action (send + PDF regeneration + status transition); human-verified report rendering; Expert product.

**Delivers:** the Expert Audit tier (from $999) and the upsell path into remediation work.
**Depends on:** Phase 12; reviewer staffing decision (§19 Q8).
**Milestone M9 — First expert-reviewed report published through the workflow.**

### 17.1 Minimum viable product

**[R]** Phases 1–3. That boundary is deliberate: without Phase 2 there is no product, and without Phase 3 the product has no verification gate — meaning unbounded analysis cost on unverified traffic — and no revenue.

**[R]** The MVP must include, without exception: the verification gate, hard pipeline guardrails, deterministic scoring, working-directory cleanup, and the single entitlement field. Each is either a cost control or a correctness invariant that is far more expensive to introduce later.

### 17.2 Suggested priorities

| Priority | Work | Rationale |
| --- | --- | --- |
| 1 | Phases 1–3 | Nothing generates value before this. |
| 2 | Phase 9's error tracking, health checks, and worker alerting | **[REC]** Pull forward. A stalled worker is currently invisible, and that is the highest-consequence silent failure in the system. |
| 3 | Phase 4's funnel instrumentation | Every conversion decision after this point is guesswork without it. |
| 4 | Phase 4's report substance | Determines whether anyone pays. |
| 5 | Phase 8's mail routing | Without it, delivery failures are invisible. |
| 6 | Phases 5–7 | Real value, but each depends on traffic that the earlier phases create. |
| 7 | Remainder of Phase 9 | Required before scale, not before launch. |

**[REC]** The one deviation from strict phase order worth making is pulling worker-liveness alerting forward into the MVP. It is a small amount of work guarding against the failure mode where the funnel looks healthy and no audits run.

**Post-revision ordering (2026-08-01).** With Phases 1–8 executed, the remaining work orders as: **Phase 10** first (small, removes dead architecture and makes the code match §5.8); **Phase 9's** error tracking, worker-liveness alerting, and health checks next (launch blockers); **Phase 11** before public launch, because the pitch's $49 price point is only defensible with the scanner-backed report and the reworked catalog; **Phase 12** immediately after launch (flagship revenue tier); **Phase 13** when the first expert order justifies the workflow — until then expert audits can be fulfilled manually via the existing results-override tooling; remainder of Phase 9 before scale.

### 17.3 Safely postponable

**[R]** Per §4.2: the credit ledger, marketing campaigns, additional repository-host invite paths, deep host-app integration, the score badge, ESP-webhook delivery/open/click tracking, PDF regeneration after override, the risk-map visualization, and the custom operator panel theme. Per §5.12: SonarQube evaluation, Trivy, import-graph/SCIP tooling, and Lizard.

**[REC]** Additionally postponable without risk: a dedicated search index, read replicas, distributed tracing, application-level encryption, and team-wide audit visibility — pending the decision in §19.

---

## 18. Risks and Trade-Offs

### 18.1 Product risks

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| P1 | Free report is good enough that nobody pays | Revenue floor | **[R]** Locked content is depth, not the verdict; **[REC]** monitor viewed-to-unlock-started conversion and adjust the lock boundary using funnel data, not intuition |
| P2 | Free report is too thin to establish trust | Funnel collapse at the report | **[R]** Scores, metrics, facts, benchmark, and risk titles always visible; **[REC]** measure both P1 and P2 against the same funnel — they pull in opposite directions and only data resolves the tension |
| P3 | One-time unlock cannibalizes subscriptions | Weak recurring revenue | **[R]** The unlock explicitly buys one report, not a run; **[REC]** present tiers at every quota and unlock touchpoint |
| P4 | Model output is generic and not repository-specific | Product feels like a template | **[R]** Metrics, excerpts, and evidence requirements ground it; **[REC]** operators should periodically read real reports — no metric substitutes for this |
| P5 | Deterministic scores contradict the narrative | Credibility loss | **[R]** Scores override the model's block, so numbers and prose come from one source; **[REC]** instruct the model to reason from the provided scores rather than invent its own |
| P6 | Report finds nothing significant | Perceived worthlessness | **[R]** A healthy verdict is a legitimate, designed outcome and must render as a confident finding, not an empty state |
| P7 | Private-repository flow has too much friction | Lost professional segment | **[R]** Clear instructions and manual launch; **[REC]** measure abandonment at the access-pending state |
| P8 | Free allowance is farmed via disposable addresses | Cost leakage | **[R]** Verification gate and hard limits; **[A]** accepted at current scale; **[REC]** monitor cost per converted customer |

### 18.2 Technical risks

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| T1 | Model provider change breaks the contract | Pipeline stops | **[R]** Interface plus adapter; validated contract; configuration-driven model identifier |
| T2 | Model cost per audit exceeds unit economics | Unsustainable free tier | **[R]** Hard excerpt limits; **[REC]** cost per audit is the missing metric that would make this manageable |
| T3 | A repository exceeds a limit in an unhandled way | Worker exhaustion | **[R]** Size, depth, and timeout limits with unconditional cleanup |
| T4 | Scoring formula changes invalidate historical comparison | Deltas and benchmarks become meaningless | **[R]** Formulas are documented and stable; **[REC]** version the formula and record which version produced each score, so a change does not silently corrupt history |
| T5 | Stored payload shape drifts from the renderer | Old reports break | **[R]** Additive changes or migration; **[REC]** add an explicit schema-version field |
| T6 | Static analysis baseline grows unchecked | Real defects hide in noise | **[R]** No new error category per change; **[REC]** freeze the accepted baseline in a file so growth is unmissable |
| T7 | Test suite non-determinism erodes trust in the gate | Real failures dismissed as flakes | **[R]** Scope assertions to created records; freeze the clock for time-sensitive assertions |
| T8 | Duplicated presentation logic drifts between surfaces | Inconsistent status meaning | **[R]** Single status mapper; **[REC]** consolidate the duplicated column, description, and visibility definitions |
| T9 | Query count per render grows unnoticed | Slow dashboards | **[REC]** Assert query counts in tests for the heaviest views |
| T10 | Container image and runtime drift | Environment-specific failures | **[R]** Fixes must be baked into images, never applied only at runtime |

### 18.3 Operational risks

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| O1 | Workers stop; submissions still succeed | Silent total failure of the core product | **[R]** Queue depth visible; **[REC]** worker-liveness alerting is the highest-value missing control |
| O2 | Scheduled tasks silently stop | Reminders and re-audits quietly cease | **[REC]** Scheduler health check and missed-task alert |
| O3 | *Resolved by D1 (2026-08-01).* Direct send is now the specified path, not a fallback | Delivery/open/click tracking absent until ESP webhooks (§4.2) | **[R]** Accepted; email log records every send outcome |
| O4 | Operator override introduces a bad payload | Wrong customer-facing content | **[R]** Canonical validation; **[REC]** actor-attributed change log so the change is traceable |
| O5 | PDF diverges from the hosted report after an override | Customer sees two versions | **[R]** Must be stated in the override interface; **[REC]** offer re-run as the resolution |
| O6 | No staging environment | Provider, email, and cross-origin flows verified only in production | **[REC]** Staging is the largest infrastructure gap |
| O7 | No rehearsed restore | Backups may not be recoverable | **[REC]** Quarterly restore rehearsal |
| O8 | Manual release with no rollback procedure | Slow recovery from a bad deploy | **[REC]** Automate release with a smoke gate and a documented rollback |
| O9 | Disk exhaustion from concurrent large clones | Worker failure | **[R]** Cleanup in all paths; **[REC]** size disk to the limit multiplied by concurrency and alert on free space |

### 18.4 Security risks

Enumerated in §11.9. **[R]** The four requiring active enforcement rather than one-time design: no execution of cloned code; token redaction; the ownership scope's grouping; and the never-auto-sign-in-to-an-existing-account rule. Each must be guarded by a test, because each is a rule a well-meaning future change could quietly break.

### 18.5 Scaling risks

| # | Risk | Trigger | Mitigation |
| --- | --- | --- | --- |
| SC1 | Queue backlog during a traffic spike | Marketing success | **[R]** Add workers; **[REC]** alert on oldest-pending age, not only depth — depth alone hides a stalled queue |
| SC2 | Model provider rate limits | Concurrency growth | **[REC]** Worker concurrency is the natural throttle; reduce it rather than adding a limiter |
| SC3 | Database contention on list and statistics queries | Volume growth | **[REC]** Add the composite indexes in §9.8 before scaling hardware |
| SC4 | Email log body column dominates storage | Volume over time | **[Q]** Retention policy undefined (§19) |
| SC5 | Benchmark computation cost grows with the population | Report volume | **[R]** Cached with a bounded lifetime |
| SC6 | Funnel table growth degrades the operator report | Traffic growth | **[REC]** Stage and time indexes exist; consider pre-aggregation if the report slows |

### 18.6 Architectural trade-offs

| Decision | Gained | Given up | Assessment |
| --- | --- | --- | --- |
| No human review gate | Speed as the product; zero marginal labor | Ability to catch a bad report before a customer sees it | **[R]** Correct. Compensated by deterministic scoring, conservative templating, and the operator override. |
| Write-time entitlement | Trivial rendering; a paid report stays paid | An expired subscription's past reports stay unlocked | **[R]** Correct — and arguably the desired behavior, not merely an acceptable cost. |
| Query scope over per-action policies | No bypass path | Less explicit at each call site | **[R]** Correct for this shape, where every read flows through one query. |
| Signed links over accounts | Frictionless free funnel | Link forwarding grants report access | **[R]** Correct, with bounded lifetimes and the no-auto-sign-in rule as guards. |
| Static analysis only | Never executing untrusted code | Approximated coverage; heuristic duplication | **[R]** Correct and non-negotiable. Must be honest about it in the report. |
| Deterministic scores over model scores | Comparability and defensibility | Model nuance in the numbers | **[R]** Correct. Model narrates, measurement decides. |
| Separate email application | No framework version conflict; full platform features | A third application to operate; no single sign-on | *Superseded by D1 (2026-08-01): the platform is removed entirely — license expiry plus a never-exercised live path outweighed the tracking features, which move to ESP webhooks later.* |
| Application-owned email log as sole mail record | One source of truth; nothing to reconcile or operate | No delivery/open/click state until ESP webhooks are integrated | **[R]** Correct under D1. |
| Accepted static analysis baseline | Velocity | Some framework-inference noise permanently present | **[R]** Acceptable only while no new category is introduced. |
| Three applications in one repository | Atomic cross-surface changes; one review surface | Independent versioning; larger checkout | **[R]** Correct at this team size. |
| Deferred derivation of deltas and benchmarks | Stored payload stays an immutable run record | Recomputation per view | **[R]** Correct, with caching for the population query. |
| Dark-only public pages | One coherent identity; no theme matrix | No light preference support | **[R]** Acceptable; the report page is deliberately light, which covers the document-reading case. |

### 18.7 Known defects and improvement candidates

**[REC]** Non-blocking, recorded so they are not rediscovered:

*Correctness and safety*
- Intent read-then-delete is not transactional; concurrent order events could both observe an intent.
- Reminder check-then-create is not atomic across overlapping scheduler runs.
- Bonus accrual uses read-modify-write; concurrent referrals for one referrer could lose an increment. Money-adjacent, so worth an atomic increment.
- Carrying an unlock forward on re-run restamps the unlock time rather than preserving the original.
- A mail render failure prevents any log row from existing, so the failure is unrecorded — the one gap in the "never silently stop" guarantee.
- Attempt counters increment via read-then-write.
- Resend and status-refresh paths lack explicit exception handling.
- Funnel event metadata may be absent; readers must guard.

*Consistency*
- One audit status reconciles to no statistics bucket, so operator tiles do not sum to the total.
- Column, status-description, and visibility logic is duplicated across list views, detail views, and widgets.
- Prompt composition duplicates the context-append block between composition and preview.
- One layout heading rule is not scoped to the public wrapper, which would matter if a light public page were ever added.

*Efficiency*
- Several widgets issue one query per tile rather than one grouped query.
- Some relations are not eagerly loaded in list contexts.
- Metrics collection spawns repeated git invocations.
- Pipeline steps save per step rather than batching.
- Container start repairs permissions across the whole tree rather than only mismatched files.
- Navigation visibility performs a query and a service call per render.

*Coverage and hygiene*
- No automated browser coverage; every visual and client-side behavior is currently unverified by test.
- One known time-sensitive test is flaky under load because the clock is not frozen.
- Aggregate processing time uses a database-specific function.
- Schedules have no database-level uniqueness per user and repository.
- Some detected secret patterns have known false-positive sources, including documentation placeholders.
- Corrupt lockfiles yield zero packages silently, with no operator signal.
- One legacy checkout route references a view that does not exist — a pre-existing defect unrelated to the audit domain.

**[REC]** Highest-value five, in order: worker-liveness alerting; excluding secret-matching files from excerpts; the actor-attributed operator change log; scoring-formula versioning; and recording mail render failures.

---

## 19. Open Questions

**[Q]** Each requires a decision. Grouped by type, with the consequence of leaving it open.

### 19.1 Business decisions

| # | Question | Why it matters | Recommendation |
| --- | --- | --- | --- |
| Q1 | Confirm or replace every conversion target in §2.4. | No baselines exist; the proposed thresholds are guesses. | Instrument first, set targets after four weeks of real data. |
| Q2 | Is the current lock boundary — verdict and scores free, evidence and plan paid — the right split? | Directly determines revenue and trust. | Ship as specified; revisit using P1 and P2 funnel data. |
| Q3 | Should the free allowance stay lifetime-per-email, or become periodic? | Lifetime caps total cost per address but blocks legitimate returning users. | Keep lifetime for now; revisit if returning-user friction appears. |
| Q4 | Should audits be visible to all members of a tenant? | Changes the ownership boundary; agencies likely want it. | Defer. The ownership scope is the single change point when needed. |
| Q5 | Are the tier prices and allowances final, and do they hold at expected cost? *(Restated by D2: the pitch prices — $49 / $199 / $999+, subscriptions $59–$499 — supersede the Appendix A figures.)* | Unit economics depend on cost per audit, which is unmeasured; the pitch requires validation on the first 20–30 paid runs. | Implement F5.12.6 cost telemetry in Phase 11; confirm margins before advertising prices as standard rather than launch pricing. |
| Q6 | Should the one-time unlock ever become a credit usable for a run? | Currently a deliberate simplification. | Hold until the funnel shows the demand. |
| Q7 | What is the accessibility conformance target? | Determines contrast, focus, and announcement obligations. | WCAG 2.1 AA. |
| Q8 | What is the support commitment for a failed or follow-up audit? | These states imply a human response that is not staffed. | Define an owner and a response window before launch. |

### 19.2 Technical decisions requiring validation

| # | Question | Why it matters | Recommendation |
| --- | --- | --- | --- |
| Q9 | Review the pinned model default. | The current pin is valid and active, but model selection drives cost, latency, and output quality — the three variables the product is most sensitive to. | Evaluate the current generation on a fixed repository corpus, comparing report quality, latency, and cost per audit before launch. Keep the identifier configuration-driven either way. |
| Q10 | Is a staging environment in scope before launch? | Provider checkout, live email, and credentialed cross-origin behavior under a real cookie domain cannot be fully verified locally. | Yes. This is the largest infrastructure gap. |
| Q11 | Confirm backup and recovery objectives (§14.6). | Currently unspecified, so effectively none. | Adopt the proposed baseline and rehearse a restore. |
| Q12 | Is losing queued audits on a queue outage acceptable? | Determines whether queue persistence is required. | Acceptable, given operator retry — but confirm. |
| Q13 | Which error tracking, metrics, and alerting stack? | Nothing is chosen; §15 is entirely a recommendation. | Choose one integrated option and adopt the §15.6 alert set. |
| Q14 | Should scoring formulas be versioned and recorded per report? | Without it, a formula change silently corrupts historical deltas and benchmarks. | Yes. Cheap now, expensive later. |
| Q15 | Should the payload carry an explicit schema version? | Makes the renderer-compatibility obligation enforceable rather than aspirational. | Yes. |
| Q16 | Should the operator panel gain an actor-attributed change log? | Operators can rewrite customer-facing content and grant paid access with no attribution. | Yes; treat as a security control. |
| Q17 | Should files matching secret patterns be excluded from excerpts sent to the model? | Customer credentials could be transmitted to a third party. | Yes. The scanner already identifies the files. |
| Q18 | Should the session-status endpoint be throttled? | Unauthenticated and cheap to call in a loop. | Yes. |
| Q19 | Retention periods for verified requests, reports, PDFs, funnel events, and email logs — including message bodies. | Privacy obligation and the fastest-growing storage cost. | Decide before launch; the email body column is the pressing one. |
| Q20 | Subject-access and erasure procedure, including interaction with audit trails and order-linked unlocks. | Regulatory exposure. | Anonymize-in-place where a financial or audit trail must survive. |
| Q21 | Frozen static-analysis baseline file, or continue with per-change category review? | Current practice depends on reviewer diligence. | Freeze it. |
| Q22 | Automated deployment with a smoke gate and documented rollback. | Release is currently manual with no rehearsed rollback. | Adopt before launch. |

### 19.3 Assumptions to confirm

| # | Assumption | Consequence if wrong |
| --- | --- | --- |
| Q23 | Requesters are authorized to share the repositories they submit. | Legal exposure; may require stronger attestation at submission. |
| Q24 | Separate hosts for the two surfaces, with cookie domain scoped to permit the credentialed probe in production. | The navigation swap silently fails in production while working locally. |
| Q25 | Disposable-address farming is tolerable at current scale. | Cost leakage; may require domain restrictions. |
| Q26 | Payment providers are configured with live credentials at deployment, and seeded identifiers are unmistakably placeholders. | A placeholder reaching production breaks checkout. |
| Q27 | Bounded excerpts are transmitted to the model provider, and this is disclosed. | Privacy non-compliance. |
| Q28 | English-only reports are acceptable for the target market. | Localization becomes a launch dependency. |
| Q29 | A single application node plus workers serves expected volume. | Scaling work moves earlier. |
| Q30 | *Resolved by D1 (2026-08-01).* The license expired 2025-10-15 and is not being renewed; the email platform is removed from the architecture. Direct framework send is the specified path (§5.8); delivery/open/click tracking is deferred to ESP webhooks (§4.2). | — |

**New questions raised by the 2026-08-01 revision:**

| # | Question | Why it matters | Recommendation |
| --- | --- | --- | --- |
| Q31 | Which SMTP/ESP transport for production mail? | Deliverability (SPF/DKIM/DMARC) is now entirely this transport's job; an ESP with event webhooks also unlocks the deferred tracking item cheaply. | Pick an ESP with webhooks (e.g. Postmark/Resend/SES) rather than bare SMTP, so §4.2's tracking deferral stays a config change, not a migration. |
| Q32 | Does the legacy $5 unlock survive once tier products launch? | Two overlapping monetization models confuse copy and funnel math. | Retire it at Phase 11: the free diagnostic converts directly to the $49 tier; grandfather existing unlocks. |
| Q33 | Which in-house/permissive Semgrep rulesets ship in tier 1? | Registry-licensed rules are unusable commercially; rule quality drives report quality. | Start from permissively-licensed community packs plus a small in-house pack per supported ecosystem; review licenses rule-by-rule before launch. |
| Q34 | Expert-tier reviewer staffing and SLA (extends Q8). | The $999 tier sells engineering time; an unstaffed queue is a refund generator. | Current engineering team fulfils per pitch; publish a review SLA on the product page only after the first three are delivered on time. |

---

## 20. Acceptance Criteria

### 20.1 Project-level

**[R]** The platform is acceptable when all of the following hold:

| # | Criterion |
| --- | --- |
| A1 | An anonymous visitor can submit a public repository from the marketing site, confirm their email, and receive a hosted report and PDF link with no account and no payment. |
| A2 | Nothing runs, no allowance is consumed, and no operator is notified before email confirmation. Confirmation is idempotent. |
| A3 | The report shows deterministic scores, measured metrics, repository facts, real dependency vulnerability findings, secret findings as counts and paths only, tooling detection, history-derived insights, and ranked risks with evidence. |
| A4 | Locked content is visibly obscured with a clear unlock path. Unlocking reveals full detail and enables the PDF, with no re-analysis. |
| A5 | A requester whose allowance is exhausted can purchase a single run; the resulting report is created unlocked. |
| A6 | A private repository can be audited after access is granted, launched by an operator, with the access token absent from every stored and displayed message. |
| A7 | A subscriber can launch and schedule audits within a monthly allowance, sees score movement against the previous audit of the same repository, and receives unlocked reports. |
| A8 | Cloned code is never executed, and working directories are removed on every path including failure. |
| A9 | No user can see another user's audit or report through any path. A foreign identifier yields not-found. |
| A10 | An operator can search, inspect, edit, retry, launch, grant unlock, mark handled, and override results, and can change the prompt template without a deployment. |
| A11 | Every audit message is sent through the single mailer and produces a log row recording the outcome. A send failure is recorded with its reason; audit email never silently stops. |
| A12 | Every funnel stage is recorded and visible in the operator funnel report, with acquisition stages uninflated by internal runs. |
| A13 | Retries and duplicate webhooks never produce a duplicate report, a duplicate entitlement, a duplicate run, or a revoked unlock. |
| A14 | The marketing site and every backend-served public page share one visual identity, and navigation reflects session state without a wrong-state flash. |
| A15 | Every monetary and quota figure shown anywhere matches backend configuration exactly. No fabricated social proof appears anywhere. |
| A16 | The full test suite passes, the formatter reports no changes, and static analysis introduces no new error category. |

### 20.2 Module-level

**[R]** Each module is complete when its criteria hold.

**Intake** — Validation, honeypot, throttle, and dedupe all enforced and tested. Cross-origin access works from the configured origin. Consent stored with a timestamp. Verification gate holds all three prohibitions. All four routing branches selected correctly. Purge removes only unverified requests past the window. Expired and tampered signatures rejected with a friendly page.

**Repository acquisition** — Preflight, clone, and cleanup honor every configured limit. Token injected for supported hosts only. Token absent from every persisted failure reason, proven by test. Cleanup verified on the failure path.

**Measurement** — Every metric in §5.2.5 produced against fixture repositories. Every secret pattern matches its positive case and emits no matched value. Individual detector failure degrades rather than aborting. Patterns verified linear-time at multi-megabyte scale.

**Dependency auditing** — Manifests resolved and batch-queried. Endpoint failure yields zero findings without failing the run. Unparseable lockfiles yield zero packages.

**Scoring** — All six dimensions computed as integers. Missing metrics yield defined defaults. Formulas documented. Computed scores override the model's block, proven by test.

**Analysis** — Interface, implementation, and test double in place. Every contract violation rejected. One corrective retry then stage failure. Default template used when blank; override applied; context appended; placeholders validated at save time. Prompt preview matches what the next run would use.

**Funnel** — All ten stages recorded. Aggregates zero-fill every stage. Acquisition stages excluded from dashboard and scheduled runs. Recording never breaks the observed flow.

**Entitlement** — Allowance limits, usage, bonus, and remaining calculations all correct across subscribed, free, and exhausted users. Consumption idempotent per request. Absent subscription data yields zero, never unlimited.

**Report lifecycle** — Creation resolves entitlement correctly for every source. Regeneration carries an unlock forward and regenerates the PDF. Locked download refused. Signed URLs honor configured lifetimes. Delivery failure leaves a recoverable state. Sample renders; missing fixture yields not-found.

**Comparative context** — Percentile suppressed below the minimum sample. Absent prior audit renders as no-comparison. Population query cached.

**Scheduling** — Schedules persist per repository. Due runs respect remaining allowance. Insufficient allowance skips without consuming or failing the batch. Task non-overlapping and single-node.

**Mail routing** — All ten message types routed through the mailer, proven by exhaustive search. Successful send logs as sent; failure logs the reason and marks the row failed. Resend reproduces the stored subject and body and requires confirmation. *(Revised by D1: platform-gate and status-refresh criteria removed.)*

**Customer dashboard** — Cross-user isolation proven live, not only by unit assertion. Email-matched ownership works and is backfilled on registration. Every status has a plain-language description. Action-required states show their callout. Widget values correct across subscribed, free, and exhausted users. Widgets and navigation hidden entirely for a user with neither audits nor allowance. Navigation visible to an entitled subscriber before their first report.

**Operator administration** — Search and filters cover the required fields. Edits persist. Every action behaves as specified with existing actions unchanged. Override rejects invalid payloads via the canonical validator and accepts valid ones. Settings pages permission-gated, proven at the route level. Pipeline log and timing render. Funnel report and both statistics widgets compute correctly, and widgets tolerate a missing sibling table. Email log filters, searches, resends with confirmation, and refreshes statuses.

**Marketing site** — Type-check, lint, format, and build all pass. Product links built from configuration. Modal shows class-specific errors, preserves data, and clears stale errors on reopen. Analytics absent when unconfigured. Legal pages linked from footer and consent control. Social preview and structured data valid. Navigation swaps only on affirmative response.

**Visual identity** — Tokens defined once and consumed by both applications. Three layouts plus shared control styling cover every public page. Layout markers present on every page, proven by test. Customer dashboard brand-aligned through supported hooks only, with the operator panel untouched. Report page and emails deliberately unchanged. Custom controls retain native inputs with all five states.

### 20.3 Production readiness

**[R]** All of §20.1 and §20.2 satisfied, plus:

| # | Criterion |
| --- | --- |
| PR1 | **[R]** All secrets supplied via environment or a secret manager. None in the repository, in seed data, in logs, or in user-facing text. Placeholder identifiers unmistakably non-live. |
| PR2 | **[R]** TLS on every public surface; session cookie secure-flagged and domain-scoped for the credentialed probe. |
| PR3 | **[R]** Queue workers supervised and restarting automatically. The scheduler installed. |
| PR4 | **[REC]** Error tracking capturing unhandled exceptions with release context. |
| PR5 | **[REC]** Health checks for liveness, readiness, worker health, and scheduler health. No dependency check gates readiness. |
| PR6 | **[REC]** The §15.6 alert set configured, with worker-liveness alerting mandatory. |
| PR7 | **[REC]** Backups automated and a restore rehearsed successfully at least once. |
| PR8 | **[REC]** Deployment automated with a post-release smoke gate and a documented, rehearsed rollback. |
| PR9 | **[REC]** A staging environment exists, and provider checkout, live email delivery, and credentialed cross-origin session probing have each been verified there. |
| PR10 | **[R]** Legal pages published and accurate about collection, third-party transmission, and retention. |
| PR11 | **[Q]** Retention policies decided and implemented; erasure procedure documented. |
| PR12 | **[R]** Payment providers configured with live credentials and a real transaction verified end to end. |
| PR13 | **[R]** Outbound email verified end to end against the production transport — send, log row, and resend — including SPF/DKIM/DMARC alignment for the sending domain. *(Revised by D1.)* |
| PR14 | **[REC]** Automated browser coverage for the §16.3 flows, or each unverified behavior explicitly listed as such. |
| PR15 | **[REC]** A performance baseline established against the repository corpus, and every configured limit proven to actually bind. |
| PR16 | **[R]** Cost per audit measured, and unit economics confirmed against the published prices. |
| PR17 | **[R]** An owner and a response window defined for requests in failed and follow-up states. |
| PR18 | **[R]** Every completion claim in this section backed by observed evidence. Any gap stated explicitly rather than assumed. |

**[R]** PR18 is not ceremonial. Several capabilities in this specification are verifiable only in an environment with a real browser, real payment credentials, and the real mail transport. Where that evidence has not been obtained, the correct report is "not verified", never "done".

---

## Appendix A — Reference values

**[R]** Every value below must live in exactly one configuration entry and be read from there by backend logic, operator interfaces, and marketing copy alike. The figures shown are the specified defaults.

**Pricing note (D2, revised 2026-08-24).** The §5.12 tier pricing **has landed** with the Phase 11 catalog rework; the rows below now describe the implemented catalog, not the pre-D2 one. The 2026-08-24 catalog revision retired the Automated Health Report tier outright — its name and positioning did not work, and its five-scanner profile is now what the base Diagnostic tier runs — leaving three tiers. §5.12 and Phases 10–13 below are the original design record and still describe four; the rows here are current. Every figure lives in `backend/config/pricing.php` and nowhere else — `AuditMonetizationSeeder` seeds from it, `app:export-pricing` generates the marketing site's `pricing.json` from it, and the *Pricing drift* workflow fails a pull request where the two disagree. Prices remain **[Q]** subject to cost-per-audit validation on the first 20–30 paid runs (Q5). Q32 is resolved: the legacy $5 unlock is retired, its product row deactivated rather than deleted so existing unlocks keep rendering.

| Domain | Value |
| --- | --- |
| Free allowance | 3 runs per email address, lifetime, plus per-user bonus |
| Diagnostic Report | $49 one-time — tier `diagnostic` |
| Deep AI Code Review | $119 one-time — tier `deep_ai` |
| Expert Audit | $999 one-time, "from" pricing — tier `expert` |
| Automated Health Report | **retired (2026-08-24)** — tier `automated` removed from the enum, product row deactivated, existing rows migrated to `diagnostic` |
| Subscription grid | Starter $59 / 5 Diagnostic / 0 Deep AI credits; Growth $149 / 20 / 1; Agency $499 / 75 / 4; Enterprise $1,500 / 250 / 15 — per month |
| Partner plan | `audit-partner-monthly`, $0, hidden and assigned manually — 100 Diagnostic / 50 Deep AI / 10 Expert per month |
| Subscription metering | One metadata key per tier, no aliases: Diagnostic dashboard runs consume `audit_diagnostic_credits`, Deep AI `audit_deep_ai_credits`, Expert `audit_expert_credits`. All read from plan product metadata. Diagnostic falls back to the lifetime free-run quota only when the plan grants it no allowance |
| One-time unlock | $5, unlocks one existing report only — **retired (Q32)**, row deactivated, existing unlocks grandfathered |
| Prepaid single run | $5, same product — **retired (Q32)**, same grandfathering |
| Retired subscription plan | `audit-scale-monthly` — the one plan the new grid orphans; deactivated, never deleted |
| Verification link lifetime | 48 hours |
| Unverified purge | 7 days |
| Report link lifetime | 30 days |
| Status link | Signed, non-expiring |
| Clone timeout | 120 seconds |
| Preflight timeout | 30 seconds |
| Clone depth | 200 commits |
| Maximum repository size | 500 MB |
| Maximum excerpt files | Per tier (`audit.tiers.*.excerpt_files`) — 50 on every tier |
| Maximum excerpt bytes | Per tier (`audit.tiers.*.excerpt_bytes`) — 6,000 per file on every tier |
| Per-tier AI token budget | 16,000 on every tier |
| Per-tier narrated groups | 12 on every tier |
| Per-tier scanner set | scc + Gitleaks + OSV + jscpd + Semgrep on every tier. Tiers differ by deep review (Deep AI, Expert) and human sign-off (Expert), not by scanner set or token budget |
| Scanner timeouts | scc 60s, Gitleaks 120s, jscpd 180s, Semgrep 300s — per tool, and a tool that fails or times out contributes no findings and never fails the run |
| Findings grouping | 20 groups maximum, 8 examples per group, directory depth 2 |
| Benchmark minimum sample | 20 completed reports |
| Pipeline job timeout | 900 seconds |
| Pipeline attempts | 3, with increasing backoff |
| Queue visibility timeout | 960 seconds — must exceed the job timeout |
| Verification reminder | ~24 hours after submission |
| Score dimensions | structure, duplication, testing, dependencies, security hygiene, overall |
| Risk impact values | high, medium, low |
| Effort values | S, M, L |
| Funnel stages | submitted, verified, queued, awaiting payment, report sent, report viewed, unlock started, unlock paid, run purchased, failed |
| Audit statuses | new, pending verification, queued, analyzing, report ready, sent, failed, needs followup, handled, awaiting access, awaiting payment |
| Message types | verification, received, access needed, quota exhausted, report ready, report unlocked, request failed, verification reminder, unlock reminder, operator notification |
| Palette | canvas near-black, cream text, gold primary, coral warning |

## Appendix B — Source documents

This specification consolidates and supersedes in role, not in detail:

| Document | Contributes |
| --- | --- |
| Marketing positioning spec | Rescue narrative, tone constraints, content plan, offer model |
| Monorepo and environment spec | Two-surface split, URL layout, container development environment |
| Audit pipeline spec | Pipeline stages, guardrails, payload contract, delivery, operator observability |
| Freemium intake spec | Verification gate, private-repository flow, consent, entitlement model, pricing, growth extras |
| Growth, conversion, retention plan | Funnel instrumentation, guest unlock, prepaid run, status page, recovery emails, report substance, deltas, scheduled re-audits, marketing trust surface |
| Foundations fixes spec | Container permissions, authentication redirects, session-aware navigation, seeders, accessible controls |
| Unified layout spec | Design tokens, layout coverage, dashboard branding, deliberate exceptions |
| Customer dashboard spec | Ownership scope, read-only resource, widgets, navigation visibility |
| Operator management and email spec | Prompt template, per-audit context, results override, pipeline log, statistics, email service, mail routing, email log |
