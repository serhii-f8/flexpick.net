# Phase 12 — Deep AI Review ($199)

**Date:** 2026-08-04
**Spec references:** F5.12.3, F5.12.6, §17 Phase 12, Milestone M8, §18.7 Q17
**Depends on:** Phase 11's findings model, tier profiles, and payload v2

---

## 1. Scope

Phase 12 delivers the flagship **Deep AI Code Review ($199)**: AI review of the source of
the 20–40 riskiest files in a repository, producing findings bound to files, with evidence,
recommendation, and effort sizing.

Phase 11 left more of this pre-wired than the roadmap checklist implies. Already shipped:

- `AuditTier::DEEP_AI` exists, and `TierProfileResolver` reads a `deep_ai` profile that
  currently clones `automated` with an explicit "Phase 12 diverges this" comment.
- The **$199 product exists** (`config/pricing.php` → `audit-deep-ai`), is seeded idempotently
  by `AuditMonetizationSeeder`, and `HandleAuditTierOrder` already creates a `deep_ai` run on
  purchase.
- **Plan-metadata Deep AI credits exist and are metered** (`audit_deep_ai_credits`,
  `AuditEntitlementService::remainingDeepAiRuns()`).
- Purchased runs are auto-unlocked via `prepaid`, so no new access gating is required.
- Cost telemetry columns for the tier-1 call already record.

Two of the roadmap's seven bullets — the $199 product and plan-metadata credits — are therefore
already satisfied. This design covers the remainder:

1. Risk-file selection
2. The file-content review call
3. Payload contract v3
4. The per-run token budget
5. Deep-section report rendering
6. Q17 secret-file exclusion

### Out of scope, deliberately

- **A public sample of a deep report.** `resources/data/sample-audit-report.json` stays as-is;
  it represents the free/automated report, and adding `file_findings` would show visitors
  paid-tier content as the free sample. A separate deep-tier sample fixture and route is a
  marketing conversion asset, not part of this contract.
