# Audit precision: facts, secrets, generated code, silent scanner failures

**Date:** 2026-10-05
**Status:** Draft, awaiting review
**Scope:** Spec 1 of 2. Spec 2 (deep-review finding verification) is separate and follows this one.

## Problem

The 2026-09-25 report on `github.com/skaile-ai/platform` (report `01a0d9e0-…`) was answered by the
customer with a point-by-point rebuttal (`2026-09-25-code-audit-response.html`). Of the deterministic
claims, most did not hold:

| Report claim | Customer verdict | Root cause |
|---|---|---|
| CI configured: no / test ratio 0% / potential secrets 0 | Wrong facts | View bug: `audit-web.blade.php:185-187`, `audit.blade.php:127` read `$metrics['has_ci']`, `$metrics['test_ratio_pct']`, `$metrics['secret_findings']`; the first two live under `$metrics['tooling']`, the third is written nowhere. |
| No dependency lockfile | False positive | `ManifestCollector` knows only `composer.lock` / `package-lock.json`, root only. Repo uses `bun.lock` with workspaces. |
| 131 critical credentials, security hygiene 0 | 0 of 131 production credentials | `resources/scanners/gitleaks.toml` is `useDefault = true` only; every hit is CRITICAL; score subtracts 15 per hit. Hits were lockfile checksums (`postxl-lock.json`), test fixtures, public samples, minified bundles, `.example` files. One real low-privilege key was buried in the noise. |
| Structure 0 | Partly valid | scc's inventory pass runs without `--no-gen --no-min-gen`, so generated Prisma clients and `storybook-static/` bundles drive LOC, largest files, and the structure formula. Markdown/JSON lines are counted as "lines of code". |
| No error monitoring | Partly valid | Only root manifests are checked. |

Two further **silent false negatives** surfaced while tracing:

- `JscpdScanner::scan()` returns `[]` — a successful run — when jscpd writes no report. A crashed scan
  scores duplication 100. (Platform: 2.2M LOC, reported 0% duplication.)
- `DependencyAuditor` parses only `composer.lock` / `package-lock.json`; on a bun/yarn/pnpm repo it scans
  0 packages and reports no vulnerabilities. `OsvScanner::normalize()` also returns `[]` on
  `osv_unreachable`. Both score dependencies as clean when nothing was checked.

## Goals

1. Repository facts in the report are correct and consistent with the scores.
2. Secret findings distinguish likely-real credentials from fixtures and noise, without ever suppressing
   a provider-specific credential found in source.
3. Generated, vendored, and build-output files do not drive structure, size, duplication, or hotspots.
4. A scan that failed or covered nothing is reported as **not measured**, never as a perfect score.
5. Scores stay deterministic (same repo → same scores).

## Non-goals

- Verifying deep-review (LLM) findings — spec 2.
- Honouring repo-supplied `.gitleaks.toml`, `.jscpd.json`, or Semgrep config. Spec §5.4 stands: repo
  config never steers the analyzer. The single exception below (`.gitattributes` `linguist-*`) only
  classifies files for structure metrics and never affects secret findings.
- Full-history secret scanning. We continue to scan HEAD.
- LLM triage of scanner findings.

## Design

### 1. `PathClassifier`

New `App\Services\AuditReport\PathClassifier`, built once per run and stored on `RepoContext`
(`withClassifier()` / `$context->classifier`). Pure function of path + a small set of per-run inputs.

```php
enum PathClass: string {
    case Source = 'source';
    case Test = 'test';
    case Docs = 'docs';
    case Example = 'example';
    case Generated = 'generated';
    case Vendored = 'vendored';
    case Lockfile = 'lockfile';
}

public function classify(string $relativePath): PathClass;
```

Precedence (first match wins):

1. **Lockfile** — basename in `composer.lock`, `package-lock.json`, `npm-shrinkwrap.json`, `yarn.lock`,
   `pnpm-lock.yaml`, `bun.lock`, `bun.lockb`, `Cargo.lock`, `Gemfile.lock`, `poetry.lock`, `go.sum`,
   or matches `*-lock.json` / `*.lock.json` / `*-lock.yaml` / `*.lock.yaml` (covers `postxl-lock.json`,
   `skaile.lock.yaml`, `skills-lock.json`).
