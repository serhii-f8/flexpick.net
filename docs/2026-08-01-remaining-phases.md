# FlexPick — Remaining Phases

**Date:** 2026-08-01
**Source of truth:** `docs/2026-07-27-flexpick-platform-specification.md` (revision 2026-08-01, decisions **D1** and **D2**).
**Relationship to other docs:** this file expands `docs/2026-08-01-pre-launch-backlog.md` into an itemized checklist. The backlog states the execution *order*; this states the execution *contents*. The spec remains the authority on requirements — where the two disagree, the spec wins.

Every item below was checked against the working tree on branch `growth-retention` (2026-08-01). Items marked **verified absent** were grepped for and not found; items marked **verified present** cite the file that has to change.

---

## 0. Where the system actually is

| | State |
| --- | --- |
| Spec Phases 1–8 | Implemented, reviewed, fix-waved on `growth-retention` (123 commits ahead of `main`, never merged) |
| Phase 8 live path | Code complete, **never exercised against a real transport** — and D1 now deletes the platform it was built on |
| Phase 9 | **Zero implementation.** No error tracking package, no health-check route, no `.github/`, no smoke gate in `deploy.php` — all verified absent |
| Phases 10–13 | Not started. No `tier` attribute on `AuditRequest`, no scanner harness, no findings model, no `expert_review` status — verified absent |
| Last known gate state | 587 tests / 1611 assertions passing; PHPStan level 3, 416 accepted errors, no frozen baseline file |

Everything below is what remains.

---

## Phase 10 — Email simplification (D1)

**Goal:** delete the removed email platform so the code matches spec §5.8. Small, no dependencies, immediately executable. Owner intends to do this by hand.
**Spec:** §17 Phase 10, §5.8, §18.6, Q30.

### Delete — infrastructure
- [ ] `mailcoach/` application directory (**verified present** at repo root)
- [ ] `mailcoach` and `mailcoach.horizon` services in `compose.yml:17` and `compose.yml:33`, plus the `mailcoach` dependency edge at `compose.yml:45`
- [ ] `backend/docker/mysql/create-mailcoach-database.sh` (**verified present**; leave `create-testing-database.sh`)
- [ ] `MAILCOACH_ENDPOINT` / `MAILCOACH_API_TOKEN` / `MAILCOACH_UI_URL` in `backend/.env.example:131-133`, and the local `backend/.env`
- [ ] `.gitignore` entries for the mailcoach app, if any survive the directory deletion

### Delete — application code
- [ ] `backend/app/Services/AuditMail/MailcoachClient.php`
- [ ] `backend/app/Exceptions/MailcoachUnavailableException.php`
- [ ] `services.mailcoach` block in `backend/config/services.php:125`
- [ ] `open-mailcoach` admin nav action, `backend/app/Providers/Filament/AdminPanelProvider.php:43-47`

### Simplify — mailer
- [ ] `AuditMailer::send()` (`backend/app/Services/AuditMail/AuditMailer.php`) becomes **log → `Mail::send` → record outcome**: drop the constructor dependency, the `isConfigured()` branch, the `sendTransactional` call, and the fallback `last_error` write
- [ ] Fold in the §18.7 defect while the file is open: `$mailable->render()` is currently evaluated *inside* the `AuditEmailLog::create()` array, so a render failure means **no log row is ever written** — the single gap in the "audit email never silently stops" guarantee (A11). Render before create, or create-then-populate, and record a render failure as a failed row.

### Rework — email log
- [ ] Drop or repurpose `mailcoach_uuid` (`backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php:18`, `AuditEmailLog::$fillable`). Repurposing to a provider message id is the cheaper path if the ESP decision (Q31) lands soon.
- [ ] Remove the status-refresh header action, `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php:26-30`
- [ ] Remove the platform branch of the resend action, `.../AuditEmailLogResource.php:83-84`; resend keeps working from the stored subject/body
- [ ] Add explicit exception handling on the resend path (§18.7 — currently unguarded)

### Tests and docs
- [ ] Delete `backend/tests/Feature/Services/MailcoachClientTest.php` and every `Http::fake` platform-contract assertion
- [ ] Update `AuditMailerTest` and `AuditEmailLogResourceTest` for the direct-send shape; keep the exhaustive ten-message routing test — that guarantee is untouched
- [ ] `CLAUDE.md:90` names `MailcoachClient` as part of the delivery path; update it and any `backend/AGENTS.md` reference
- [ ] Stale comment at `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php:62` ("ships with the Mailcoach workstream") — the table has landed; verify the tile renders real counts