- **Deep-review output quality.** Nothing here proves findings are good, only that the plumbing
  is correct and the contract holds. Quality is established by operators reading real reports
  (risk P4's stated mitigation).
- **Import-graph centrality** as a fourth selection signal — deferred per F5.12.3 so tier 2 does
  not wait on graph tooling.
- **Phase 13's delivery hold and reviewer queue.** The `expert` tier gets the deep-review
  profile here so Phase 13 adds only the hold and the queue.

---

## 2. Decisions

Seven decisions shaped everything below. Each is recorded with its reasoning, because the
reasoning is what a future change has to overturn.

### D1 — A failed deep review degrades and delivers

The tier-1 payload is complete and valid before the deep stage runs. On failure the customer
receives the automated-tier report, the report **states plainly that the deep section is
missing**, and an operations alert fires.

The alternative — failing the run — would cost a full re-clone, re-scan, and re-analysis to
recover from a transient API hiccup. The alternative of degrading *silently* is the failure mode
that actually costs money: a paying customer receiving a lesser product without knowing. Naming
the gap makes it recoverable.

### D2 — One call, all selected files

Cross-module context is what tier 2 sells over tier 1. Batching per-file structurally cannot
produce a finding that spans the auth layer and the payment layer, which turns a $199 review
into a more expensive linter. A two-pass map-reduce recovers some reach but reasons over
summaries rather than code, weakening the evidence the contract requires.

A single call is also the only structure under which "the token budget truncates the file list"
is coherent: truncation is a pre-flight decision about one call's input.

### D3 — Rank-normalized weighted sum for selection, weights versioned

Quota-based selection (reserve N slots per signal) needs no normalization and is more
explainable, but budget truncation against quotas requires inventing a second policy for *which*
quota to shrink — one that can silently delete an entire signal. A single ranked list truncates
from the bottom, unambiguously.

The weighted sum also handles consensus correctly: a file that is simultaneously high-churn,
finding-dense, and under `auth/` is the riskiest file in the repository and ranks first, where
quota selection would dedupe it into an ordinary single entry.

Weights are versioned alongside the scoring formula version so any report can be reproduced
against the selection policy that produced it.

### D4 — Local character heuristic for the token budget, with calibration

An exact `count_tokens` pre-flight buys precision that changes no decision that matters: the
number decides whether file #38 makes the cut, and a 15% error means 37 files or 39. It cannot
cause a contract violation, because the contract is enforced by the output schema, not the input
count. Paying a network round trip and a new failure mode for that is a bad trade.

The estimate is stored next to the actual token count from the API response, so the safety
margin can be tightened from evidence after 20–30 paid runs.

### D5 — Secret-file exclusion applies to all tiers

§18.7's argument for Q17 is that tier 2 "sends far more source to a third party than tier 1
does." That is a claim about magnitude, not permissibility. `ExcerptCollector` already ships the
15–50 largest files' contents to Anthropic on every tier, selecting purely by size with no
exclusion — and a `.env` file in a vibecoded repository is frequently large enough to make that
cut. If transmitting it is wrong at $199 it was already wrong at $0, and the free tier has far
more volume.

Applying the filter at one shared chokepoint is also cheaper to build than a deep-review-specific
filter plus a permanently unguarded legacy path.

### D6 — The deep reviewer receives deterministic context only

It gets the selected files, metrics, ranked finding groups, and the selection rationale. It does
**not** get the tier-1 narrative prose.

Groups and metrics are valuable: the model learns Semgrep already flagged three SQL-injection
candidates in `app/Http/` and can confirm those against real source — a verified scanner hit is
worth more than a raw one — or spend attention elsewhere.

The tier-1 narrative is another model's opinion, and models anchor hard on prior framing. Feeding
it in means paying $199 for elaboration of the $49 report. It also destroys the ability to
evaluate the tier: under deterministic-only context, agreement between the two sections is
genuine corroboration rather than echo.

### D7 — The deep review does not touch the scores

`ScoreCalculator` runs before any AI stage and owns the numbers. F5.12.2 is explicit that
findings feed the formulas and the narrative, never the reverse, and risk P5 is precisely the
credibility damage of prose contradicting numbers. Deep findings render as findings. The scores
a customer sees at $199 are computed identically to those at $49.

---

## 3. Architecture

A new namespace, `app/Services/AuditReport/DeepReview/`. Nothing in it is referenced by tiers
that do not run deep review.

### Pipeline placement

`AuditPipeline::run()` gains one conditional stage between `analyzer->analyze()` and
`reportService->create()`:

```
scanners → dedupe/group → metrics → ScoreCalculator → analyzer->analyze()
   → [if $profile->deepReview !== null]
        RiskFileSelector → DeepReviewer → hallucination guard → merge into payload
   → reportService->create() → send()
```

Placing the stage *after* `analyze()` is what makes D1 work: the tier-1 payload is complete and
valid before the deep stage is attempted, so failure loses a section rather than a report.

### Components

| Class | Responsibility |
| --- | --- |
| `RiskFileSelector` | Ranks and selects files under the token budget; returns `RiskFileSelection` |
| `RiskFileSelection` / `SelectedFile` | Value objects: chosen files, per-signal contributions, truncation state, estimated tokens, selection version |
| `SensitivePathMatcher` | The sensitive-domain path signal, configuration-driven |
| `DeepReviewer` (interface) | The review contract |
| `ClaudeDeepReviewer` | Implementation, bound in `AppServiceProvider`, mirroring `AiAnalyzer`/`ClaudeAnalyzer` |
| `DeepReviewResult` | File-bound findings plus input/output token counts |
| `SecretFileFilter` | Q17. Lives at `AuditReport/`, one level up, because it is shared |

`SecretFileFilter` sits outside `DeepReview/` deliberately: it guards `ExcerptCollector` on every
tier, and burying an all-tier privacy guard inside the tier-2 namespace would misrepresent its
reach.

Introducing `DeepReviewer` as an interface with a separate implementation follows the existing
`AiAnalyzer`/`ClaudeAnalyzer` split, which exists to satisfy risk T1 (provider change must not
stop the pipeline) and to make the stage fakeable in tests.

### Wiring the Gitleaks signal

`ExcerptCollector` receives only `RepoContext`, which today has no access to findings.
`RepoContext` gains `secretPaths` alongside `inventory`, populated by the pipeline from
`$suite->findings` filtered to `tool === 'gitleaks'`, set before `metricsCollector->collect()`.

Deriving it in the pipeline from deduped findings — rather than having `GitleaksScanner` stash it
internally — matches how `inventory` already flows and keeps scanners free of cross-stage
knowledge. `RepoContext`'s existing docblock already establishes it as the correct home for
per-run state shared across stages, and explains why (Horizon workers are long-lived, so state on
a scanner instance would leak between runs).

`HotspotCollector` gains a parallel `withChurn()` call recording the full per-path change map,
because selection needs churn for every candidate file, not the top 10 it returns for metrics.
This avoids a second `git log --name-only` over a 200-commit clone and keeps one definition of
churn in the system.

### Tier configuration

`TierProfile` gains a nullable `deepReview` sub-profile. It is `null` for `diagnostic` and
`automated`, so the pipeline's conditional is `$profile->deepReview !== null` and no tier name is
hardcoded in the pipeline — the tier configuration stays the single source of truth.

The **`expert` tier receives the deep-review profile too**. F5.12.4 defines tier 3 as "everything
in tiers 1–2, plus a human review stage," so expert runs must produce a deep section for the
Phase 13 reviewer to edit.

Configuration lives in `config/audit.php`:

```php
'deep_ai' => [
    // ... existing tier-1 keys unchanged ...
    'deep_review' => [
        'min_files'          => 20,
        'max_files'          => 40,
        'file_bytes'         => 12000,
        'min_file_bytes'     => 4000,
        'input_token_budget' => 150000,
        'max_tokens'         => 16000,
    ],
],

'deep_review' => [
    'selection_version' => 1,
    'weights' => ['churn' => 0.4, 'findings' => 0.4, 'sensitive' => 0.2],
    'chars_per_token' => 3.5,
    'safety_margin'   => 1.15,
    'max_findings'    => 40,
    'path_exclusions' => ['vendor/', 'node_modules/', 'dist/', 'build/', '*.min.js', '*.lock'],
    'sensitive_patterns' => [
        '*auth*', '*login*', '*session*', '*token*', '*permission*', '*polic*', '*role*',
        '*payment*', '*billing*', '*checkout*', '*invoice*', '*subscription*', '*webhook*',
        '*upload*', '*file*store*', '*crypt*', '*password*', '*secret*', '*credential*',
    ],
],

'secret_files' => [
    'denylist' => [
        '.env*', '*.pem', '*.key', '*.p12', '*.pfx', 'id_rsa*',
        '.npmrc', '.netrc', '*credentials*.json', '*.keystore',
    ],
],
```

---

## 4. Risk-file selection

### Candidate pool

Files from the scc inventory, minus secret-filtered files (§6), minus `path_exclusions` for
vendored and generated code.

Test files are **not** excluded. Test quality is legitimately reviewable, and their absence is
often the finding.

### The three signals

Each is computed over the whole candidate pool.

1. **Churn × size** — `changes × loc`, using the full per-path change map recorded on
   `RepoContext` by `HotspotCollector`.
2. **Finding density** — severity-weighted finding count per path, computed from the deduped
   findings using the existing `config('audit.findings.severity_weights')`. Reusing those weights
   means one critical outranks a pile of info findings, and the reviewer's attention follows the
   same severity model the scores already use.
3. **Sensitive domain** — binary 1.0 / 0.0 from `sensitive_patterns`, covering authentication,
   authorization, payments, uploads, and secrets handling. Graded per-category weighting would be
   invention without data.

### Normalization

Raw units are incomparable, so each signal maps to 0–1 by rank, with one rule that matters:

> **A raw value of zero normalizes to zero.** Nonzero values are ranked among the nonzero set
> only, mapping to `(0,1]`.

Without this, naive percentile rank would award a substantial score to a file with no findings
purely because most other files also have none — and since *both* finding density and sensitive
domain are mostly-zero signals, finding density would be close to pure noise on a clean
repository.

### Combination and ordering

```
score = w_churn · n_churn + w_findings · n_findings + w_sensitive · n_sensitive
```

Sorted descending, ties broken by path ascending for a total order. Take up to `max_files`, then
apply the token budget (§5), never dropping below `min_files` except as §5 defines.

Starting weights are `0.4 / 0.4 / 0.2`, with sensitive-domain lowest because a path heuristic is
the crudest of the three. They are a labelled starting guess, tunable from the persisted
per-signal contributions.

### Determinism and logging

Same repository state produces the same list: clone depth is fixed configuration, every input is
deterministic, and the tie-break makes the order total.

Selection persists to a new `risk_files` JSON column on `audit_requests`: each file with its
rank, raw and normalized per-signal values, final score, and whether the budget cut it, plus the
`selection_version` and the estimated token count. A pipeline-log line records the summary.

Persisting per-signal contributions rather than just the file list is what makes the weights
tunable later — you can ask "would weighting churn higher have selected the files the findings
actually came from?" without re-running anything.

---

## 5. Token budget and cost telemetry

### Budget arithmetic

Computed in `RiskFileSelector`, so selection and budget are one decision:

1. Read each candidate's content capped at `file_bytes`.
2. Estimate tokens as `ceil(bytes ÷ chars_per_token) × safety_margin`. Fixed overhead — system
   prompt, metrics, groups, selection rationale — is estimated the same way and subtracted from
   the budget up front.
3. Accumulate in rank order until the next file would exceed `input_token_budget`. Stop, record
   `truncated`.

At the proposed defaults a full 40-file run is roughly 137k estimated input tokens, inside the
150k budget, while a repository of large files truncates. **Truncation is the normal case on
large repositories, not an exception** — which is why the §8 disclosure matters.

`max_findings` bounds the *output* side: the prompt instructs the reviewer to return at most that
many findings, ranked by severity. This is the primary defense against the output cap being hit
and the response JSON arriving truncated, which §9 can only handle by degrading.

### Resolving the budget against the floor

These two rules can conflict, so the policy is fully defined rather than discovered at runtime:

1. If `min_files` do not fit at `file_bytes`, shrink the per-file cap uniformly toward
   `min_file_bytes`.
2. Only if `min_files` at `min_file_bytes` still overflow does selection go below the floor, and
   that logs explicitly.

Breadth beats depth here: cross-module reasoning is the tier's differentiator and needs to see
many modules, so trading per-file depth for file count is the right direction.

### Telemetry

A migration adds three nullable columns to `audit_requests`: `deep_review_input_tokens`,
`deep_review_output_tokens`, `deep_review_ms`. The existing `ai_input_tokens` /
`ai_output_tokens` stay bound to the tier-1 call.

Keeping them separate is the point. Summing them would make the marginal cost of tier 2
unmeasurable, and F5.12.6 exists to answer "what does a $199 report actually cost us?" Split,
they give tier-1 cost, tier-2 marginal cost, and scanner wall time as three independent numbers
from the first paid run.

### Calibration loop

The estimate is stored in the `risk_files` JSON next to the actual from the API response. The
ratio between them is the real chars-per-token for this corpus, letting `safety_margin` be
tightened from evidence rather than caution.

---

## 6. Secret-file exclusion (Q17)

One class, `SecretFileFilter`, with a single method taking a candidate path list and returning
the survivors. Two independent rules, either of which excludes:

- **Gitleaks-flagged paths**, from `RepoContext::$secretPaths`.
- **Static denylist** globs from `config('audit.secret_files.denylist')`.

Both sources are used because they fail in different directions and neither subsumes the other:
Gitleaks is precise but conditional on having run, and catches secrets hardcoded into ordinary
source; the denylist is unconditional and catches the `.env` and key files by name.

### Two call sites, one filter

`ExcerptCollector` (all tiers) and `RiskFileSelector` (deep tiers). Both filter **before** file
contents are read, so excluded files are never loaded into memory, never counted against the
token budget, and never occupy a selection slot.

### What exclusion does and does not remove

It withholds file **contents** from the model. It does not remove the file from the findings
model, the scores, or the report — a Gitleaks hit still renders as "credential detected in
`config/database.php`", because the `Finding` value object structurally cannot carry a matched
secret value. The customer still learns they have a leaked credential and where; the model never
sees the credential.

### Degradation

If Gitleaks did not run, only the denylist applies. The run proceeds, and `appendPipelineLog`
records that content filtering ran on the denylist alone. The denylist is the guard that actually
catches `.env` files; Gitleaks is the enhancement.

### Behavioral change to existing tiers

This alters diagnostic and automated behavior: repositories whose largest files include a `.env`
or key file now send fewer excerpts. That is the intended fix, but existing excerpt-related tests
will need updating.

---

## 7. Payload contract v3

`ReportPayload::VERSION` goes to 3. `validate()` gains a v3 arm and keeps v1 and v2 so stored
reports keep rendering. v3 is v2's rules plus two optional top-level keys.

### `file_findings`

| Field | Type |
| --- | --- |
| `path` | string, required |
| `line` | int or null |
| `title` | string, required |
| `severity` | enum: `critical` / `high` / `medium` / `low` / `info` |
| `category` | enum: `business_logic` / `authorization` / `architecture` / `security` |
| `evidence` | string, required |
| `recommendation` | string, required |
| `effort` | enum: `S` / `M` / `L` |
| `related_paths` | list of string, may be empty |

`related_paths` is where the cross-module requirement becomes concrete and checkable — it
distinguishes "this function is unsafe" from "this controller trusts a value that `Billing.php`
never validates."

Severity reuses the existing `SEVERITIES` constant and effort reuses `fix_first_plan`'s sizing,
so the deep section renders and sorts alongside everything else instead of introducing a parallel
scale.

`category` extends the spec's three (business logic, authorization, architecture) with
`security`, because a model reading real source will find genuine vulnerabilities and filing
those under `business_logic` would misrepresent them.

### `deep_review`

Pipeline-authored metadata, not model output: `files_selected`, `files_reviewed`, `truncated`
(bool), `selection_version`, `degraded` (bool), and an optional `reason`.

This drives the truncation and degradation disclosures. It lives in the payload rather than being
read from `audit_requests` at view time so that rendering is self-contained and PDFs and
historical reports stay correct. There is precedent: the pipeline already overwrites
`$payload['scores']` with the deterministic set after the model returns.

### Both keys are optional in v3

The validator is context-free and does not know the tier — and must stay that way, or the
contract becomes coupled to the catalog and breaks whenever pricing changes. More practically,
D1 requires degradation to yield a *valid* payload: a deep run whose review failed is a valid v3
report with `file_findings` absent and `deep_review.degraded` true.

### Hallucination guard

Before merging, the deep stage:

- drops any finding whose `path` was not in the set actually sent to the model;
- strips `related_paths` entries not present in the repository inventory.

A finding bound to a file the model never saw is fabricated by definition. Drops are counted in
the pipeline log; a nonzero count is a prompt-quality signal worth watching.

This check lives in the deep stage rather than in `validate()`, because it needs run context the
validator deliberately does not have.

---

## 8. Report rendering

### Placement and ordering

A new "Deep file review" section, after "Risks, ranked by impact" and before "What to fix first",
in both templates. The existing narrative runs repository-level → file-level → action, and
file-bound findings belong where the report stops describing the codebase and starts pointing at
lines. The fix-first plan stays the closer.

Findings are grouped by file; files ordered by their highest-severity finding; findings within a
file by severity, ties by path and line. Grouping by file honors "findings bound to files" — a
customer opens one file and sees everything wrong with it, rather than jumping between files
while scanning a flat severity list.

`related_paths` renders inline as "also involves `app/Services/Billing.php`", visibly distinct
from a single-file hit, because it is the thing tier 1 structurally cannot produce.

### Shared partial

`resources/views/reports/partials/deep-findings.blade.php`, included by both
`audit-web.blade.php` and `audit.blade.php`.

The two templates currently duplicate every section — the PDF is standalone HTML with its own
stylesheet — and this design does not propose fixing that generally, as it is unrelated to this
work. But it should not *add* to the problem: this is the most structurally complex section
either template has, and a drifting PDF that omits findings the web report shows is a
customer-visible defect on a $199 product. The partial carries semantic markup and class names;
each template continues to style those classes in its own stylesheet.

### Gating

Titles, paths, and severities render when locked; evidence, recommendation, and effort render
only when unlocked — matching how `risks` already behaves and satisfying P2's "risk titles always
visible."

In practice deep runs are `prepaid` and auto-unlocked, so this path is defensive. It exists so
the report cannot leak paid content if an unlocked flag is ever wrong.

### Disclosures

Both driven by the `deep_review` metadata block:

- **Truncation**, at the section head: "Reviewed 28 of 40 selected files, in risk order."
- **Degradation**, near the top of the report and not buried in the section: "The deep review
  could not be completed for this run. The automated analysis below is complete, and we've been
  notified." Prominent placement is the entire point of D1.

---

## 9. Error handling and degradation

### The catch boundary

The deep stage as a whole, inside `AuditPipeline::run()`. Everything from selection through merge
is wrapped; any failure leaves the already-complete tier-1 payload untouched and sets
`deep_review.degraded = true` with a classified reason.

### One bounded retry

Transport-level failures only — timeouts, 5xx, overloaded — retried once with a short backoff
before degrading. Schema and validation failures are **not** retried: they fail identically and
retrying only doubles the token spend.

The retry sits inside the stage rather than relying on the job's retry, because a job-level retry
re-clones the repository, re-runs five scanners, and re-pays for the tier-1 analysis to recover
from a transient hiccup in the last stage.

### Failure modes

| Condition | Handling |
| --- | --- |
| API error, timeout, rate limit | Retry once, then degrade + alert |
| `stopReason !== 'end_turn'` (output cap hit) | Degrade + alert; truncated JSON is unusable |
| Payload validation throws | Degrade + alert |
| Hallucination guard drops *some* findings | Proceed; log the drop count |
| Hallucination guard drops *all* findings | Degrade + alert — a review whose every finding was fabricated is not a review |
| Zero findings returned | **Not a failure**; see below |
| Fewer than `min_files` candidates exist | Not degradation; render honestly, but alert |

### Zero findings is a designed outcome

Risk P6 is explicit that a healthy verdict must render as a confident finding rather than an
empty state. The section renders "No file-level issues found across the 30 files reviewed" with
the file list. That is a defensible thing to have paid for, and treating it as failure would both
alarm the customer and pollute failure-rate telemetry with successes.

### The small-repository case

If fewer than `min_files` reviewable files exist after exclusions, the review runs on what exists
and the report states how many files were reviewed. But someone paid $199 for a tier whose
premise is "your 20–40 riskiest files," and whether that warrants a refund or a conversation is a
human judgment — so it surfaces to operators rather than being silently absorbed.

### Alerting

The existing `OperationsAlert` notification and the Phase 9A-1 channels (`MailAlertChannel`,
`TelegramChannel`, `SlackWebhookChannel`), dispatched directly from the pipeline. Its constructor
already takes exactly what is needed — `(checkName, band, status, message)` — so the deep stage
passes `checkName: 'deep_review'`, `band: 'high'` (a paging band per `config/health.php`),
`status: 'failed'`, and a message carrying the audit request UUID and the classified reason.

Deliberately **not** a new `spatie/laravel-health` check. Those evaluate aggregate state every
five minutes and can report that a failure rate is elevated, but they cannot identify *which
customer's run to re-run*. A degraded paid run is an individual actionable event with a
per-request recovery action.

Sentry also captures the exception; the `audit_request` scope tag is already set at the top of
`run()`.

### Recovery

No new machinery. An operator re-runs the request through existing admin tooling;
`AuditReportService::create()` already replaces the prior report while preserving `unlocked_at`
and `unlock_order_id`, so the customer's access survives the re-run.

---

## 10. Testing

PHPUnit, `TestCase`-based, scaffolded with `php artisan make:test --phpunit`. The Pest snippets
in `backend/AGENTS.md` do not apply.

`Tests\Support\FakeAiAnalyzer` and the `RunsAuditPipelineWithFakes` trait already let
`AuditPipelineTest` exercise the pipeline without network access. A **`FakeDeepReviewer`** mirrors
it — configurable to return findings, return none, or throw — bound via
`$this->app->instance(DeepReviewer::class, ...)`. No test calls the Anthropic API.

### Unit level

- **`RiskFileSelector`** — the highest-value target, because its logic is pure and its bugs are
  invisible in production. Determinism (same inputs, identical order, run twice); the
  zero-normalizes-to-zero rule; tie-breaking; consensus files ranking above single-signal files;
  budget truncation cutting from the bottom; the floor holding; the shrink-per-file-bytes path;
  the below-floor path logging.
- **`SecretFileFilter`** — each denylist glob; Gitleaks paths excluded; both sources together;
  the degradation case where Gitleaks contributed nothing.
- **`ReportPayload` v3** — valid with both new keys, valid with neither, rejection of each
  malformed `file_findings` field, and v1 and v2 payloads still validating.
- **Hallucination guard** — a finding on an unsent path is dropped; a `related_paths` entry
  outside the inventory is stripped; all-dropped escalates to degraded.

### Pipeline level

- A `deep_ai` run produces `file_findings` and populates the telemetry columns.
- An `automated` run produces no deep section and makes no second call. Worth an explicit test:
  a silent regression here would bill tier-2 costs against tier-1 revenue.
- A throwing `FakeDeepReviewer` still sends a complete report, sets `degraded`, and dispatches
  the ops alert (`Notification::fake()`).
- Zero findings sends a normal report with `degraded` false, guarding the P6 confusion directly.
- The `expert` tier also runs deep review.

### Rendering

The shared partial renders findings grouped by file, hides evidence and recommendation when
locked, and shows the truncation and degradation notices when the metadata says so.

### Execution notes

Tests run inside Docker (`docker compose exec laravel.test php artisan test`). The full suite
takes roughly 150 seconds, so implementation should use targeted `--filter` runs and reserve the
full suite for checkpoints. One test command at a time — concurrent runs collide on the test
database.

---

## 11. Exit criteria

**Milestone M8 — first paid tier-2 report delivered within its token budget.**

Concretely:

1. A `deep_ai` purchase produces a run whose report contains a deep section with file-bound
   findings.
2. Risk-file selection is deterministic, logged to `risk_files`, and reproducible from the
   persisted `selection_version` and per-signal contributions.
3. The run's actual input tokens fall within the configured budget, and estimate-versus-actual is
   recorded for calibration.
4. Secret-bearing files are excluded from model input on every tier.
5. A forced deep-review failure delivers a complete tier-1 report, discloses the gap, and raises
   an operations alert.
6. All three CI gates pass: `php artisan test`, `vendor/bin/phpstan analyse`,
   `vendor/bin/pint --test`.