2. **Vendored** — any segment in `vendor`, `node_modules`, `bower_components`, `third_party`; or
   `.gitattributes` `linguist-vendored`.
3. **Generated** — any of:
   - path segment in `dist`, `build`, `out`, `.next`, `coverage`, `storybook-static`, `__generated__`,
     `generated` (as a directory name);
   - basename matches `*.min.js`, `*.min.css`, `*.bundle.js`, `*.map`, `*.pb.go`, `*_pb2.py`, `*.g.dart`;
   - in the set of files scc flagged as generated/minified (see §3);
   - `.gitattributes` `linguist-generated` (true / set).
4. **Example** — basename matches `*.example`, `*.sample`, `*.dist`, `.env.example`, `.env.sample`,
   `.env.template`, or contains `.example.`.
5. **Test** — segment in `test`, `tests`, `spec`, `__tests__`, `__mocks__`, `fixtures`, `testdata`,
   `e2e`, or basename matches `*Test.*`, `*.test.*`, `*.spec.*`, `*_test.*`, `test_*.py`.
   (Supersedes the two regexes currently inline in `ToolingCollector`.)
6. **Docs** — extension `md`, `mdx`, `rst`, `adoc`, `txt`; or first segment in `docs`, `doc`,
   `_devlog`, `_concept`, `examples`.
7. **Source** — everything else.

`.gitattributes` is read from the repo root only, patterns matched with `fnmatch` using git's
semantics for the common cases (`dir/**`, `*.ext`, `path/file`). Only `linguist-generated` and
`linguist-vendored` are honoured. Classification never decides whether a secret finding exists. For secret severity (§4), the
classifier is called with `ignoreRepoAttributes: true`, so `.gitattributes` cannot lower a secret's
severity: only path conventions and scc's own detection count there.

Unit-tested with a table of paths drawn from the platform repo.

### 2. Report facts

