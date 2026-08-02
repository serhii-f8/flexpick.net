# Phase 11 — Scanner platform, findings model, and catalog rework (D2)

**Date:** 2026-08-02
**Branch:** `growth-retention`
**Spec authority:** `docs/2026-07-27-flexpick-platform-specification.md` §5.12 (F5.12.1, F5.12.2, F5.12.5, F5.12.6), §17 Phase 11, Milestone M7.
**Checklist:** `docs/2026-08-01-remaining-phases.md` § Phase 11.
**Goal:** the sellable **Automated Health Report ($49)**, backed by real scanner output rather than in-house heuristics.

This design covers all of Phase 11 in one cycle. The phase spans six subsystems — scanner
harness, findings model, analysis rework, tier attribute, cost telemetry, and catalog — and
decomposition into separate spec/plan cycles was considered and rejected in favour of a
single cycle. The coupling risks that decision creates are recorded in
[Risks and coupling](#risks-and-coupling).

---

## 1. Decisions settled by this design

| # | Question | Decision |
| --- | --- | --- |
| Q33 | Which Semgrep rulesets ship in tier 1 | **In-house only.** ~25–40 FlexPick rules under `backend/resources/semgrep/flexpick/`. No third-party rules, so no rule-by-rule licensing review gates launch. |
| Q32 | Does the legacy $5 unlock survive | **Retired, existing unlocks grandfathered.** Product deactivated, not deleted; the read path keeps honouring `unlocked_at`. |
| Q14 | Scoring-formula versioning | **Version-scoped comparison.** `scoring_version` recorded per report; deltas and benchmarks compare only within a version. |
| Q15 | Payload schema versioning | **`payload_schema_version` recorded; validator dispatches on it** and retains v1 validation. |
| Q5 | Are tier prices final at measured cost | **Not answered here — instrumented here.** F5.12.6 telemetry makes cost per audit measurable per tier; PR16 remains open until real runs exist. |

Additional decisions taken during design, not posed by the spec:

| # | Decision |
| --- | --- |
| D11.1 | Scanners are **provisioned onto the host**, version-pinned, via a `provision:scanners` Deployer task mirrored in `backend/docker/8.4/Dockerfile`. Production is a VPS, not Docker. |
| D11.2 | `diagnostic` composes a **reduced scanner set** (scc, Gitleaks, OSV) and a small AI budget; `automated` adds jscpd and Semgrep with full budgets. |
| D11.3 | Grouped problem families are **persisted**; raw findings are **ephemeral**, in memory for the duration of a run only. |
| D11.4 | Collectors and scanners get **separate interfaces** with different failure contracts. |
| D11.5 | A dimension whose contributing scanner did not run is **not measured**, never scored. |
| D11.6 | Repository-supplied scanner configuration is **ignored**. |
| D11.7 | Backend is authoritative for pricing; an artisan command **exports** to a committed frontend data file, with CI drift detection. |

---

## 2. Current state

Verified on `growth-retention`, 2026-08-02.

| Component | State |
| --- | --- |
| `AuditRequest` | No `tier`, no cost columns. |
| `MetricsCollector.php` | 220 lines, single pass, eight responsibilities. Phase 11 supersedes three: 15 in-house secret regexes, md5 line-hash duplication, language/LOC counting. |
| `ScoreCalculator.php` | Five dimensions plus weighted `overall`. No version recorded anywhere. |
| `ReportPayload::validate()` | Hand-rolled, no schema version. |
| `PromptComposer` | `{metrics}` + `{excerpts}`; template is admin-overridable and stored via `ConfigService`. |
| `ClaudeAnalyzer` | Hardcoded `maxTokens: 16000`; **discards `$message->usage`** — no token telemetry exists. |
| `AuditDeltaService` | Compares the immediately-previous report unconditionally. Version-blind. |
| `AuditBenchmarkService` | Pools **every report ever written** into one cached percentile distribution. Version-blind. |
| `config/audit.php` | 20 flat keys, no per-tier structure. |
| `AuditMonetizationSeeder` | $5 unlock plus subscriptions at $10/$30/$60. Bears no relation to the pitch grid. |
| Scanner binaries | None installed. |
| Marketing price surface | One hardcoded string: `frontend/src/pages/index.astro:836`. |
| Deployment | `backend/deploy.php` is untouched SaaSykit boilerplate — placeholder host, `apt-get` provisioning, Supervisor, fnm. Bare VPS. |
| Observability | **Phase 9A-1 landed** (commits `c47f2f3`..`82a673b`): `app:smoke`, three custom health checks, Bugsink error tracking with a mandatory `TokenScrubber`. |

---

## 3. Architecture

```
AuditPipeline::run(AuditRequest)
│
├─ TierProfileResolver::for($request->tier) ──► TierProfile
│     scanners[]  collectors[]  excerpt_files  excerpt_bytes
│     ai_max_tokens  narrated_groups
│
├─ RepositoryCloner  preflight() → clone()                        [unchanged]
│
├─ ScannerRunner over TierProfile->scanners, FIXED ORDER
│     1 scc      ─► file inventory; sizes the budgets for everything after
│     2 Gitleaks ─┐
│     3 OSV      ─┤ each: own timeout, own catch-all
│     4 jscpd    ─┤ failure or missing binary ⇒ zero findings
│     5 Semgrep  ─┘                            + classified log entry + run continues
│                          │
│                          ▼  Finding[]  (in memory only)
├─ CollectorSuite over TierProfile->collectors
│     GitFacts · Manifests · Tooling · Hotspots · Excerpts
│     a throw here is a BUG ⇒ fails the run
│                          │
│     ┌────────────────────┘
│     ▼
├─ FindingNormalizer ─► FindingDeduplicator ─► FindingGrouper
│                                                   │
│                                                   ▼  FindingGroup[] (≤ max_groups, persisted)
├─ ScoreCalculator::calculate($metrics, $groups, $scannerRuns)
│                          ──► ScoreSet { scores, not_measured[], scoring_version }
│
├─ AiAnalyzer::analyze($metrics, $groups, $excerpts, $tierProfile, $adminContext)
│                          ──► AnalysisResult { payload, inputTokens, outputTokens }
│
└─ AuditReportService::create() → send()
       persists scoring_version, payload_schema_version, cost telemetry
```

### 3.1 Namespace layout

```
app/Services/AuditReport/
  Collectors/     GitFactsCollector, ManifestCollector, ToolingCollector,
                  HotspotCollector, ExcerptCollector
                  interface Collector { name(): string; collect(RepoContext): array }
                  a throw is a BUG and fails the run

  Scanners/       SccScanner, GitleaksScanner, OsvScanner, JscpdScanner, SemgrepScanner
                  interface Scanner { name(): string; isAvailable(): bool;
                                      scan(RepoContext): array /* Finding[] */ }
                  wrapped by ScannerRunner; a throw is NORMAL and is absorbed

  Findings/       Finding, Severity, FindingGroup,
                  FindingNormalizer (one per tool), FindingDeduplicator, FindingGrouper

  Tiers/          TierProfile, TierProfileResolver
```

Two interfaces rather than one uniform `Probe` because the failure contract genuinely
differs. F5.12.2 requires a failed scanner to contribute nothing and never fail the run —
but a `GitFactsCollector` throwing is a defect that must surface, not be swallowed. A single
interface forces a single policy, and the swallow-everything policy is the wrong one for
internal code.

`MetricsCollector` is reduced to a composer over `Collectors/` plus scc's inventory. Its
superseded halves — secret regexes, md5 duplication, language counting — are deleted, not
retained alongside the scanners.

### 3.2 Ordering

Scanners run before collectors. scc must be first per F5.12.2 (its output sizes later
budgets), and `ExcerptCollector`'s budget comes from the tier profile, so this ordering lets
scc's inventory drive excerpt selection instead of re-walking the tree.

### 3.3 Interface change to `AiAnalyzer`

`analyze()` currently takes `($metrics, $excerpts, $adminContext)` and returns a bare array,
discarding `$message->usage`. Cost telemetry is impossible without changing this. The new
signature returns an `AnalysisResult` carrying the payload and the token counts.
`ClaudeAnalyzer` is the only implementation; the blast radius is that class and its tests.

---

## 4. Tier attribute (F5.12.1)

`audit_requests.tier` — string, default `diagnostic`, backfilled to `diagnostic` for all
existing rows. Values are a closed set in a new `App\Constants\AuditTier`, following the
existing `App\Constants\AuditRequestStatus` pattern: `diagnostic`, `automated`, `deep_ai`,
`expert`.

All per-tier budgets are configuration-driven, never hardcoded (Appendix A single-source
rule). The existing flat `max_excerpt_files` and `max_excerpt_bytes` keys are **removed** —
retaining them would create a second source of truth alongside the tier entries.

```php
// config/audit.php
'tiers' => [
    'diagnostic' => ['scanners' => ['scc', 'gitleaks', 'osv'],
                     'excerpt_files' => 15, 'excerpt_bytes' => 3000,
                     'ai_max_tokens' => 4000,  'narrated_groups' => 2],

    'automated'  => ['scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
                     'excerpt_files' => 50, 'excerpt_bytes' => 6000,
                     'ai_max_tokens' => 16000, 'narrated_groups' => 12],

    'deep_ai'    => // identical to `automated` in this phase
    'expert'     => // identical to `automated` in this phase
],
```

`deep_ai` and `expert` are **defined but not differentiated here**. The column value, the
constant, and the catalog products all exist so nothing needs migrating later, but their
pipeline composition is identical to `automated` until Phase 12 adds risk-file review and
Phase 13 adds the delivery hold. This is deliberate, not an omission.

Findings-model tuning lives alongside it, so grouping behaviour is configuration too:

```php
'findings' => [
    'max_groups'         => 20,
    'max_group_examples' => 8,
    'directory_depth'    => 2,
    'severity_weights'   => ['critical' => 100, 'high' => 40, 'medium' => 10,
                             'low' => 3, 'info' => 1],
],
```

### 4.2 How a tier is assigned

The tier is set at request creation and never inferred later:

| Origin | Tier |
| --- | --- |
| Public intake form (the free funnel) | `diagnostic` |
| Dashboard run against a subscription allowance | `automated`, metered per §8.3 |
| One-time purchase of `audit-automated` / `audit-deep-ai` / `audit-expert` | the purchased tier |

The purchase path is an order listener alongside the existing `HandleAuditUnlockOrder`: on a
completed order for a tier product it creates or upgrades the `AuditRequest` with that tier
and enqueues the run. This is the money path for M7 — a locked `diagnostic` report's
call to action buys `audit-automated`, and the listener re-runs the same repository at the
higher tier rather than unlocking the thin one.

### 4.1 Tier composition rationale (D11.2)

Today the free funnel runs the full pipeline and gates only rendering, so a free run costs
what a paid run costs and $49 would buy a decryption key rather than analysis. Per F5.12.2
the model call dominates cost and scanners are compute-seconds, so the lever is composition:

- `diagnostic` — scc, Gitleaks, OSV; reduced excerpts; small AI budget; two narrated groups.
- `automated` — adds jscpd and Semgrep; full excerpts; full AI budget; all ranked groups.

Consequence, accepted deliberately: the free report becomes **visibly thinner than today**.
The md5 duplication heuristic is deleted with the rest of the superseded code and nothing
replaces it at the diagnostic tier, so duplication renders as not-measured for free runs.
Retaining the heuristic for free runs was rejected: it means two duplication implementations
and, worse, one dimension name computed two different ways depending on tier, which makes
cross-tier comparison meaningless.

---

## 5. Scanner harness (F5.12.2)

### 5.1 Contracts

```php
interface Scanner {
    public function name(): string;                 // 'semgrep'
    public function isAvailable(): bool;            // configured binary exists and is executable
    public function scan(RepoContext $ctx): array;  // Finding[]
}
```

`ScannerRunner` wraps every call: applies that tool's configured timeout, catches
`Throwable` including `ProcessTimedOutException`, and on any failure returns `[]`, records a
classified outcome, and continues. A missing binary takes the identical path — no special
case, so a half-provisioned host degrades rather than erroring.

### 5.2 Committed set, fixed order

| # | Tool | Invocation | Output | Notes |
| --- | --- | --- | --- | --- |
| 1 | scc | `--format json --by-file` | native JSON | Always first; produces `SccInventory` |
| 2 | Gitleaks | `dir --report-format sarif --redact --no-banner` | SARIF | `--redact` is load-bearing (§5.4) |
| 3 | OSV | existing `DependencyAuditor` querybatch | native | Retained as-is, degrade-to-zero |
| 4 | jscpd | `--reporters json --silent --config <ours>` | native JSON | File set capped from scc's inventory |
| 5 | Semgrep | `scan --config <our rules> --sarif --metrics=off --disable-version-check` | SARIF | Last; most expensive |

SARIF where the tool supports it (Gitleaks, Semgrep), native parsers for the rest — matching
F5.12.2's "SARIF as the interchange format where the tool supports it" rather than forcing a
translation layer onto tools that do not emit it.

Existing collectors retained unchanged: git facts, hotspots, tooling detection, manifest
summaries.

Explicitly **not** in this phase: SonarQube, Trivy, import-graph/SCIP, Lizard. CodeQL is
excluded permanently — its licence prohibits use in a commercial service.

### 5.3 Semgrep ruleset (Q33)

In-house rules only, versioned in `backend/resources/semgrep/flexpick/`, targeting the
AI-generated-code failure modes the product is pitched against:

```
flexpick/
  php/sql-interpolation.yaml          string-interpolated SQL
  php/missing-authorization.yaml      mutating routes without an authorization check
  js/tls-verify-disabled.yaml         rejectUnauthorized:false and equivalents
  common/unsafe-deserialize.yaml      unserialize/pickle/yaml.load on untrusted input
  common/debug-in-production.yaml     debug or verbose modes reachable in production config
  common/unbounded-upload.yaml        file upload handling without size or type bounds
  …
```

No third-party rules ship. This resolves Q33 outright: there is no rule-by-rule licensing
review, because there is no rule we do not own. The engine itself (LGPL-2.1) is only invoked,
never modified or redistributed. Rules we own are also quotable in the report, which
third-party rules would not be.

Every rule carries two required metadata fields, which is what lets Semgrep output feed both
grouping and scoring without a second mapping table maintained elsewhere:

```yaml
metadata:
  family:    php.injection      # → Finding::$ruleFamily, the grouping axis
  dimension: security_hygiene   # → which score dimension this rule feeds
```

`dimension` is one of the five score dimensions. Security rules feed `security_hygiene`;
maintainability and correctness rules feed `structure`. A rule missing either field fails
the ruleset test in §11 — an unmapped rule would otherwise produce findings that narrate in
the report but silently affect no score.

### 5.4 Safety properties

**Secret values must never leave the scanner.** F5.2.6 is counts-and-paths-only, but
Gitleaks' SARIF output carries the matched snippet by default. Two independent guards:
`--redact` at invocation, **and** the normalizer discards the snippet field unconditionally
rather than trusting the flag. Structurally, `Finding` has no field a secret value could
occupy — `message` carries the rule's own description, never matched content.

**Repository-supplied scanner configuration is ignored (D11.6).** Not in the spec; added
here. Three of the four scanners read configuration from the directory being scanned —
jscpd honours `.jscpd.json`, Semgrep honours `.semgrepignore`, Gitleaks honours
`.gitleaksignore` and `.gitleaks.toml`. Since arbitrary third-party repositories are cloned,
a repository can otherwise suppress its own findings, and a repository being audited has an
obvious motive. Every scanner is invoked with an explicit config path and repo-local config
disabled. §4.3 guarantees repository code is never *executed*; this closes the adjacent hole
where repository *data* steers the analyzer.

**Failure reasons are classified, never raw tool output.** Semgrep's stderr can echo matched
source lines, and failure reasons flow to the pipeline log and to Bugsink. The recorded
reason is one of `timeout`, `nonzero_exit`, `parse_failure`, `unavailable` — bounded strings,
never captured stdout or stderr. This complements rather than relies on `TokenScrubber`.

**No repository code is executed.** All five tools are static analyzers. §4.3 stands
unchanged; no dependency installation, build step, or hook execution occurs at any point.

### 5.5 Value objects

```php
final readonly class RepoContext {
    public string $path;
    public TierProfile $tier;
    public ?SccInventory $inventory;   // null until scc has run
}

final readonly class Finding {
    public string   $tool;        // 'semgrep'
    public string   $ruleId;      // 'flexpick.php.sql-interpolation'
    public string   $ruleFamily;  // 'php.injection'   ← the grouping axis
    public Severity $severity;    // enum: critical|high|medium|low|info
    public string   $path;        // repository-relative
    public ?int     $line;
    public string   $message;     // the RULE's description — never matched content

    public function fingerprint(): string;   // sha1(ruleFamily|path|line)
}
```

### 5.6 Severity normalization

The five tools disagree on scale. One mapping table, one place, tested per tool:

| Source | → | Notes |
| --- | --- | --- |
| SARIF `error` / Semgrep `ERROR` | `high` | A rule may override upward to `critical` |
| SARIF `warning` / Semgrep `WARNING` | `medium` | |
| SARIF `note` / Semgrep `INFO` | `low` | |
| Gitleaks (any finding) | `critical` | Tool emits no severity; a live credential is the highest-consequence finding the pipeline can produce |
| OSV | CVSS ≥ 9 `critical`, ≥ 7 `high`, ≥ 4 `medium`, else `low` | Falls back to `medium` when CVSS is absent |

### 5.7 Provisioning (D11.1)

Production is a bare VPS provisioned by Deployer, so binaries are installed on the host, not
baked into an image. The four tools have four install shapes: scc and Gitleaks are single
static Go binaries from release tarballs, jscpd is an npm package (Node is already
provisioned via fnm), and Semgrep is a Python package requiring Python 3 on the host.

- `provision:scanners` Deployer task installs pinned versions to `/opt/flexpick/bin`.
- `backend/docker/8.4/Dockerfile` repeats the same pins for the dev container.
- Absolute paths, pinned versions, and per-tool timeouts live in `config/audit.php` under
  `scanners.*`, so a version bump has exactly one edit site.

```php
'scanners' => [
    'scc'      => ['bin' => '/opt/flexpick/bin/scc',      'version' => '…', 'timeout' => 60],
    'gitleaks' => ['bin' => '/opt/flexpick/bin/gitleaks', 'version' => '…', 'timeout' => 120],
    'jscpd'    => ['bin' => '/opt/flexpick/bin/jscpd',    'version' => '…', 'timeout' => 180,
                   'max_files' => 2000],
    'semgrep'  => ['bin' => '/opt/flexpick/bin/semgrep',  'version' => '…', 'timeout' => 300,
                   'rules_path' => resource_path('semgrep/flexpick')],
    'osv'      => ['endpoint' => 'https://api.osv.dev/v1/querybatch'],   // existing
],
```

Concrete version numbers are pinned during implementation, at the then-current stable
release of each tool. They are recorded in config and asserted by `app:smoke` (§9.1).

### 5.8 Run provenance

`audit_requests.scanner_runs` (JSON) records, per run and per scanner: the configured
version, wall-milliseconds, finding count, and outcome — `ok`, `failed`, `timeout`, or
`unavailable`. This makes "a failed scanner never fails the run" auditable after the fact
instead of invisible, feeds the scanner-wall-time half of F5.12.6, and is the data source
for the health check in §9.2.

A scanner version change alters findings the same way a formula change alters scores, which
is why the version is recorded per run rather than read from config at analysis time.

---

## 6. Findings model

```
Finding[]  (per tool)
    │
    ├─ FindingNormalizer       one per tool; the two SARIF tools share a base
    ├─ FindingDeduplicator     merge on fingerprint = sha1(ruleFamily|path|line)
    │                          collision ⇒ keep max severity, union `tools`
    └─ FindingGrouper          key = (ruleFamily, directory at configured depth)
                               score = Σ weight(severity) across the group
                               keep top `max_groups`, each with capped examples
                                        │
                                        ▼
                                  FindingGroup[] → audit_finding_groups
```

### 6.1 Normalization cases that are not uniform

- **OSV findings have no source location** — they are manifest-level. Normalized with `path`
  set to the declaring manifest (`composer.lock`) and `line` null. They group under
  `dependencies` × the manifest's directory, which is the correct narration unit anyway.
- **jscpd findings are clone *pairs*** spanning two locations. Emitted as one `Finding` per
  occurrence rather than one per pair, so a block duplicated into four directories produces
  findings in all four and groups where a reader would look for it.
- **Gitleaks emits no severity** — mapped to `critical` per §5.6.

### 6.2 Grouping and ranking

Group key is `(ruleFamily, directory)`, where directory is the first *N* path segments with
*N* configurable (default 2). Root-level files group under `.`.

Ranking is `score = Σ weight(severity)` over the group's findings, weights in config. This is
literally severity × count when a group is uniform, and degrades correctly when it is not —
one `critical` outranks a pile of `low`, which a plain count would invert.

The top `max_groups` are persisted (default 20). Of those, the tier's `narrated_groups` are
sent to the model. Each group carries at most `max_group_examples` example locations
(default 8) as narration evidence — `{path, line}` only, never content.

### 6.3 Determinism is a requirement, not an emergent property

Same repository, same scanner versions, same rules ⇒ byte-identical groups.

Score deltas (F5.2.8) already depend on repeat runs scoring identically; persisting groups
extends that dependency to *which* groups appear and *which* examples they cite. Concretely:
every sort is total with an explicit tie-break on a stable key; example selection is
highest-severity-then-path-ascending; nothing may depend on PHP hash-map iteration order.

`MetricsCollector` has this exposure today — `$secretFindings` is keyed by pattern name and
its ordering is incidental — which is one more reason the superseded code is deleted rather
than kept.

### 6.4 Schema

```
audit_finding_groups
  audit_request_id   FK cascade         index
  rule_family        string           ─┬─ composite index, for later cross-run comparison
  directory          string           ─┘
  severity           string             max severity within the group
  count              unsigned int
  score              unsigned int       ranking, denormalized so ordering is a plain sort
  examples           json               capped {path, line} list — no content, ever
  tools              json               which scanners contributed
```

### 6.5 Deliberately deferred

**Group-level deltas** — "3 new injection findings in `app/Http`, 8 resolved since your last
run." The table makes this a later query rather than a later migration, but the spec asks
only for score-level deltas, and the returning-customer flow it serves does not exist until
repeat paid runs do. Not built here.

---

## 7. Scoring, prompt, and report

### 7.1 `ScoreCalculator` v2

Dimension names are unchanged — five plus `overall` — but three inputs change:

| Dimension | v1 input | v2 input |
| --- | --- | --- |
| `duplication` | md5 line-hash heuristic | jscpd percentage |
| `security_hygiene` | 15 in-house regex counts | Gitleaks findings + Semgrep rules tagged `dimension: security_hygiene` |
| `structure` | average LOC + size buckets | scc complexity + size distribution + Semgrep rules tagged `dimension: structure` |
| `testing` | test ratio + CI | unchanged |
| `dependencies` | OSV + lockfile presence | unchanged |

Semgrep findings route to a dimension by their rule's `dimension` metadata (§5.3), so there
is no second mapping to keep in sync when a rule is added.

`scoring_version` becomes 2.

### 7.2 A failed scanner must not look like a clean repository (D11.5)

Semgrep times out, contributes zero findings, and `security_hygiene` scores 100. That is the
worst failure mode available to this system: it charges $49 to tell a customer their code is
clean when nothing looked at it. "A failed scanner never fails the run" (§5.1) is correct,
but it must not mean "a failed scanner silently improves the score."

Therefore each dimension records which scanners fed it and whether all of them ran. A
dimension missing a contributor is marked **not measured** rather than scored, is excluded
from `overall` with the remaining weights renormalized, and is rendered as not-measured in
plain language on the report. Never a number the run did not earn.

This falls out cleanly for the free tier: `diagnostic` does not run jscpd or Semgrep by
design, so its duplication and security dimensions render as "not measured at this tier" —
an honest upsell rather than a contrived one.

`ScoreCalculator::calculate()` therefore takes `($metrics, $groups, $scannerRuns)` and
returns a `ScoreSet` carrying `scores`, `not_measured[]`, and `scoring_version`.

### 7.3 The prompt template is admin-overridable and stored in the database

`PromptComposer::template()` reads an override from `ConfigService`, and
`templateIsValid()` currently requires only `{metrics}` and `{excerpts}`.

Adding `{groups}` means any override already saved in production keeps validating, keeps
being used, and silently produces prompts containing **no findings at all** — the entire
scanner platform invisible, with no error anywhere.

Therefore: `templateIsValid()` requires `{groups}`; and a stored override missing `{groups}`
is not used — the composer falls back to the default template and raises an admin notice.

While that file is open, the §18.2 defect is folded in: `compose()` and `preview()` duplicate
the admin-context append block verbatim.

The system prompt is reworked to instruct narration of each group — what it is, what it
affects, and what fixing it buys the client. One lint error must never become one report
item; grouping is also the prompt-size cost control.

### 7.4 `ReportPayload` v2

Adds `groups[]`, each carrying `rule_family`, `directory`, `severity`, `count`, and the
model's narration as `{what, affects, benefit}` — the three things F5.12.2 requires it to
say. `risks` and `fix_first_plan` are retained unchanged.

The validator **dispatches on `payload_schema_version` and retains v1 validation**.
`AuditReportController` renders stored payloads on every historical report view, so dropping
v1 validation would break every report already delivered.

### 7.5 Version-scoped comparison (Q14 / Q15)

`audit_reports` gains `scoring_version` and `payload_schema_version`, both backfilled to 1.

- `AuditDeltaService` compares only against the most recent prior report sharing the current
  `scoring_version`, returning null when there is none — no trend rather than a false trend.
- `AuditBenchmarkService` pools only current-version scores, and its cache key includes the
  version. The percentile stays hidden until `benchmark_min_sample` (20) v2 reports exist.

Both go dark across the boundary. That is the accepted cost of never showing a number the
spec says is not comparable (§18.2 T4).

**Rejected:** recomputing historical scores under the v2 formula. `audit_requests.metrics` is
persisted so it looks feasible, but historical metrics contain no scanner signal, so v2
formulas would score every historical run as clean on dimensions that were never measured —
worse than not comparing.

### 7.6 Templates

`resources/views/reports/audit.blade.php` (PDF) and `reports/audit-web.blade.php` both gain:

- the grouped narration section, ordered by group score;
- not-measured markers per §7.2;
- for `diagnostic`, top-2-groups-then-locked rendering carrying the conversion to $49.

---

## 8. Catalog, entitlements, and telemetry

### 8.1 Catalog rebuild (F5.12.5)

Every price and allowance figure lives in **one** new file, `config/pricing.php`. Both
consumers read it and neither hardcodes a figure: `AuditMonetizationSeeder` seeds products
and plans from it, and the export command in §8.5 generates the marketing data file from it.
Without this, the seeder and the marketing surface become two sources of truth for the same
number, which is exactly what A15 forbids.

`AuditMonetizationSeeder`, idempotent per F5.4.9:

```
RETIRE — deactivate, never delete
  audit-report-unlock              $5             is_active=false, is_visible=false
  audit-starter / growth / scale   $10/$30/$60    existing subscribers keep their plan

ADD — one-time
  audit-automated   $49     tier = automated
  audit-deep-ai     $199    tier = deep_ai
  audit-expert      $999    tier = expert     (from $999)

ADD — subscriptions
  Starter     $59      metadata: audit_analyses_per_month, audit_deep_ai_credits
  Growth      $149
  Agency      $499
  Enterprise  $1,500   (from)
```

Deactivating rather than deleting is load-bearing twice: the unlock product row still backs
already-unlocked reports, and a plan row with active subscriptions cannot be removed without
orphaning them.

### 8.2 Q32 — the legacy $5 unlock

Retired, existing unlocks grandfathered. With `diagnostic` computing genuinely less, "$5
unlocks the full report" is no longer coherent — there is no hidden Semgrep finding to
reveal, because none was computed.

- **Removed:** the product from the active catalog; new $5 checkouts; the `$5` string at
  `frontend/src/pages/index.astro:836`.
- **Kept:** `unlocked_at`, `unlock_order_id`, and the read path that honours them;
  `HandleAuditUnlockOrder` for in-flight orders; `config('audit.unlock_product_slug')`,
  marked deprecated.
- **Conversion path:** locked diagnostic → $49 automated.

### 8.3 Entitlements

`AuditEntitlementService::subscriptionAllowance()` already reads `audit_analyses_per_month`
from plan metadata. It gains tier awareness — subscription allowances meter `automated`-tier
runs, and Deep AI credits are metered separately from `audit_deep_ai_credits`. The free-run
quota and `audit_bonus_free_runs` bonus continue to govern `diagnostic` runs unchanged.

### 8.4 Cost telemetry (F5.12.6)

Discrete columns on `audit_requests` — `ai_input_tokens`, `ai_output_tokens`, `scanner_ms`,
`repo_size_kb` — rather than JSON, so cost per audit per tier is a `GROUP BY tier` rather
than a scan-and-decode. Per-tool timings already live in `scanner_runs`; `scanner_ms` is the
denormalized total.

Token counts come from the `AnalysisResult` returned by the new `AiAnalyzer` signature
(§3.3), sourced from `$message->usage`, which is currently discarded.

**No new admin widget in this phase.** The columns are queryable and Q5 needs the numbers,
not a dashboard; the operations dashboard is Phase 9B's §15.7.

### 8.5 Marketing pricing sync (F5.7.6 / A15, D11.7)

A15 requires every monetary and quota figure shown anywhere to match backend configuration
exactly. `CLAUDE.md` states the two apps do not import from each other and are built and
deployed separately, so this must be satisfied without coupling the builds.

Backend stays authoritative. `config/pricing.php` (§8.1) is the single source; an artisan
command exports it to a committed `frontend/src/data/pricing.json`; the frontend reads the
committed data file; CI regenerates and diffs to catch drift. No cross-app code import and
no build-time dependency on a running backend.

The chain is therefore `config/pricing.php` → seeder → Stripe products, and
`config/pricing.php` → export → marketing copy. One edit changes both.

**Rejected:** a build-time fetch from a public pricing endpoint. Stronger guarantee, but it
makes the static site build fail whenever the backend is down.

---

## 9. Operations

Phase 9A-1 landed on 2026-08-02 (`c47f2f3`..`82a673b`), so this phase integrates with it
rather than inventing parallel machinery.

### 9.1 Smoke gate

`app:smoke` gains an assertion that every scanner in the `automated` tier profile is
available and reports its pinned version. Without it, provisioning drift after a deploy is
invisible: every run degrades, reports quietly thin out, and the exit code stays 0.

### 9.2 Health check

A new `ScannerDegradationCheck` under `app/Health/Checks/`, alongside the three existing
custom checks, banded per the `flexpick` block of `config/health.php`.

Trap §7.2's not-measured marking is visible to the *customer* but not to the *operator*.
Because a failed scanner never fails the run, telemetry is the only signal that thin reports
are being sold. The check reads `scanner_runs` and fails when the fraction of recent runs
with a non-`ok` outcome for a tier-required scanner exceeds the configured threshold.

### 9.3 Error tracking

Failure reasons are classified strings (§5.4), never captured tool output, so raw Semgrep
stderr cannot reach Bugsink through the pipeline log. This complements the mandatory
`TokenScrubber` rather than relying on it.

---

## 10. Error handling

| Condition | Behaviour |
| --- | --- |
| Any scanner fails, times out, or is unavailable | Zero findings, classified outcome in `scanner_runs`, pipeline log entry, **run continues**. Dimensions it fed become not-measured. |
| **scc** fails | Same, plus `SccInventory` falls back to the existing Symfony `Finder` walk, so budgets and excerpts retain a basis. |
| All scanners fail | A report is still produced; every scanner-fed dimension is not-measured and `overall` renormalizes over what remains. |
| A collector throws | The run fails. This is a defect, not a degradation. |
| Clone or AI failure | Existing `AuditNotAnalyzableException` / `AiAnalysisException` → `markNeedsFollowup()` paths, unchanged. |
| Legacy `AuditRequest` with no tier | Column default plus backfill to `diagnostic`; every historical row resolves a profile. |
| Stored prompt override missing `{groups}` | Default template used; admin notice raised. |
| Historical v1 payload | Validates and renders under retained v1 rules. |

---

## 11. Testing

PHPUnit (`php artisan make:test --phpunit`), per `CLAUDE.md` — the Pest snippets in
`backend/AGENTS.md` must be translated before use.

Scanner tests run against **committed fixture outputs** — real SARIF and JSON samples — so
CI never requires the binaries to be installed. A thin layer of smoke tests exercises the
real binaries and skips when one is unavailable.

Beyond per-normalizer and per-collector coverage, six tests each pin a decision made above:

| # | Test | Pins |
| --- | --- | --- |
| 1 | Grouper over shuffled input twice ⇒ byte-identical output | §6.3 determinism |
| 2 | Planted fake credential appears in neither the persisted group nor the composed prompt | F5.2.6 / §5.4 |
| 3 | Semgrep unavailable ⇒ `security_hygiene` not-measured, `overall` renormalized, never 100 | §7.2 |
| 4 | Stored prompt override lacking `{groups}` ⇒ default used, notice raised | §7.3 |
| 5 | Seeder run twice ⇒ no duplicates, unlock deactivated not deleted, subscribed plans intact | F5.4.9 / §8.1 |
| 6 | A v1 payload validates and renders | §7.4 |

Plus: repo-supplied `.semgrepignore` / `.jscpd.json` does not alter results (§5.4); a
scanner timeout produces a classified reason containing no tool output (§5.4); deltas and
benchmarks return nothing across a version boundary (§7.5); every shipped Semgrep rule
declares both `family` and `dimension` metadata (§5.3); the exported
`frontend/src/data/pricing.json` matches `config/pricing.php` (§8.5), which is the CI drift
check itself; and a completed tier-product order produces an `AuditRequest` at the purchased
tier (§4.2).

---

## 12. Risks and coupling

Phase 11 was kept as one cycle rather than decomposed. The risks that follow from that, and
from the design itself:

1. **Single review gate over six subsystems.** Scanner harness, findings model, analysis
   rework, tiering, telemetry, and catalog land together. The catalog work (§8) has no
   dependency on the pipeline work (§5–§7) and could be reviewed and merged independently if
   the implementation plan sequences it first.
2. **Formula versioning must precede formula change.** §7.5 is cheap now and expensive after
   §7.1 ships. The implementation plan must order versioning before the `ScoreCalculator`
   rewrite, or historical deltas and benchmarks are corrupted in the window between them.
3. **Trends and percentiles go dark at launch.** Accepted per §7.5, but it is customer-visible
   and should not be a surprise to support.
4. **The free report gets thinner.** Accepted per §4.1, and it is a funnel change, not only a
   technical one. Conversion should be watched after launch.
5. **Provisioning is a new operational surface.** Four pinned binaries on the host, one of
   which needs Python. §9.1's smoke assertion is what keeps drift from being silent.
6. **`deploy.php` is still boilerplate.** `provision:scanners` is added to a file whose host,
   repository, and domain are all placeholders. Phase 9A's deployment automation (Q22, PR8)
   is not yet complete, so the provisioning task cannot be rehearsed end to end until it is.

---

## 13. Exit criteria

**Milestone M7 — first paid tier-1 report sold at the new price, with measured cost per audit
behind it.**

- [ ] `tier` on `AuditRequest`, closed enumeration, all budgets configuration-driven
- [ ] Five scanners provisioned, version-pinned, asserted by `app:smoke`
- [ ] In-house Semgrep ruleset shipping; no third-party rules (Q33 resolved)
- [ ] Findings normalized, deduplicated, and grouped; groups persisted; grouping deterministic
- [ ] No secret value reachable in any persisted row or composed prompt
- [ ] Scores versioned; deltas and benchmarks version-scoped
- [ ] Payload schema versioned; v1 payloads still render
- [ ] Not-measured dimensions never scored, never counted toward `overall`
- [ ] Cost telemetry recorded per run and aggregable per tier
- [ ] Catalog rebuilt idempotently; $5 unlock retired with existing unlocks intact
- [ ] A completed tier-product order runs the repository at the purchased tier
- [ ] Marketing pricing figures generated from backend configuration, drift-checked in CI
- [ ] Suite green; `vendor/bin/pint --dirty`; `vendor/bin/phpstan analyse` with no new error category
