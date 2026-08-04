# FlexPick — Remaining Phases

**Date:** 2026-08-01
**Source of truth:** `docs/2026-07-27-flexpick-platform-specification.md` (revision 2026-08-01, decisions **D1** and **D2**).
**Relationship to other docs:** this file expands `docs/2026-08-01-pre-launch-backlog.md` into an itemized checklist. The backlog states the execution *order*; this states the execution *contents*. The spec remains the authority on requirements — where the two disagree, the spec wins.

Every item below was checked against the working tree on branch `growth-retention` (2026-08-01). Items marked **verified absent** were grepped for and not found; items marked **verified present** cite the file that has to change.

---

## 0. Where the system actually is

The table below was written on 2026-08-01 and is re-stated as of **2026-08-04**.

| | State |
| --- | --- |
| Spec Phases 1–8 | Implemented, reviewed, fix-waved on `growth-retention` (**202 commits** ahead of `main`, still never merged) |
| Phase 8 live path | Code complete, **still never exercised against a real transport**. D1 deleted the platform it was built on; Postmark is configured but unproven |
| Phase 9A-1 | Complete — Sentry/Bugsink SDK, health checks, three custom checks, band-aware alerting, `app:smoke` |
| Phase 9A-2 | **In-repo enablers complete**; the runbook is written and **not yet executed**. No staging server, no production deploy, no proven alert delivery |
| Phase 10 | Complete |
| Phase 11 | Complete — tier attribute, five-scanner harness, findings model with dedup and grouping, versioned scores, cost telemetry, reworked catalog |
| Phase 12 | Complete — deterministic logged risk-file selection, deep file-content review bound to files, payload v3, per-run token budget, deep-section rendering, secret-file exclusion on every tier. The $199 product and plan-metadata Deep AI credits predate this phase (shipped with Phase 11's catalog rework) |
| Phase 13 | Not started. No `expert_review` status, no operator review queue — verified absent 2026-08-04 |
| Gate state | 896 tests / 2245 assertions passing, exit 0 (confirmed twice); PHPStan level 3 with 418 errors frozen in `backend/phpstan-baseline.neon`, no new errors; Pint clean across 950 files |

Everything below is what remains. **The single largest gap is no longer code** — it is that
nothing in Phase 9A-2 has met real infrastructure.

---

## Phase 10 — Email simplification (D1)

**Status: complete** — implemented on `growth-retention` (commits `f9d192d`..`85643d7`), spec `docs/superpowers/specs/2026-08-01-email-simplification-design.md`, plan `docs/superpowers/plans/2026-08-01-email-simplification.md`.

**Goal:** delete the removed email platform so the code matches spec §5.8. Small, no dependencies, immediately executable. Owner intends to do this by hand.
**Spec:** §17 Phase 10, §5.8, §18.6, Q30.

### Delete — infrastructure
- [x] `mailcoach/` application directory (**verified present** at repo root)
- [x] `mailcoach` and `mailcoach.horizon` services in `compose.yml:17` and `compose.yml:33`, plus the `mailcoach` dependency edge at `compose.yml:45`
- [x] `backend/docker/mysql/create-mailcoach-database.sh` (**verified present**; leave `create-testing-database.sh`)
- [x] `MAILCOACH_ENDPOINT` / `MAILCOACH_API_TOKEN` / `MAILCOACH_UI_URL` in `backend/.env.example:131-133`, and the local `backend/.env`
- [x] `.gitignore` entries for the mailcoach app, if any survive the directory deletion

### Delete — application code
- [x] `backend/app/Services/AuditMail/MailcoachClient.php`
- [x] `backend/app/Exceptions/MailcoachUnavailableException.php`
- [x] `services.mailcoach` block in `backend/config/services.php:125`
- [x] `open-mailcoach` admin nav action, `backend/app/Providers/Filament/AdminPanelProvider.php:43-47`

### Simplify — mailer
- [x] `AuditMailer::send()` (`backend/app/Services/AuditMail/AuditMailer.php`) becomes **log → `Mail::send` → record outcome**: drop the constructor dependency, the `isConfigured()` branch, the `sendTransactional` call, and the fallback `last_error` write
- [x] Fold in the §18.7 defect while the file is open: `$mailable->render()` is currently evaluated *inside* the `AuditEmailLog::create()` array, so a render failure means **no log row is ever written** — the single gap in the "audit email never silently stops" guarantee (A11). Render before create, or create-then-populate, and record a render failure as a failed row.

### Rework — email log
- [x] Drop or repurpose `mailcoach_uuid` (`backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php:18`, `AuditEmailLog::$fillable`). Repurposing to a provider message id is the cheaper path if the ESP decision (Q31) lands soon.
- [x] Remove the status-refresh header action, `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php:26-30`
- [x] Remove the platform branch of the resend action, `.../AuditEmailLogResource.php:83-84`; resend keeps working from the stored subject/body
- [ ] **Deferred — not part of D1.** Add explicit exception handling on the resend path (§18.7 — currently unguarded). Design decision D10.5 keeps resend without a try/catch; this stays on the §18.7 backlog.

### Tests and docs
- [x] Delete `backend/tests/Feature/Services/MailcoachClientTest.php` and every `Http::fake` platform-contract assertion
- [x] Update `AuditMailerTest` and `AuditEmailLogResourceTest` for the direct-send shape
- [ ] **Deferred — out of D1's scope (design decision D10.6).** The exhaustive-routing guard test — a regression test proving every audit mailable routes through `AuditMailer` — was not added; §20.2's "proven by exhaustive search" is still satisfied by manual grep only. Recorded as a backlog item in the spec (§18.7-adjacent backlog).
- [x] `CLAUDE.md:90` names `MailcoachClient` as part of the delivery path; update it and any `backend/AGENTS.md` reference
- [x] Stale comment at `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php:62` ("ships with the Mailcoach workstream") — the table has landed; verify the tile renders real counts

**Exit:** `grep -ri mailcoach` returns nothing outside historical docs; suite green; §20.2 *Mail routing* criteria hold in their D1-revised form.

---

## Phase 9A — Launch-blocking operations

**Goal:** the four controls without which a production outage is invisible. Spec §17.2 pulls these forward ahead of strict phase order; §17.2's post-revision ordering puts them second, right after Phase 10.
**Spec:** §15, §17 Phase 9, §18.3 O1/O2/O8, §20.3 PR4–PR6, PR8.
**Blocked on:** Q13 (which error-tracking/metrics/alerting stack — nothing is chosen; §15 is entirely recommendation).

**Split (2026-08-02):** 9A-1 (in-repo observability) is specified in
`docs/superpowers/specs/2026-08-02-launch-blocking-operations-design.md` and implemented per
`docs/superpowers/plans/2026-08-02-launch-blocking-operations.md`. 9A-2 (staging, CI, first
deploy, rollback rehearsal, ESP + DNS, support ownership) remains outstanding as a runbook.

**9A-2 status (2026-08-04):** the **in-repo enablers are complete** — CI, Deployer hosts,
`SENTRY_RELEASE` injection, the smoke gate and rollback wiring, Postmark keys, and the runbook
itself (`docs/superpowers/runbooks/2026-08-02-launch-operations-runbook.md`). Every bullet below
that describes *infrastructure* stays unticked: PR8, PR9, PR13 and PR17 are satisfied by
**executing** that runbook against real servers, not by writing it. Under PR18 the correct report
until then is "not verified".

- [x] **Choose the stack (Q13).** Resolved as D9A.1–D9A.5: Ploi single server, self-hosted only,
  `spatie/laravel-health` + self-hosted Bugsink, alerts to Telegram/Slack/mail, Ploi's own
  off-box monitor as the dead-man's switch.
- [x] **Error tracking** (PR4) — *in-repo half only.* SDK wired, exceptions tagged with the audit
  request identifier, token scrubber proven by test. Release context depends on `SENTRY_RELEASE`
  injection in the Ploi deploy script — **9A-2, not verified.**
- [x] **Health checks** (PR5) — liveness, readiness, worker, scheduler. No dependency check gates
  readiness, proven by a `preventStrayRequests` test.
- [x] **Worker-liveness alerting** (PR6) — `QueueCheck` on the audit queue plus
  `OldestPendingAuditCheck` on oldest-pending age and stranded `analyzing` runs (§18.5 SC1).
- [x] **Scheduler-missed alert** (§18.3 O2) — `ScheduleCheck` heartbeat, plus the staleness arm on
  `/health` so a dead scheduler is audible to the off-box monitor.
- [ ] **Deploy automation with a post-release smoke gate and a documented, rehearsed rollback** (Q22, PR8, §18.3 O8). *Wiring exists as of 9A-2* — `backend/deploy.php` carries `deploy:smoke` and `deploy:sentry-release`, Deployer's `rollback` is available, and `.github/workflows/` holds `ci.yml`, `deploy-staging.yml`, `pricing-drift.yml`. Unticked because **rehearsed** is the requirement: no deploy has run and no rollback has been performed.
- [ ] **Staging environment** (Q10, PR9) — the spec's "largest infrastructure gap" (§18.3 O6). Provider checkout, live email delivery, and credentialed cross-origin session probing cannot be verified anywhere else.
- [ ] **Production mail transport** (Q31, PR13): pick the ESP, configure SPF/DKIM/DMARC, verify send → log row → resend end to end. Choosing an ESP with event webhooks keeps the deferred delivery/open/click tracking a config change rather than a migration.
- [ ] **Support ownership for `failed` and `needs_followup`** (Q8, PR17) — an owner and a response window, defined before launch. These states already imply a human response that is not staffed.

**Exit:** PR4, PR5, PR6, PR8, PR9, PR13, PR17 satisfied with observed evidence (PR18 — "not verified" is the correct report where evidence is absent).

### Carried out of 9A-1 (recorded 2026-08-02, after final review)

**Blocks 9A-2 — these must be done as part of standing up the infrastructure:**

- [ ] **Point Ploi's uptime monitor at `/health` with a real token.** Until this exists, the
  dead-man's switch is inert: the staleness arm lives *only* at that endpoint, so a dead
  scheduler is completely silent in-app. Set `HEALTH_ENDPOINT_TOKEN` to a long random value —
  `.env.example` deliberately ships it blank, and the endpoint 404s on an empty token.
- [ ] **A database outage cannot be alerted in-app at all.** Both the result store and
  `app:health-alerts` read MySQL, so `DatabaseCheck` failing means the alerter is blind too, and
  `/health` returns 500 rather than the designed 503. Ploi's non-2xx rule is the only coverage.
  Structural, not a bug — but it means the single most important alert depends entirely on 9A-2.
- [x] **Fix the suite's Faker email-collision flake before CI exists.** `UserFactory` now builds the
  local part from a ULID, so the address is unique by construction rather than by Faker's per-instance
  `unique()` pool. The `FeatureTest` no-rollback root cause is untouched and will resurface on the next
  unique column. Two `safeEmail()` factories remain.
- [x] `SENTRY_RELEASE` must be injected with the deployed git SHA by the Ploi deploy script, or
  PR4's release-context requirement is unmet. — `deploy:sentry-release` task in `backend/deploy.php`;
  **injection is code, the resulting release context is only observable once the runbook is executed.**

**Found while closing 9A-2 (2026-08-04) — both were silent CI-gate failures:**

- [x] **`php artisan test` exited 1 on a fully green run.** PHPUnit 11 reports test runner warnings
  through the exit code, and two `*Test.php` classes declared no tests: the stock empty
  `Tests\Unit\ExampleTest` and `Tests\Feature\FeatureTest`, the base class 155 tests extend. The
  base is now abstract *and* excluded from the Feature testsuite; the empty one is deleted. One
  risky test (a legacy-plan assertion behind a null guard no fixture ever satisfied) fixed with it.
- [x] **Freeze the static-analysis baseline** (Q21, §18.2 T6) — pulled forward from the defect
  backlog because CI's `phpstan analyse` step was red on every pull request without it. 418 level-3
  errors frozen in `backend/phpstan-baseline.neon`; verified a newly introduced error still fails
  the run.

**Deferred from 9A-1, non-blocking:**

- [ ] No boundary-value coverage on any of the three custom checks — a `>` → `>=` regression would
  be invisible. One cheap follow-up covers all three.
- [ ] `MailFailureRateCheck` counts only `failed`, not `bounced`, so a deliverability collapse
  manifesting as bounces is invisible. Revisit when the ESP lands (Q31).
- [ ] The Vite-manifest-missing branch of `app:smoke` is untested; `public_path('build/manifest.json')`
  is hardcoded rather than injectable.
- [ ] Spatie's stock `notifications` block is retained but inert in `config/health.php`, and
  re-reads `HEALTH_SLACK_WEBHOOK_URL` — two config keys per env, against the one-authoritative-entry
  rule. Prune or mark inert.
- [ ] Cache-outage behaviour is fail-open by design (alert rather than suppress), which at a
  5-minute cadence means up to 12 alerts/hour/check with no throttle. Mandated direction, unmitigated.
- [ ] `/health/ready` is unauthenticated and discloses which subsystem is down. Standard for a
  probe; noted, not judged worth changing.

---

## Phase 11 — Scanner platform, findings model, and catalog rework (D2)

**Goal:** the sellable **Automated Health Report ($49)**. The pitch price is only defensible with scanner-backed output, so this lands **before public launch**.
**Spec:** F5.12.1, F5.12.2, F5.12.5, F5.12.6; §17 Phase 11; Milestone M7.
**Depends on:** Phase 9A (launchable operations).
**Open:** Q5 (prices vs. measured cost) — still open, and only the first paid runs close it. Q32 **resolved** (legacy $5 unlock retired, rows deactivated not deleted). Q33 **resolved** by shipping in-house rules only, no Registry content.

**Status: complete (2026-08-04).** Implemented across 28 tasks on `growth-retention`, spec
`docs/superpowers/specs/2026-08-02-scanner-platform-findings-catalog-design.md`, plan
`docs/superpowers/plans/2026-08-02-scanner-platform-findings-catalog.md`. Every box below was
re-verified against the working tree on 2026-08-04, not ticked from the task log. The exit
criterion (M7) is **not** met and cannot be met in-repo — it requires a real sale.

### Tier attribute
- [x] `tier` on `AuditRequest` — `diagnostic` | `automated` | `deep_ai` | `expert`. Drives pipeline composition, resource budgets, prompt template, report rendering, and price. `App\Constants\AuditTier`, migration `2026_08_02_000001`.
- [x] Per-tier budgets (excerpt limits, token budget, scanner set) **configuration-driven, never hardcoded** — one config entry each, per Appendix A's single-source rule.

### Scanner harness — committed set, fixed order
Executed inside `AuditPipeline`'s existing guardrails; no repository code is ever executed (§4.3 stands).

- [x] 1. **scc** (MIT) — size, language breakdown, per-file complexity. Always first; its output sizes the budgets for everything after.
- [x] 2. **Gitleaks** (MIT) — supersedes the in-house secret pattern set, same counts-and-paths-only contract (F5.2.6). Also retires the §18.7 false-positive item (documentation placeholders matching).
- [x] 3. **OSV querybatch** — existing `DependencyAuditor` integration, retained as-is with its degrade-to-zero behavior.
- [x] 4. **jscpd** (MIT) — cross-language duplication, supersedes the heuristic. Capped file set.
- [x] 5. **Semgrep CE** (LGPL-2.1 engine) — quality/security SAST, most expensive, runs last. **Permissive or in-house rulesets only** — the Registry license forbids use in a competing commercial product (Q33).
- [x] Per-tool timeout; a failed scanner contributes no findings, is recorded in the pipeline log, and **never fails the run on its own**.
- [x] Keep the existing collectors: git facts, hotspots, tooling detection, manifest summaries.
- [x] Explicitly **not** in this phase: SonarQube, Trivy, import-graph/SCIP, Lizard. CodeQL excluded permanently (license prohibits commercial service use). — Held: `app/Services/AuditReport/Scanners/` contains the five committed tools and nothing else.

### Findings model
- [x] One internal findings model, SARIF as the interchange format where the tool supports it
- [x] Deduplication across tools
- [x] **Grouping into problem families** (rule family × directory), ranked by severity × count
- [x] Prompt rework in `PromptComposer` / `ClaudeAnalyzer`: the model receives metrics plus top problem *groups*, never the raw finding list, and narrates each group — what it is, what it affects, what fixing it buys. One lint error must never become one report item. Grouping is also the prompt-size cost control.
- [x] Report template rework to render grouped narration
- [x] `ScoreCalculator` formulas extended to consume scanner signal — measurement still owns the numbers, findings feed the formulas and never the reverse

### Scoring-formula versioning (Q14 — §18.7 top-five #4)
- [x] Version the formulas and record the version on each report. Phase 11 *changes* the formulas, so without this the change silently corrupts every historical delta and benchmark (§18.2 T4). This is the phase where it stops being cheap.
- [x] Same argument for an explicit payload schema version (Q15, §18.2 T5) — the payload contract changes here too.

### Cost telemetry (F5.12.6)
- [x] Record model tokens in/out, scanner wall time, and repository size per run, so cost per audit is measurable per tier from the first paid runs. Feeds Q5 (validate on the first 20–30 paid runs) and PR16 (unit economics confirmed against published prices).

### Catalog and marketing
- [x] Rebuild the purchasable catalog, seeded idempotently per F5.4.9: three one-time tier products ($49 / $199 / $999+) plus the subscription grid (Starter $59 / Growth $149 / Agency $499 / Enterprise from $1,500)
- [x] Subscription allowances meter tier-1 runs; higher-tier runs and Deep AI credits read from plan metadata
- [x] **Q32 decision:** retired, per the recommendation. `config('pricing.retired')` names the unlock product and the orphaned `audit-scale-monthly` plan; the seeder deactivates rather than deletes, so already-purchased unlocks keep rendering.
- [x] Marketing pricing surface synchronized — every monetary and quota figure read from backend configuration (F5.7.6, A15)
- [x] Supersede the Appendix A pricing rows

**Exit:** Milestone M7 — first paid tier-1 report sold at the new price, with measured cost per audit behind it.

---

## Phase 12 — Deep AI review (D2)

**Goal:** the flagship **Deep AI Code Review ($199)**. Immediately after launch.
**Spec:** F5.12.3, §17 Phase 12, Milestone M8.
**Depends on:** Phase 11's findings model.

**Status: complete (2026-08-04).** Implemented across 13 tasks on `growth-retention`
(commits `5ec4830`..`f712729`), spec/plan under `docs/superpowers/plans/2026-08-04-deep-ai-review.md`.
Every box below was re-verified against the working tree on 2026-08-04, not ticked from the task
log. Gate re-run for this phase: `vendor/bin/pint --test` clean (950 files), `vendor/bin/phpstan
analyse` reports `[OK] No errors` against the frozen baseline, `php artisan test` green twice in a
row (896 tests / 2245 assertions, no risky tests, identical both times). The exit criterion (M8)
requires a *paid* tier-2 report and, like Phase 11's M7, is **not** met and cannot be met in-repo.

- [x] **Risk-file selection** — deterministic and logged, 20–40 files, never the whole repository. Three signals: churn×size hotspots (existing), scanner-finding density (from Phase 11), sensitive-domain path heuristics (auth, authorization, payments, uploads, secrets handling). Import-graph centrality is a deferred fourth signal — tier 2 must not wait on graph tooling. — `RiskFileSelector` / `SensitivePathMatcher` (`app/Services/AuditReport/DeepReview/`) score and rank candidates; the full selection (per-file rank plus per-signal contributions and `selection_version`) persists to the `audit_requests.risk_files` JSON column (migration `2026_08_04_000001_add_deep_review_to_audit_requests_table`). Import-graph centrality remains deferred, as scoped.
- [x] **File-content review** with cross-module context, returning findings bound to files, covering business logic, authorization, and architectural risk; each carries evidence, recommendation, and effort sizing — `DeepReviewer` interface / `ClaudeDeepReviewer` impl with `DeepReviewPromptComposer` (`app/Services/AuditReport/DeepReview/`) produce the file-bound findings; `DeepFindingSanitizer`'s hallucination guard drops any finding bound to a file the model was never actually shown.
- [x] **Payload contract extension** (`ReportPayload`) for file-bound findings, validated by the canonical validator — schema bumped to v3, with `validateFileFinding` covering evidence, recommendation, and effort sizing per finding.
- [x] **Per-run token budget** — exceeding it truncates the file list, never the contract — `RiskFileSelector::fit()` bounds the selected set to the configured budget (`config('audit.tiers.deep_ai.deep_review')`); estimated-vs-actual input tokens are recorded (`audit_requests.deep_review_input_tokens`) for calibration.
- [x] **Report rendering** for the deep section — `resources/views/reports/partials/deep-findings.blade.php`, included from both the PDF template (`reports/audit.blade.php`) and the web report (`reports/audit-web.blade.php`).
- [x] **$199 product** plus plan-metadata Deep AI credits — **already true before this phase**: the `audit-deep-ai` product ($199 / 19900 cents, `config/pricing.php`) and the `audit_deep_ai_credits` plan-metadata field shipped as part of Phase 11's catalog rework, not this one. Recorded here for completeness, not claimed as Phase 12 work.
- [x] Fold in Q17 here at the latest (§18.7 top-five #2): exclude files matching secret patterns from excerpts sent to the model. Tier 2 sends far more source to a third party than tier 1 does, and Gitleaks already identifies the files. — `SecretFileFilter` (`app/Services/AuditReport/SecretFileFilter.php`) excludes Gitleaks-flagged paths and denylisted basenames from excerpts on **every** tier, not just tier 2, with a regression test covering the excerpt loop's slot-backfill behavior.

**Exit:** Milestone M8 — first paid tier-2 report delivered within its token budget. Code complete; unmet pending a real paid run, same as Phase 11's M7.

---

## Phase 13 — Expert review workflow (D2)

**Goal:** the **Expert Audit (from $999)** and the upsell path into remediation work. Build when the first expert order justifies it — until then, expert orders are fulfilled manually through the existing results-override tooling.
**Spec:** F5.12.4, §17 Phase 13, Milestone M9.
**Depends on:** Phase 12; reviewer staffing decision (Q8 / Q34).

- [ ] `expert_review` status added to the closed enumeration in `App\Constants\AuditRequestStatus`, with display mapping **everywhere** — the single status mapper, list views, detail views, widgets, and the customer dashboard's plain-language description
- [ ] **Delivery hold**: for expert-tier runs only, the pipeline stops after persistence and the report is not auto-sent. §4.3's "no human review gate" exclusion narrows to the diagnostic, automated, and deep-AI tiers; the expert tier's gate *is* the product.
- [ ] **Operator review queue** in the admin panel: reports awaiting review; edit findings through the canonical payload validator; remove false positives; adjust priorities; fill the expert payload section (expert summary, review notes, reviewed-by, reviewed-at)
- [ ] **Reviewer permission**, distinct from full administrator rights
- [ ] **Publish action**: send + regenerate PDF + transition to sent
- [ ] **Human-verified rendering** in the report template, for this tier only
- [ ] **$999+ product**
- [ ] Q34: publish a review SLA on the product page only after the first three are delivered on time
- [ ] Fixes the §18.7 consistency defect if done right — one audit status currently reconciles to no statistics bucket, so operator tiles don't sum to the total. Adding a status makes this worse unless the buckets are made exhaustive.

**Exit:** Milestone M9 — first expert-reviewed report published through the workflow.

---

## Phase 9B — Production readiness remainder

**Goal:** required before scale, not before launch (§17.2 priority 7).
**Spec:** §17 Phase 9, §20.3, Milestone M6.

- [ ] **Backups automated and a restore rehearsed** at least once (Q11, PR7, §18.3 O7) — currently no stated objectives, so effectively none
- [ ] **Retention policies decided and implemented; erasure procedure documented** (Q19, Q20, PR11). The email-log body column is the pressing one (§18.5 SC4) — anonymize in place where a financial or audit trail must survive.
- [ ] **Automated browser coverage** for the §16.3 flows (PR14, §18.7) — today *every* visual and client-side behavior is unverified by test. The alternative the spec permits is listing each unverified behavior explicitly as such.
- [ ] **Performance baseline** against the repository corpus, with every configured limit proven to actually bind (PR15)
- [ ] **Operations dashboard** (§15.7)
- [ ] **Model default review** (Q9) — evaluate the current generation on a fixed corpus for quality, latency, and cost per audit; keep the identifier configuration-driven either way
- [ ] Confirm the remaining assumptions before they are relied on: Q24 (cookie domain scoped for the credentialed probe in production — the navigation swap fails silently in production if wrong), Q26 (live provider credentials, placeholders unmistakably non-live), Q27 (excerpt transmission disclosed), Q12 (losing queued audits on a queue outage is acceptable), Q23 (submitter authorization attestation)
- [ ] **PR12**: real transaction verified end to end against live provider credentials

**Exit:** Milestone M6 — §20.3 satisfied, each claim backed by observed evidence per PR18.

---

## Cross-cutting: recorded defects (§18.7)

Non-blocking, but three of the spec's highest-value five are folded into phases above (worker alerting → 9A, secret-file exclusion → 12, formula versioning → 11, mail render logging → 10). The rest, unscheduled:

**Correctness and safety**
- [ ] Intent read-then-delete is not transactional — concurrent order events could both observe an intent
- [ ] Reminder check-then-create is not atomic across overlapping scheduler runs
- [ ] Bonus accrual is read-modify-write; concurrent referrals for one referrer lose an increment. **Money-adjacent — use an atomic increment.**
- [ ] Carrying an unlock forward on re-run restamps the unlock time instead of preserving the original
- [ ] Attempt counters increment via read-then-write
- [ ] Funnel event metadata may be absent; readers must guard

**Security control the spec treats as a control, not a nicety**
- [ ] **Actor-attributed operator change log** (Q16, top-five #3). Operators can rewrite customer-facing content and grant paid access with no attribution today (§18.3 O4).
- [ ] **Throttle the session-status endpoint** (Q18). `routes/web.php:55` — `/api/auth/status` is unauthenticated, cheap to call in a loop, and **verified to have no throttle middleware**.

**Consistency**
- [ ] One audit status reconciles to no statistics bucket — operator tiles don't sum to the total
- [ ] Column, status-description, and visibility logic duplicated across list views, detail views, and widgets (§18.2 T8)
- [ ] Prompt composition duplicates the context-append block between composition and preview
- [ ] One layout heading rule isn't scoped to the public wrapper

**Efficiency**
- [ ] Several widgets issue one query per tile rather than one grouped query
- [ ] Relations not eagerly loaded in list contexts
- [ ] Metrics collection spawns repeated git invocations (Phase 11 partly supersedes this — scc replaces several)
- [ ] Pipeline steps save per step rather than batching
- [ ] Container start repairs permissions across the whole tree rather than only mismatched files
- [ ] Navigation visibility performs a query and a service call per render

**Coverage and hygiene**
- [ ] One known time-sensitive test is flaky under load — freeze the clock (§18.2 T7)
- [ ] Aggregate processing time uses a database-specific function
- [ ] Schedules have no database-level uniqueness per user and repository
- [ ] Corrupt lockfiles yield zero packages silently, with no operator signal
- [ ] One legacy checkout route references a view that does not exist (pre-existing, unrelated to the audit domain)
- [x] **Freeze the static-analysis baseline** (Q21, §18.2 T6). Done 2026-08-04 as part of closing 9A-2 — see the "Found while closing 9A-2" note in Phase 9A. 418 errors frozen in `backend/phpstan-baseline.neon`.

---

## Decisions that gate the work

| # | Question | Gates |
| --- | --- | --- |
| Q13 | Error tracking / metrics / alerting stack | **All of Phase 9A** |
| Q31 | SMTP/ESP transport for production mail | Phase 9A (PR13); keeps ESP-webhook tracking a config change later |
| Q10 | Staging before launch | Phase 9A; nothing else verifies checkout, live mail, or the credentialed probe |
| Q33 | Which Semgrep rulesets ship in tier 1 | Phase 11 — licensing review must precede launch, rule by rule |
| Q32 | Does the legacy $5 unlock survive | Phase 11 catalog rework and all pricing copy |
| Q5 | Are tier prices final at measured cost | Phase 11 telemetry answers it; PR16 blocks on it |
| Q14 / Q15 | Formula and payload schema versioning | Phase 11 — cheap now, expensive after the formulas change |
| Q8 / Q34 | Support ownership and reviewer staffing/SLA | Phase 9A (failed/followup) and Phase 13 (expert queue) |
| Q11 / Q19 / Q20 | Backup objectives, retention, erasure | Phase 9B |
| Q22 | Automated deployment with smoke gate and rollback | Phase 9A |

---

## Order

```
Phase 10  ──►  Phase 9A-1  ──►  Phase 11  ──►  9A-2 runbook  ──►  ⟨LAUNCH⟩  ──►  12  ──►  13
  ✅ done       ✅ done          ✅ done        ⬅ HERE            first sale    flagship  expert

Phase 9B — before scale, not before launch
Defect backlog — opportunistic, except the items already folded into 10/11/12
```

Every remaining pre-launch item is **infrastructure execution, not code**: run
`docs/superpowers/runbooks/2026-08-02-launch-operations-runbook.md` — DNS, staging, first
production deploy, rollback rehearsal, Postmark verification, alert-delivery proof, and the two
9A-1 carry-overs it owns (Ploi's monitor pointed at `/health` with a real token; accepting that a
database outage is only detectable off-box).

One unscheduled item sits outside every phase: `growth-retention` is **202 commits** ahead of
`main` and has never been merged. Note the ordering constraint this now carries — `deploy-staging.yml`
deploys on every merge to `main`, so merging before a staging server exists produces a red
workflow on the first push. Stand up staging first, or merge with the workflow disabled.