- `audit-web.blade.php` and `audit.blade.php` read `tooling.has_ci`, `tooling.test_ratio_pct`.
- "Potential secrets" shows the sum of secret findings at severity **critical + high** (the "likely
  real" set), with the low count beside it: `2 (+129 likely fixtures)`. Source: the persisted
  `AuditFindingGroup` rows with `rule_family` starting `secrets.`, passed into the view by
  `ReportPresenter`.
- "Lines of code" counts code lines from languages scc treats as code, excluding generated, vendored,
  and docs/markup languages (Markdown, JSON, YAML, plain text). Excluded totals appear as a secondary
  fact: "N generated/vendored files excluded".
- New feature test renders both report views from metrics produced by the real collectors on a fixture
  repo and asserts each fact against the collector output. This is the guard against key drift.

### 3. scc inventory and generated files

- The `SccScanner` inventory pass keeps running without `--no-gen --no-min-gen`, because those flags
  would drop the files silently and we want to report them. The billing pass already runs with them;
  it switches to `--by-file` (still summing code lines for billing). Files in the inventory but absent
  from the billing pass are the ones scc detected as generated or minified, and that set feeds
  `PathClassifier` (rule 3). No extra scc invocation.
- `SccInventory` gains `excluded: list<{path, loc, class}>`; `files` keeps only `Source`, `Test`,
  `Example` classes. `totalLoc` / `totalComplexity` are summed over the kept files, code-language only.
- `MetricsCollector::largest_files`, `HotspotCollector`, `RiskFileSelector`, and `ExcerptCollector`
  consume the filtered inventory, so generated files no longer reach structure, hotspots, deep review,
  or the AI prompt.
- `ToolingCollector` test counting uses `PathClassifier` (`Test`) over the filtered inventory.
- Committed build output still gets **one** informational finding per top-level generated directory
  (`structure.committed-build-output`, severity `low`, dimension `structure`, counts toward
  `ruleFindings` in the structure formula as 1, not per file). Generated source that is committed by
  design (e.g. ORM clients) is excluded silently; the "excluded" fact lists it.
- jscpd: the `ignore` list in `resources/scanners/jscpd.json` gains `**/storybook-static/**`,
  `**/out/**`, `**/.next/**`, `**/*.min.css`, `**/*.bundle.js`, `**/__generated__/**`; additionally
  `JscpdScanner` passes `--ignore` with the per-run generated/vendored paths from the classifier
  (capped at 500 globs; directories collapsed to `dir/**`). Duplicated blocks whose every occurrence
  is in a non-`Source` class are dropped in normalization.

### 4. Secrets

**Gitleaks config** (`resources/scanners/gitleaks.toml`, still the only config ever passed):

```toml
[extend]
useDefault = true

[[allowlists]]
description = "Lockfiles, minified and build output"
paths = [
  '''(^|/)(composer\.lock|package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|Cargo\.lock|Gemfile\.lock|poetry\.lock|go\.sum)$''',
  '''(^|/)[^/]*[-.]lock\.(json|ya?ml)$''',
  '''\.min\.(js|css)$''', '''\.map$''',
  '''(^|/)(storybook-static|node_modules|vendor|dist|coverage)/''',
]

[[allowlists]]
description = "Public, well-known sample values"
regexTarget = "secret"
regexes = [ '''<jwt.io sample JWT>''', '''^(0123456789abcdef|fedcba9876543210)+$''' ]
stopwords = ["example", "placeholder", "changeme", "dummy", "your_", "xxxxxxxx"]
```

The exact sample-value list is assembled from the platform repo's fixture hits during implementation
and pinned by tests. Bump `audit.scanners.gitleaks.version` usage check: the binary must be ≥ 8.25
(`[[allowlists]]` syntax); `GitleaksScanner::isAvailable()` stays as-is, a version assertion is added
to `app:smoke`.

**Severity matrix** (`GitleaksScanner::normalize()` uses `PathClassifier`; rule specificity is a fixed
list of gitleaks rule ids — everything not on it is "generic"):

| Path class | Provider-specific rule (aws-*, github-*, gitlab-*, stripe-*, slack-*, anthropic-*, openai-*, gcp-*, private-key, …) | Generic rule (`generic-api-key`, `jwt`, …) |
|---|---|---|
| Source, CI (`Jenkinsfile*`, workflow files), Docker/compose | critical | high |
| Generated, Vendored | critical | low |
| Example, Test, Docs | high | low |

Rule family splits so groups render separately: `secrets.credential` (critical/high) and
`secrets.likely-fixture` (low). The low group's copy says "credential-shaped values in tests, docs or
examples — likely fixtures; confirm and allowlist".

`AuditPipeline::secretPaths()` is unchanged: **every** gitleaks hit, whatever its severity, still
withholds that file's content from the model (Q17).

**Score** (`ScoreCalculator::securityHygiene`):

```
critical: 35 for the first finding, +10 each further, cap 80
high:     10 each, cap 40
low:      1 each, cap 10
SAST:     unchanged (min(20, 2 × count) per group)
```

One real key in source → 65. The platform repo's expected outcome (one live key, rest fixtures/noise)
lands roughly 55–75 instead of 0. `ScoreCalculator::VERSION` → 3.

### 5. Manifests, lockfiles, dependencies

New `App\Services\AuditReport\Collectors\WorkspaceDiscovery` returns the list of package roots:

- the repo root;
- npm/yarn/bun `workspaces` (array or `{packages: []}`) and `pnpm-workspace.yaml` `packages` globs,
  expanded against the filesystem;
- composer `repositories` of type `path`;
- fallback: any `package.json` / `composer.json` up to depth 3 outside `Vendored`/`Generated` paths,
  capped at 50 roots.

`ManifestCollector` emits one entry per root, keyed by relative path (`package.json`,
`frontend/package.json`, …). `lockfile` is true if the root **or any ancestor workspace root** has a
recognised lockfile for that ecosystem:

- npm ecosystem: `package-lock.json`, `npm-shrinkwrap.json`, `yarn.lock`, `pnpm-lock.yaml`,
  `bun.lock`, `bun.lockb`;
- composer: `composer.lock`.

A new `lockfile_kind` field records which. `ScoreCalculator::dependencies` deducts 20 once per
**workspace root without coverage**, not per package (a monorepo missing one lockfile loses 20, not
20 × packages).

`ToolingCollector` merges dependency names across all discovered manifests (error monitoring, linter,
static analysis, formatter). `has_ci` adds `Jenkinsfile*`, `.circleci/config.yml`,
`azure-pipelines.yml`, `.buildkite/`, `.drone.yml`, `.travis.yml`; a new `ci_systems` list records
which. Error-monitoring list adds `@sentry/bun`, `@sentry/nestjs`, `@sentry/sveltekit`,
`@sentry/astro`, `dd-trace`, `newrelic`, `@opentelemetry/sdk-node`, `posthog-node` (error capture) —
reported as detected, not as an endorsement.

`DependencyAuditor` gains parsers for `yarn.lock` (v1 and berry), `pnpm-lock.yaml`, and text `bun.lock`
(JSONC). `bun.lockb` is binary and unscannable: recorded as `unscannable_lockfiles`. Package lists are
deduplicated by (ecosystem, name, version) across workspaces.

### 6. Silent failures become "not measured"

`ScannerRunner` already marks a scanner not-run when `scan()` throws; scores already drop not-run
dimensions and the views already render `not_measured`. So each silent path becomes a throw with a
classified reason:

- `JscpdScanner`: no report file → `RuntimeException('jscpd produced no report')`.
- `OsvScanner`: `error` present → `RuntimeException('osv_unreachable')`.
- `OsvScanner`: `packages_scanned === 0` while `ManifestCollector` found ≥ 1 manifest with
  dependencies → `RuntimeException('no scannable lockfile')`. Needs manifest data at scan time: the
  scanner calls `WorkspaceDiscovery` itself rather than depending on collector order.
- `SccScanner`: empty or non-array JSON → throw (today it builds an empty inventory).

The report's "not measured" note states the reason in customer language ("Dependency vulnerabilities
were not checked: this repository's lockfile format (bun.lockb) can't be read"). Reasons come from a
fixed map, never raw tool output (spec §5.4).

### 7. Narrative prompt

`PromptComposer` gets the excluded-files summary, `lockfile_kind`, `ci_systems`, and the
critical/high vs. low secret split, and one instruction: "Low-severity secret groups are likely test
fixtures. Do not describe them as leaked credentials." No other prompt changes in this spec.

## Data compatibility

- New metrics keys are additive. Old reports render with the old keys; the views fall back
  (`$metrics['tooling']['has_ci'] ?? $metrics['has_ci'] ?? false`) so historical reports also get the
  facts bug fixed.
- `VERSION = 3`: deltas and benchmarks already compare only within a version, so the jump in security
  and structure scores won't show as fake improvements.

## Testing

- `PathClassifierTest`: a table of ~60 paths covering every class and precedence edge.
- `GitleaksScannerTest`: severity matrix from SARIF fixtures; allowlist behaviour against the real
  gitleaks binary on a small fixture tree (skipped when the binary is absent, like the scc test).
- `ManifestCollectorTest` / `WorkspaceDiscoveryTest`: bun workspaces, pnpm, yarn, nested composer.
- `DependencyAuditorTest`: each new lockfile parser against checked-in sample lockfiles.
- `ScoreCalculatorTest`: new secret curve; dependencies-per-workspace deduction.
- Scanner failure tests: jscpd no report, OSV unreachable, OSV zero packages, scc empty → dimension
  in `not_measured`.
- Report view test (§2).
- **Regression fixture** `tests/Fixtures/repos/monorepo-precision/`, built by a test helper into a
  temp git repo like the existing fixture repo: bun workspaces + `bun.lock`, `postxl-lock.json` with
  hex checksums, test files with fake keys and the jwt.io sample, a Prisma-style `// Do not edit`
  file over 1,000 lines, `storybook-static/` with a minified bundle, a `Jenkinsfile` with an OAuth
  client id and `$GIT_TOKEN`, `.github/workflows/ci.yml`, and one real-shaped provider key in source.
  Pipeline-level assertions: CI yes, lockfile present, test ratio > 0, exactly one critical secret,
  fixture group low, generated files absent from `largest_files`, no structure penalty from them.
- Manual acceptance: run the pipeline on `/var/www/html/platform-main` and compare it with the
  customer response. Expected: CI yes, test ratio ≈ 20%, lockfile present, the GIF-search key in
  `backend/libs` critical or high, `postxl-lock.json` absent, Storybook reported as committed build
  output, Prisma clients excluded, security hygiene well above 0.

## Rollout

Own branch off `growth-retention`, one PR. There is no feature flag: the version bump
already isolates score comparisons. After deploy, offer the platform customer a free re-run.