**Exit:** `grep -ri mailcoach` returns nothing outside historical docs; suite green; §20.2 *Mail routing* criteria hold in their D1-revised form.

---

## Phase 9A — Launch-blocking operations

**Goal:** the four controls without which a production outage is invisible. Spec §17.2 pulls these forward ahead of strict phase order; §17.2's post-revision ordering puts them second, right after Phase 10.
**Spec:** §15, §17 Phase 9, §18.3 O1/O2/O8, §20.3 PR4–PR6, PR8.
**Blocked on:** Q13 (which error-tracking/metrics/alerting stack — nothing is chosen; §15 is entirely recommendation).

- [ ] **Choose the stack (Q13).** One integrated option covering exceptions, metrics, and alert routing. Everything else in this phase is configuration once this is decided.
- [ ] **Error tracking** with release context on unhandled exceptions (PR4). No package is installed today — `backend/composer.json` has no sentry/bugsnag/flare/honeybadger entry.
- [ ] **Health checks** (PR5): liveness, readiness, worker health, scheduler health. Only Laravel's default `/up` exists; no dependency check may gate readiness.
- [ ] **Worker-liveness alerting** (PR6, mandatory). The spec calls this the highest-consequence silent failure in the system (§18.3 O1, §18.7 top-five #1): submissions keep succeeding while nothing runs. Alert on **oldest-pending age**, not queue depth alone — depth alone hides a stalled queue (§18.5 SC1).
- [ ] **Scheduler-missed alert** (§18.3 O2) — four scheduled commands in `routes/console.php` silently cease if the scheduler stops.
- [ ] **Deploy automation with a post-release smoke gate and a documented, rehearsed rollback** (Q22, PR8, §18.3 O8). `backend/deploy.php` has no smoke or rollback step today; no `.github/workflows` exists at all.
- [ ] **Staging environment** (Q10, PR9) — the spec's "largest infrastructure gap" (§18.3 O6). Provider checkout, live email delivery, and credentialed cross-origin session probing cannot be verified anywhere else.
- [ ] **Production mail transport** (Q31, PR13): pick the ESP, configure SPF/DKIM/DMARC, verify send → log row → resend end to end. Choosing an ESP with event webhooks keeps the deferred delivery/open/click tracking a config change rather than a migration.
- [ ] **Support ownership for `failed` and `needs_followup`** (Q8, PR17) — an owner and a response window, defined before launch. These states already imply a human response that is not staffed.

**Exit:** PR4, PR5, PR6, PR8, PR9, PR13, PR17 satisfied with observed evidence (PR18 — "not verified" is the correct report where evidence is absent).

---

## Phase 11 — Scanner platform, findings model, and catalog rework (D2)

**Goal:** the sellable **Automated Health Report ($49)**. The pitch price is only defensible with scanner-backed output, so this lands **before public launch**.
**Spec:** F5.12.1, F5.12.2, F5.12.5, F5.12.6; §17 Phase 11; Milestone M7.
**Depends on:** Phase 9A (launchable operations).
**Open:** Q5 (prices vs. measured cost), Q32 (fate of the legacy $5 unlock), Q33 (Semgrep ruleset licensing).

### Tier attribute
- [ ] `tier` on `AuditRequest` — `diagnostic` | `automated` | `deep_ai` | `expert` (**verified absent**). Drives pipeline composition, resource budgets, prompt template, report rendering, and price.
- [ ] Per-tier budgets (excerpt limits, token budget, scanner set) **configuration-driven, never hardcoded** — one config entry each, per Appendix A's single-source rule.

### Scanner harness — committed set, fixed order
Executed inside `AuditPipeline`'s existing guardrails; no repository code is ever executed (§4.3 stands).

- [ ] 1. **scc** (MIT) — size, language breakdown, per-file complexity. Always first; its output sizes the budgets for everything after.
- [ ] 2. **Gitleaks** (MIT) — supersedes the in-house secret pattern set, same counts-and-paths-only contract (F5.2.6). Also retires the §18.7 false-positive item (documentation placeholders matching).
- [ ] 3. **OSV querybatch** — existing `DependencyAuditor` integration, retained as-is with its degrade-to-zero behavior.
- [ ] 4. **jscpd** (MIT) — cross-language duplication, supersedes the heuristic. Capped file set.
- [ ] 5. **Semgrep CE** (LGPL-2.1 engine) — quality/security SAST, most expensive, runs last. **Permissive or in-house rulesets only** — the Registry license forbids use in a competing commercial product (Q33).
- [ ] Per-tool timeout; a failed scanner contributes no findings, is recorded in the pipeline log, and **never fails the run on its own**.
- [ ] Keep the existing collectors: git facts, hotspots, tooling detection, manifest summaries.
- [ ] Explicitly **not** in this phase: SonarQube, Trivy, import-graph/SCIP, Lizard. CodeQL excluded permanently (license prohibits commercial service use).

### Findings model
- [ ] One internal findings model, SARIF as the interchange format where the tool supports it
- [ ] Deduplication across tools
- [ ] **Grouping into problem families** (rule family × directory), ranked by severity × count
- [ ] Prompt rework in `PromptComposer` / `ClaudeAnalyzer`: the model receives metrics plus top problem *groups*, never the raw finding list, and narrates each group — what it is, what it affects, what fixing it buys. One lint error must never become one report item. Grouping is also the prompt-size cost control.
- [ ] Report template rework to render grouped narration
- [ ] `ScoreCalculator` formulas extended to consume scanner signal — measurement still owns the numbers, findings feed the formulas and never the reverse

### Scoring-formula versioning (Q14 — §18.7 top-five #4)
- [ ] Version the formulas and record the version on each report. Phase 11 *changes* the formulas, so without this the change silently corrupts every historical delta and benchmark (§18.2 T4). This is the phase where it stops being cheap.
- [ ] Same argument for an explicit payload schema version (Q15, §18.2 T5) — the payload contract changes here too.

### Cost telemetry (F5.12.6)
- [ ] Record model tokens in/out, scanner wall time, and repository size per run, so cost per audit is measurable per tier from the first paid runs. Feeds Q5 (validate on the first 20–30 paid runs) and PR16 (unit economics confirmed against published prices).

### Catalog and marketing
- [ ] Rebuild the purchasable catalog, seeded idempotently per F5.4.9: three one-time tier products ($49 / $199 / $999+) plus the subscription grid (Starter $59 / Growth $149 / Agency $499 / Enterprise from $1,500)
- [ ] Subscription allowances meter tier-1 runs; higher-tier runs and Deep AI credits read from plan metadata
- [ ] **Q32 decision:** retire the legacy $5 unlock and prepaid run (recommended — the free diagnostic converts directly to the $49 tier), grandfathering existing unlocks; or reposition it as a diagnostic-tier upsell
- [ ] Marketing pricing surface synchronized — every monetary and quota figure read from backend configuration (F5.7.6, A15)
- [ ] Supersede the Appendix A pricing rows

**Exit:** Milestone M7 — first paid tier-1 report sold at the new price, with measured cost per audit behind it.

---

## Phase 12 — Deep AI review (D2)

**Goal:** the flagship **Deep AI Code Review ($199)**. Immediately after launch.
**Spec:** F5.12.3, §17 Phase 12, Milestone M8.
**Depends on:** Phase 11's findings model.

- [ ] **Risk-file selection** — deterministic and logged, 20–40 files, never the whole repository. Three signals: churn×size hotspots (existing), scanner-finding density (from Phase 11), sensitive-domain path heuristics (auth, authorization, payments, uploads, secrets handling). Import-graph centrality is a deferred fourth signal — tier 2 must not wait on graph tooling.
- [ ] **File-content review** with cross-module context, returning findings bound to files, covering business logic, authorization, and architectural risk; each carries evidence, recommendation, and effort sizing
- [ ] **Payload contract extension** (`ReportPayload`) for file-bound findings, validated by the canonical validator
- [ ] **Per-run token budget** — exceeding it truncates the file list, never the contract
- [ ] **Report rendering** for the deep section
- [ ] **$199 product** plus plan-metadata Deep AI credits
- [ ] Fold in Q17 here at the latest (§18.7 top-five #2): exclude files matching secret patterns from excerpts sent to the model. Tier 2 sends far more source to a third party than tier 1 does, and Gitleaks already identifies the files.

**Exit:** Milestone M8 — first paid tier-2 report delivered within its token budget.

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
- [ ] **Freeze the static-analysis baseline** (Q21, §18.2 T6). `backend/phpstan.neon` is level 3 with no baseline file and ~416 accepted errors; "no new error category" currently depends entirely on reviewer diligence.

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
Phase 10  ──►  Phase 9A  ──►  Phase 11  ──►  ⟨LAUNCH⟩  ──►  Phase 12  ──►  Phase 13
   small        launch          before                        flagship      on first
   cleanup      blockers        launch                        revenue       expert order

Phase 9B — before scale, not before launch
Defect backlog — opportunistic, except the items already folded into 10/11/12
```

One unscheduled item sits outside every phase: `growth-retention` is 123 commits ahead of `main` and has never been merged. Deciding when that lands is a prerequisite to any of this shipping.
