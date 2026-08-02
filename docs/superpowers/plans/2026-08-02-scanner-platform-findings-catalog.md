# Phase 11 — Scanner Platform, Findings Model, and Catalog Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the in-house heuristics in the audit pipeline with five real static analyzers, group their output into narrated problem families, and rebuild the catalog around tiered products — delivering the sellable $49 Automated Health Report (Milestone M7).

**Architecture:** Two new namespaces under `app/Services/AuditReport/` with deliberately different failure contracts — `Scanners/` (external binaries; failure is normal and absorbed) and `Collectors/` (internal PHP; failure is a bug and fails the run). Scanner output normalizes into a `Finding` value object, deduplicates on a fingerprint, and groups into `FindingGroup` families persisted to `audit_finding_groups`. A `TierProfile` resolved from config drives which scanners run and what the budgets are.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, PHPUnit 11, Larastan 3, Pint. Scanners: scc, Gitleaks, jscpd, Semgrep CE (all host-provisioned, version-pinned), plus the existing OSV querybatch integration.

**Spec:** `docs/superpowers/specs/2026-08-02-scanner-platform-findings-catalog-design.md`

## Global Constraints

- **PHP 8.4, Laravel 13, Filament 5, Livewire 4, Larastan 3** — `backend/composer.json` is the source of truth. Prose in `backend/AGENTS.md` referencing older versions is stale.
- **All commands run from `backend/`.** There is no root-level task runner.
- **Tests are PHPUnit**, not Pest. Create with `php artisan make:test --phpunit {name}`. Tests extend `Tests\Feature\FeatureTest`. Pest snippets in `backend/AGENTS.md` must be translated before use.
- **`php artisan test` exits with code 1 in this project even when every test passes.** This is pre-existing, project-wide, and unrelated to your work. Judge success by the printed `OK` / failure count, never by the exit code.
- **Run `vendor/bin/pint --dirty --format agent` before every commit.**
- **`vendor/bin/phpstan analyse` must introduce no new error category.** Level 3, ~416 accepted errors, no baseline file.
- **No repository code is ever executed** (§4.3). No dependency installation, no build step, no hooks. All five tools are static analyzers.
- **Secret values must never be persisted or sent to the model** (F5.2.6) — counts and paths only.
- **Every per-tier budget is configuration-driven, never hardcoded** (Appendix A single-source rule).
- **Grouping must be deterministic**: same repo + same scanner versions ⇒ byte-identical groups. Every sort is total with an explicit tie-break; never depend on PHP hash-map iteration order.
- **Seeders must be idempotent** (F5.4.9) — running twice creates no duplicates.
- **User-facing strings pass through the translation layer** (F5.11.3).
- **Never delete a catalog row that backs a purchase.** Deactivate instead.

## File Structure

```
app/Constants/
  AuditTier.php                          NEW  closed tier enumeration

app/Services/AuditReport/
  Tiers/
    TierProfile.php                      NEW  resolved per-tier budgets
    TierProfileResolver.php              NEW  config → TierProfile
  Findings/
    Severity.php                         NEW  normalized severity + weight
    Finding.php                          NEW  one normalized finding
    DedupedFinding.php                   NEW  merged finding + contributing tools
    FindingGroup.php                     NEW  a problem family (value object)
    FindingDeduplicator.php              NEW  merge on fingerprint
    FindingGrouper.php                   NEW  family × directory, ranked
    Normalizers/
      SarifNormalizer.php                NEW  shared base for Gitleaks + Semgrep
      (scc, jscpd, and OSV normalize inline in their scanner classes —
       each is a single tool-specific mapping with no shared structure,
       so a separate class per tool would be indirection without reuse)
  Scanners/
    Scanner.php                          NEW  interface
    ScannerRunner.php                    NEW  timeout + absorb + classify
    ScannerRun.php                       NEW  per-scanner provenance
    ScannerOutcome.php                   NEW  ok|failed|timeout|unavailable
    ScannerSuiteResult.php               NEW  findings + runs
    RepoContext.php                      NEW  path + tier + inventory
    SccInventory.php                     NEW  file inventory from scc
    SccScanner.php                       NEW
    GitleaksScanner.php                  NEW
    OsvScanner.php                       NEW  wraps existing DependencyAuditor
    JscpdScanner.php                     NEW
    SemgrepScanner.php                   NEW
  Collectors/
    Collector.php                        NEW  interface
    GitFactsCollector.php                NEW  extracted from MetricsCollector
    ManifestCollector.php                NEW  extracted
    ToolingCollector.php                 NEW  extracted
    HotspotCollector.php                 NEW  extracted
    ExcerptCollector.php                 NEW  extracted
  MetricsCollector.php                   MODIFY  → composer over Collectors/
  ScoreCalculator.php                    MODIFY  → v2, consumes groups
  ScoreSet.php                           NEW  scores + not_measured + version
  PromptComposer.php                     MODIFY  → {groups}, override guard
  ReportPayload.php                      MODIFY  → versioned validator
  AiAnalyzer.php                         MODIFY  → returns AnalysisResult
  AnalysisResult.php                     NEW  payload + token counts
  ClaudeAnalyzer.php                     MODIFY  → captures usage
  AuditPipeline.php                      MODIFY  → orchestrates the above
  AuditDeltaService.php                  MODIFY  → version-scoped
  AuditBenchmarkService.php              MODIFY  → version-scoped
  AuditEntitlementService.php            MODIFY  → tier-aware metering

app/Models/
  AuditRequest.php                       MODIFY  tier, scanner_runs, cost columns
  AuditReport.php                        MODIFY  version columns
  AuditFindingGroup.php                  NEW

app/Health/Checks/
  ScannerDegradationCheck.php            NEW

app/Listeners/Order/
  HandleAuditTierOrder.php               NEW  purchase → tiered run

app/Console/Commands/
  ExportPricingCommand.php               NEW
  SmokeCommand.php                       MODIFY  scanner availability assertion

config/
  audit.php                              MODIFY  tiers, scanners, findings blocks
  pricing.php                            NEW  single source for money figures
  health.php                             MODIFY  scanner_degradation band

resources/semgrep/flexpick/              NEW  in-house ruleset
resources/views/reports/audit.blade.php      MODIFY  grouped narration
resources/views/reports/audit-web.blade.php  MODIFY  grouped narration

database/migrations/                     NEW  four migrations (Tasks 1, 2, 7, 22)
database/seeders/AuditMonetizationSeeder.php  MODIFY  rebuild from config/pricing

deploy.php                               MODIFY  provision:scanners
docker/8.4/Dockerfile                    MODIFY  same pins
frontend/src/data/pricing.json           NEW  generated, committed
frontend/src/pages/index.astro           MODIFY  read pricing, drop hardcoded $5
```

## Task Sequence

Ordering is load-bearing in two places:

1. **Task 2 (versioning) precedes Task 17 (`ScoreCalculator` v2).** Spec §12.2 — versioning is cheap before the formulas change and corrupts historical deltas if it lands after.
2. **Tasks 4–7 (findings model) precede Tasks 8–14 (scanners).** The findings model is pure and testable with hand-built fixtures; building it first means every scanner task has a finished target to normalize into.

```
Foundations   1 ─ 2 ─ 3
Findings      4 ─ 5 ─ 6 ─ 7
Scanners      8 ─ 9 ─ 10 ─ 11 ─ 12 ─ 13 ─ 14
Collectors    15 ─ 16
Analysis      17 ─ 18 ─ 19 ─ 20 ─ 21 ─ 22
Ops           23 ─ 24
Catalog       25 ─ 26 ─ 27 ─ 28
```

---

### Task 1: Tier attribute

**Files:**
- Create: `app/Constants/AuditTier.php`
- Create: `database/migrations/2026_08_02_000001_add_tier_to_audit_requests_table.php`
- Modify: `app/Models/AuditRequest.php:17-21` (`$fillable`)
- Test: `tests/Feature/Models/AuditTierTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Constants\AuditTier` enum with cases `DIAGNOSTIC = 'diagnostic'`, `AUTOMATED = 'automated'`, `DEEP_AI = 'deep_ai'`, `EXPERT = 'expert'`. `AuditRequest::$tier` is a string column cast to `AuditTier`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/AuditTierTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditTierTest extends FeatureTest
{
    public function test_new_requests_default_to_the_diagnostic_tier(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->fresh()->tier);
    }

    public function test_tier_is_cast_to_the_enum(): void
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);

        $this->assertInstanceOf(AuditTier::class, $request->fresh()->tier);
        $this->assertSame('automated', $request->fresh()->tier->value);
    }

    public function test_enumeration_is_closed_to_the_four_known_tiers(): void
    {
        $this->assertSame(
            ['diagnostic', 'automated', 'deep_ai', 'expert'],
            array_column(AuditTier::cases(), 'value'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AuditTierTest`
Expected: FAIL — `Class "App\Constants\AuditTier" not found`.

- [ ] **Step 3: Create the enum**

Create `app/Constants/AuditTier.php`, matching the existing `AuditRequestStatus` pattern:

```php
<?php

namespace App\Constants;

enum AuditTier: string
{
    case DIAGNOSTIC = 'diagnostic';
    case AUTOMATED = 'automated';
    case DEEP_AI = 'deep_ai';
    case EXPERT = 'expert';
}
```

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_08_02_000001_add_tier_to_audit_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->string('tier')->default('diagnostic')->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn('tier');
        });
    }
};
```

The column default backfills every existing row to `diagnostic`, so no separate backfill statement is needed — spec §10, "every historical row resolves a profile."

- [ ] **Step 5: Register the attribute on the model**

In `app/Models/AuditRequest.php`, add `'tier'` to `$fillable` (after `'source'`) and add the cast:

```php
protected $casts = [
    // ... existing casts ...
    'tier' => AuditTier::class,
];
```

Add `use App\Constants\AuditTier;` to the imports.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=AuditTierTest`
Expected: PASS, 3 tests.

- [ ] **Step 7: Verify nothing else broke**

Run: `php artisan test --filter=AuditRequest`
Expected: PASS — existing request tests unaffected by an additive column.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Constants/AuditTier.php database/migrations/2026_08_02_000001_add_tier_to_audit_requests_table.php app/Models/AuditRequest.php tests/Feature/Models/AuditTierTest.php
git commit -m "feat(audit): add tier attribute to audit requests"
```

---

### Task 2: Score and payload versioning

**This task must land before Task 17.** It records which formula version produced each report and scopes deltas and benchmarks to a single version. Once Task 17 changes the formulas, historical comparisons are corrupted unless this is already in place (spec §7.5, §12.2).

**Files:**
- Create: `database/migrations/2026_08_02_000002_add_versions_to_audit_reports_table.php`
- Modify: `app/Models/AuditReport.php:14` (`$fillable`)
- Modify: `app/Services/AuditReport/AuditDeltaService.php:19-25`
- Modify: `app/Services/AuditReport/AuditBenchmarkService.php:12-23`
- Modify: `app/Services/AuditReport/ScoreCalculator.php` (add version constant only)
- Modify: `app/Services/AuditReport/ReportPayload.php` (add version constant only)
- Test: `tests/Feature/Services/AuditDeltaServiceTest.php` (existing — extend)
- Test: `tests/Feature/Services/AuditBenchmarkServiceTest.php` (existing — extend)

**Interfaces:**
- Consumes: nothing.
- Produces: `ScoreCalculator::VERSION` (int, currently `1`), `ReportPayload::VERSION` (int, currently `1`). `AuditReport::$scoring_version` and `AuditReport::$payload_schema_version`, both integers.

- [ ] **Step 1: Write the failing delta test**

Add to `tests/Feature/Services/AuditDeltaServiceTest.php`:

```php
public function test_does_not_compare_across_scoring_versions(): void
{
    $previous = $this->reportFor('https://github.com/acme/app', ['overall' => 40]);
    $previous->update(['scoring_version' => 1]);

    $current = $this->reportFor('https://github.com/acme/app', ['overall' => 90]);
    $current->update(['scoring_version' => 2]);

    $this->assertNull(app(AuditDeltaService::class)->deltasFor($current->fresh()));
}
```

Reuse whatever helper the existing tests in that file already use to build a report against a repo URL and email; if none exists, add a private `reportFor(string $repoUrl, array $scores): AuditReport` helper that creates an `AuditRequest` with a fixed email plus an `AuditReport` whose payload carries those scores.

- [ ] **Step 2: Write the failing benchmark test**

Add to `tests/Feature/Services/AuditBenchmarkServiceTest.php`:

```php
public function test_pools_only_reports_sharing_the_current_scoring_version(): void
{
    // 25 v1 reports would otherwise satisfy benchmark_min_sample and produce a percentile.
    AuditReport::factory()->count(25)->create([
        'payload' => ['scores' => ['overall' => 50]],
        'scoring_version' => 1,
    ]);

    Cache::flush();

    $this->assertNull(app(AuditBenchmarkService::class)->percentileFor(80, scoringVersion: 2));
}
```

- [ ] **Step 3: Run both to verify they fail**

Run: `php artisan test --filter=AuditDeltaServiceTest`
Expected: FAIL — `Unknown column 'scoring_version'`.

Run: `php artisan test --filter=AuditBenchmarkServiceTest`
Expected: FAIL — unknown column, or `percentileFor()` does not accept a second argument.

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_08_02_000002_add_versions_to_audit_reports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_reports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('scoring_version')->default(1)->after('payload')->index();
            $table->unsignedSmallInteger('payload_schema_version')->default(1)->after('scoring_version');
        });
    }

    public function down(): void
    {
        Schema::table('audit_reports', function (Blueprint $table): void {
            $table->dropColumn(['scoring_version', 'payload_schema_version']);
        });
    }
};
```

Defaults of `1` backfill every existing report to version 1 — spec §7.5.

- [ ] **Step 5: Add the version constants**

In `app/Services/AuditReport/ScoreCalculator.php`, add at the top of the class:

```php
/**
 * Bump when any formula in calculate() changes. Reports record the version
 * that produced them; deltas and benchmarks only compare within a version.
 */
public const VERSION = 1;
```

In `app/Services/AuditReport/ReportPayload.php`, add at the top of the class:

```php
/** Bump when the payload contract changes. validate() dispatches on this. */
public const VERSION = 1;
```

Both stay at `1` here. Task 17 bumps `ScoreCalculator::VERSION`; Task 19 bumps `ReportPayload::VERSION`.

- [ ] **Step 6: Make the report model fillable**

In `app/Models/AuditReport.php`, extend `$fillable`:

```php
protected $fillable = [
    'audit_request_id', 'user_id', 'payload', 'pdf_path',
    'unlocked_at', 'unlock_order_id',
    'scoring_version', 'payload_schema_version',
];
```

- [ ] **Step 7: Scope the delta service**

In `app/Services/AuditReport/AuditDeltaService.php`, add a version predicate to the previous-report lookup:

```php
$previous = AuditReport::query()
    ->whereHas('auditRequest', fn ($query) => $query
        ->where('email', $report->auditRequest->email)
        ->whereIn('repo_url', [$repoUrl, $repoUrl.'/']))
    ->where('id', '<', $report->id)
    ->where('scoring_version', $report->scoring_version)
    ->latest('id')
    ->first();
```

The existing `if ($previous === null) return null;` guard already produces the "no trend rather than a false trend" behaviour the spec requires.

- [ ] **Step 8: Scope the benchmark service**

Replace the body of `app/Services/AuditReport/AuditBenchmarkService.php`:

```php
public function percentileFor(int $overallScore, ?int $scoringVersion = null): ?int
{
    $version = $scoringVersion ?? ScoreCalculator::VERSION;

    $scores = Cache::remember("audit-benchmark-overall-scores:v{$version}", 3600, function () use ($version): array {
        return AuditReport::query()
            ->where('scoring_version', $version)
            ->pluck('payload')
            ->map(function ($payload): ?int {
                $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

                return isset($decoded['scores']['overall']) ? (int) $decoded['scores']['overall'] : null;
            })
            ->filter(fn (?int $score): bool => $score !== null)
            ->values()
            ->all();
    });

    if (count($scores) < (int) config('audit.benchmark_min_sample')) {
        return null;
    }

    $below = count(array_filter($scores, fn (int $score): bool => $score < $overallScore));

    return (int) round(100 * $below / count($scores));
}
```

The cache key now carries the version, so a version bump cannot serve a stale mixed-version distribution.

- [ ] **Step 9: Update the caller**

`AuditReportController` calls `percentileFor()`. Find it and pass the report's version:

```bash
grep -rn "percentileFor" app/
```

Change the call to `percentileFor($overall, $report->scoring_version)`.

- [ ] **Step 10: Run the tests**

Run: `php artisan test --filter=AuditDeltaServiceTest`
Expected: PASS, including the new cross-version test.

Run: `php artisan test --filter=AuditBenchmarkServiceTest`
Expected: PASS.

Run: `php artisan test --filter=AuditReportController`
Expected: PASS — report rendering unaffected.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_02_000002_add_versions_to_audit_reports_table.php app/Models/AuditReport.php app/Services/AuditReport/ tests/Feature/Services/AuditDeltaServiceTest.php tests/Feature/Services/AuditBenchmarkServiceTest.php app/Http/Controllers/AuditReportController.php
git commit -m "feat(audit): version scores and payloads, scope deltas and benchmarks to a version"
```

---

### Task 3: Tier profiles and configuration

**Files:**
- Modify: `config/audit.php` (add `tiers`, `scanners`, `findings`; remove two flat keys)
- Create: `app/Services/AuditReport/Tiers/TierProfile.php`
- Create: `app/Services/AuditReport/Tiers/TierProfileResolver.php`
- Modify: `app/Services/AuditReport/MetricsCollector.php:209-210` (read from profile, not config)
- Test: `tests/Feature/Services/TierProfileResolverTest.php`

**Interfaces:**
- Consumes: `App\Constants\AuditTier` (Task 1).
- Produces:
  ```php
  final readonly class TierProfile {
      public AuditTier $tier;
      public array $scanners;      // list<string>, in execution order
      public int $excerptFiles;
      public int $excerptBytes;
      public int $aiMaxTokens;
      public int $narratedGroups;
      public function runsScanner(string $name): bool;
  }

  class TierProfileResolver {
      public function for(AuditTier $tier): TierProfile;
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/TierProfileResolverTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class TierProfileResolverTest extends FeatureTest
{
    public function test_diagnostic_runs_only_the_cheap_scanner_subset(): void
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC);

        $this->assertSame(['scc', 'gitleaks', 'osv'], $profile->scanners);
        $this->assertFalse($profile->runsScanner('semgrep'));
        $this->assertFalse($profile->runsScanner('jscpd'));
    }

    public function test_automated_runs_the_full_set_in_the_committed_order(): void
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::AUTOMATED);

        $this->assertSame(['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'], $profile->scanners);
    }

    public function test_paid_tiers_have_larger_budgets_than_diagnostic(): void
    {
        $resolver = app(TierProfileResolver::class);
        $free = $resolver->for(AuditTier::DIAGNOSTIC);
        $paid = $resolver->for(AuditTier::AUTOMATED);

        $this->assertGreaterThan($free->excerptFiles, $paid->excerptFiles);
        $this->assertGreaterThan($free->aiMaxTokens, $paid->aiMaxTokens);
        $this->assertGreaterThan($free->narratedGroups, $paid->narratedGroups);
    }

    public function test_deep_ai_and_expert_match_automated_in_this_phase(): void
    {
        $resolver = app(TierProfileResolver::class);
        $automated = $resolver->for(AuditTier::AUTOMATED);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT] as $tier) {
            $this->assertSame($automated->scanners, $resolver->for($tier)->scanners);
        }
    }

    public function test_every_tier_in_the_enumeration_resolves(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach (AuditTier::cases() as $tier) {
            $this->assertSame($tier, $resolver->for($tier)->tier);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=TierProfileResolverTest`
Expected: FAIL — `Class "App\Services\AuditReport\Tiers\TierProfileResolver" not found`.

- [ ] **Step 3: Extend the config**

In `config/audit.php`, **delete** these two lines — they become a second source of truth once tiers exist:

```php
'max_excerpt_files' => 50,
'max_excerpt_bytes' => 6000,
```

Add these blocks:

```php
'tiers' => [
    'diagnostic' => [
        'scanners' => ['scc', 'gitleaks', 'osv'],
        'excerpt_files' => 15,
        'excerpt_bytes' => 3000,
        'ai_max_tokens' => 4000,
        'narrated_groups' => 2,
    ],
    'automated' => [
        'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
        'excerpt_files' => 50,
        'excerpt_bytes' => 6000,
        'ai_max_tokens' => 16000,
        'narrated_groups' => 12,
    ],
    // Phase 12 diverges deep_ai; Phase 13 adds the expert delivery hold.
    // Until then both compose identically to `automated` — deliberate, not an omission.
    'deep_ai' => [
        'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
        'excerpt_files' => 50,
        'excerpt_bytes' => 6000,
        'ai_max_tokens' => 16000,
        'narrated_groups' => 12,
    ],
    'expert' => [
        'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
        'excerpt_files' => 50,
        'excerpt_bytes' => 6000,
        'ai_max_tokens' => 16000,
        'narrated_groups' => 12,
    ],
],

'findings' => [
    'max_groups' => 20,
    'max_group_examples' => 8,
    'directory_depth' => 2,
    'severity_weights' => [
        'critical' => 100,
        'high' => 40,
        'medium' => 10,
        'low' => 3,
        'info' => 1,
    ],
],
```

The `scanners` block is added in Task 14, when provisioning lands.

- [ ] **Step 4: Create the value object**

Create `app/Services/AuditReport/Tiers/TierProfile.php`:

```php
<?php

namespace App\Services\AuditReport\Tiers;

use App\Constants\AuditTier;

final readonly class TierProfile
{
    /**
     * @param  list<string>  $scanners  scanner names in committed execution order
     */
    public function __construct(
        public AuditTier $tier,
        public array $scanners,
        public int $excerptFiles,
        public int $excerptBytes,
        public int $aiMaxTokens,
        public int $narratedGroups,
    ) {}

    public function runsScanner(string $name): bool
    {
        return in_array($name, $this->scanners, true);
    }
}
```

- [ ] **Step 5: Create the resolver**

Create `app/Services/AuditReport/Tiers/TierProfileResolver.php`:

```php
<?php

namespace App\Services\AuditReport\Tiers;

use App\Constants\AuditTier;
use InvalidArgumentException;

class TierProfileResolver
{
    public function for(AuditTier $tier): TierProfile
    {
        $config = config("audit.tiers.{$tier->value}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("No audit tier configuration for [{$tier->value}].");
        }

        return new TierProfile(
            tier: $tier,
            scanners: array_values($config['scanners']),
            excerptFiles: (int) $config['excerpt_files'],
            excerptBytes: (int) $config['excerpt_bytes'],
            aiMaxTokens: (int) $config['ai_max_tokens'],
            narratedGroups: (int) $config['narrated_groups'],
        );
    }
}
```

Throwing on a missing tier is correct: a tier value that resolves to no configuration is a deployment defect, and silently falling back to diagnostic budgets would mean a paying customer quietly receiving a free-tier analysis.

- [ ] **Step 6: Point MetricsCollector at the profile**

`MetricsCollector::excerpts()` currently reads `config('audit.max_excerpt_files')` and `config('audit.max_excerpt_bytes')`, which no longer exist. Change the signature to accept the profile:

```php
private function excerpts(string $repoPath, array $fileStats, TierProfile $profile): array
{
    $excerpts = [];

    foreach (array_slice($fileStats, 0, $profile->excerptFiles) as $file) {
        $content = (string) file_get_contents($repoPath.'/'.$file['path'], length: $profile->excerptBytes);
        $excerpts[] = ['path' => $file['path'], 'content' => $content];
    }

    return $excerpts;
}
```

Change `collect(string $repoPath)` to `collect(string $repoPath, TierProfile $profile)` and thread the profile through to `excerpts()`. Add `use App\Services\AuditReport\Tiers\TierProfile;`.

- [ ] **Step 7: Update the pipeline call site**

In `app/Services/AuditReport/AuditPipeline.php`, inject the resolver and pass a profile. Add `TierProfileResolver $tierProfileResolver` to the constructor, then change line 34:

```php
$profile = $this->tierProfileResolver->for($auditRequest->tier);
$collected = $this->metricsCollector->collect($path, $profile);
```

- [ ] **Step 8: Fix the existing collector tests**

`MetricsCollectorTest` and `MetricsCollectorGitTest` call `collect($path)` with one argument. Update every call site to pass a profile:

```php
$profile = app(TierProfileResolver::class)->for(AuditTier::AUTOMATED);
$collected = app(MetricsCollector::class)->collect($path, $profile);
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=TierProfileResolverTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=MetricsCollector`
Expected: PASS — both existing collector tests, now passing a profile.

Run: `php artisan test --filter=AuditPipelineTest`
Expected: PASS.

- [ ] **Step 10: Confirm no stale config references survive**

Run: `grep -rn "max_excerpt_files\|max_excerpt_bytes" app/ config/ tests/`
Expected: no output. Any hit is a caller still reading a deleted key, which would silently slice zero files.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/audit.php app/Services/AuditReport/ tests/Feature/Services/
git commit -m "feat(audit): add per-tier profiles and move excerpt budgets into tier config"
```

---

### Task 4: Severity and Finding value objects

**Files:**
- Create: `app/Services/AuditReport/Findings/Severity.php`
- Create: `app/Services/AuditReport/Findings/Finding.php`
- Test: `tests/Feature/Services/Findings/FindingTest.php`

**Interfaces:**
- Consumes: `config('audit.findings.severity_weights')` (Task 3).
- Produces:
  ```php
  enum Severity: string {
      case CRITICAL = 'critical'; case HIGH = 'high'; case MEDIUM = 'medium';
      case LOW = 'low'; case INFO = 'info';
      public function weight(): int;
      public function isAtLeast(self $other): bool;
      public static function max(self ...$severities): self;
  }

  final readonly class Finding {
      public function __construct(
          public string $tool, public string $ruleId, public string $ruleFamily,
          public Severity $severity, public string $path, public ?int $line,
          public string $message,
      ) {}
      public function fingerprint(): string;
      public function directory(int $depth): string;
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Findings/FindingTest.php`:

```php
<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingTest extends FeatureTest
{
    private function finding(array $overrides = []): Finding
    {
        return new Finding(
            tool: $overrides['tool'] ?? 'semgrep',
            ruleId: $overrides['ruleId'] ?? 'flexpick.php.sql-interpolation',
            ruleFamily: $overrides['ruleFamily'] ?? 'php.injection',
            severity: $overrides['severity'] ?? Severity::HIGH,
            path: $overrides['path'] ?? 'app/Http/Controllers/UserController.php',
            line: $overrides['line'] ?? 42,
            message: $overrides['message'] ?? 'SQL built by string interpolation.',
            dimension: $overrides['dimension'] ?? 'security_hygiene',
        );
    }

    public function test_carries_the_score_dimension_it_feeds(): void
    {
        $this->assertSame('security_hygiene', $this->finding()->dimension);
        $this->assertSame('structure', $this->finding(['dimension' => 'structure'])->dimension);
    }

    public function test_dimension_must_be_one_of_the_five_score_dimensions(): void
    {
        $this->assertContains($this->finding()->dimension, Finding::DIMENSIONS);
    }

    public function test_fingerprint_ignores_the_dimension(): void
    {
        // Dimension is routing metadata, not identity — two tools disagreeing
        // about it must still merge rather than double-count the same defect.
        $this->assertSame(
            $this->finding(['dimension' => 'security_hygiene'])->fingerprint(),
            $this->finding(['dimension' => 'structure'])->fingerprint(),
        );
    }

    public function test_fingerprint_ignores_the_reporting_tool(): void
    {
        // Two tools reporting the same defect at the same place must collide,
        // so the deduplicator can merge them.
        $a = $this->finding(['tool' => 'semgrep']);
        $b = $this->finding(['tool' => 'gitleaks']);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_fingerprint_distinguishes_family_path_and_line(): void
    {
        $base = $this->finding();

        $this->assertNotSame($base->fingerprint(), $this->finding(['ruleFamily' => 'php.xss'])->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->finding(['path' => 'app/Other.php'])->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->finding(['line' => 43])->fingerprint());
    }

    public function test_fingerprint_is_stable_for_line_less_findings(): void
    {
        $a = $this->finding(['line' => null]);
        $b = $this->finding(['line' => null]);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_directory_truncates_to_the_configured_depth(): void
    {
        $this->assertSame('app/Http', $this->finding()->directory(2));
        $this->assertSame('app', $this->finding()->directory(1));
    }

    public function test_root_level_files_group_under_a_dot(): void
    {
        $this->assertSame('.', $this->finding(['path' => 'composer.lock'])->directory(2));
    }

    public function test_severity_weights_come_from_configuration(): void
    {
        config()->set('audit.findings.severity_weights.critical', 999);

        $this->assertSame(999, Severity::CRITICAL->weight());
    }

    public function test_max_returns_the_most_severe(): void
    {
        $this->assertSame(
            Severity::CRITICAL,
            Severity::max(Severity::LOW, Severity::CRITICAL, Severity::MEDIUM),
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FindingTest`
Expected: FAIL — `Class "App\Services\AuditReport\Findings\Severity" not found`.

- [ ] **Step 3: Create the Severity enum**

Create `app/Services/AuditReport/Findings/Severity.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case INFO = 'info';

    /** Ranking weight; group score is the sum of its findings' weights. */
    public function weight(): int
    {
        return (int) config("audit.findings.severity_weights.{$this->value}");
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function max(self ...$severities): self
    {
        $max = self::INFO;

        foreach ($severities as $severity) {
            if ($severity->isAtLeast($max)) {
                $max = $severity;
            }
        }

        return $max;
    }

    /**
     * Ordering rank, independent of the configurable weights — comparison must
     * not change when an operator retunes the ranking weights.
     */
    private function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 5,
            self::HIGH => 4,
            self::MEDIUM => 3,
            self::LOW => 2,
            self::INFO => 1,
        };
    }
}
```

- [ ] **Step 4: Create the Finding value object**

Create `app/Services/AuditReport/Findings/Finding.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

/**
 * One normalized finding from one scanner.
 *
 * There is deliberately no field that could hold matched source content:
 * `message` carries the RULE's own description. This is what makes F5.2.6
 * (counts and paths only, never a secret value) structural rather than a
 * matter of remembering a redaction flag at each call site.
 */
final readonly class Finding
{
    /** The five score dimensions a finding may feed. */
    public const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public function __construct(
        public string $tool,
        public string $ruleId,
        public string $ruleFamily,
        public Severity $severity,
        public string $path,
        public ?int $line,
        public string $message,
        /**
         * Which score dimension this finding feeds. Set by the normalizer:
         * fixed per tool for Gitleaks, OSV, and jscpd; read from the rule's
         * metadata.dimension for Semgrep. Carrying it here is what keeps
         * scoring from needing a second rule-to-dimension mapping (spec §7.1).
         */
        public string $dimension,
    ) {}

    /** Tool-agnostic, so two tools reporting one defect merge in deduplication. */
    public function fingerprint(): string
    {
        return sha1($this->ruleFamily.'|'.$this->path.'|'.($this->line ?? ''));
    }

    /** First $depth path segments; root-level files group under '.'. */
    public function directory(int $depth): string
    {
        $dir = trim(str_replace('\\', '/', dirname($this->path)), '/');

        if ($dir === '' || $dir === '.') {
            return '.';
        }

        return implode('/', array_slice(explode('/', $dir), 0, $depth));
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=FindingTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Findings/ tests/Feature/Services/Findings/
git commit -m "feat(audit): add Finding and Severity value objects for the findings model"
```

---

### Task 5: Finding deduplication

**Files:**
- Create: `app/Services/AuditReport/Findings/FindingDeduplicator.php`
- Test: `tests/Feature/Services/Findings/FindingDeduplicatorTest.php`

**Interfaces:**
- Consumes: `Finding`, `Severity` (Task 4).
- Produces: `FindingDeduplicator::dedupe(array $findings): array` — returns `list<Finding>`, one per distinct fingerprint, carrying the maximum severity seen and a `tools` list. Because `Finding` is readonly and has no `tools` property, dedupe returns `DedupedFinding` objects:
  ```php
  final readonly class DedupedFinding {
      public Finding $finding;   // representative, carrying max severity
      public array $tools;       // sorted list<string> of contributing scanners
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Findings/FindingDeduplicatorTest.php`:

```php
<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingDeduplicatorTest extends FeatureTest
{
    private function finding(string $tool, Severity $severity, string $path = 'app/A.php', ?int $line = 10): Finding
    {
        return new Finding(
            tool: $tool,
            ruleId: $tool.'.rule',
            ruleFamily: 'secrets.credential',
            severity: $severity,
            path: $path,
            line: $line,
            message: 'A credential appears to be committed.',
            dimension: 'security_hygiene',
        );
    }

    public function test_merges_findings_sharing_a_fingerprint(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('gitleaks', Severity::CRITICAL),
            $this->finding('semgrep', Severity::MEDIUM),
        ]);

        $this->assertCount(1, $result);
    }

    public function test_merged_finding_keeps_the_highest_severity(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::MEDIUM),
            $this->finding('gitleaks', Severity::CRITICAL),
        ]);

        $this->assertSame(Severity::CRITICAL, $result[0]->finding->severity);
    }

    public function test_merged_finding_records_every_contributing_tool_sorted(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::MEDIUM),
            $this->finding('gitleaks', Severity::CRITICAL),
        ]);

        $this->assertSame(['gitleaks', 'semgrep'], $result[0]->tools);
    }

    public function test_keeps_distinct_findings_apart(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::HIGH, 'app/A.php', 10),
            $this->finding('semgrep', Severity::HIGH, 'app/B.php', 10),
            $this->finding('semgrep', Severity::HIGH, 'app/A.php', 99),
        ]);

        $this->assertCount(3, $result);
    }

    public function test_output_order_does_not_depend_on_input_order(): void
    {
        $a = $this->finding('semgrep', Severity::HIGH, 'app/A.php', 1);
        $b = $this->finding('semgrep', Severity::HIGH, 'app/B.php', 2);
        $c = $this->finding('semgrep', Severity::HIGH, 'app/C.php', 3);

        $deduplicator = app(FindingDeduplicator::class);

        $forward = array_map(fn ($d) => $d->finding->fingerprint(), $deduplicator->dedupe([$a, $b, $c]));
        $reverse = array_map(fn ($d) => $d->finding->fingerprint(), $deduplicator->dedupe([$c, $b, $a]));

        $this->assertSame($forward, $reverse);
    }

    public function test_handles_an_empty_finding_list(): void
    {
        $this->assertSame([], app(FindingDeduplicator::class)->dedupe([]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FindingDeduplicatorTest`
Expected: FAIL — `Class "App\Services\AuditReport\Findings\FindingDeduplicator" not found`.

- [ ] **Step 3: Create the DedupedFinding value object**

Create `app/Services/AuditReport/Findings/DedupedFinding.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

final readonly class DedupedFinding
{
    /**
     * @param  list<string>  $tools  contributing scanners, sorted
     */
    public function __construct(
        public Finding $finding,
        public array $tools,
    ) {}
}
```

- [ ] **Step 4: Create the deduplicator**

Create `app/Services/AuditReport/Findings/FindingDeduplicator.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

class FindingDeduplicator
{
    /**
     * Collapse findings sharing a fingerprint into one, keeping the highest
     * severity and recording every tool that reported it.
     *
     * Output is sorted by fingerprint so the result is independent of input
     * order — grouping downstream must be deterministic (spec §6.3).
     *
     * @param  list<Finding>  $findings
     * @return list<DedupedFinding>
     */
    public function dedupe(array $findings): array
    {
        /** @var array<string, array{finding: Finding, tools: array<string, true>}> $merged */
        $merged = [];

        foreach ($findings as $finding) {
            $key = $finding->fingerprint();

            if (! isset($merged[$key])) {
                $merged[$key] = ['finding' => $finding, 'tools' => []];
            } elseif ($finding->severity->isAtLeast($merged[$key]['finding']->severity)) {
                $merged[$key]['finding'] = $finding;
            }

            $merged[$key]['tools'][$finding->tool] = true;
        }

        ksort($merged);

        return array_values(array_map(function (array $entry): DedupedFinding {
            $tools = array_keys($entry['tools']);
            sort($tools);

            return new DedupedFinding($entry['finding'], $tools);
        }, $merged));
    }
}
```

`ksort` on the fingerprint plus `sort` on the tool list are the two total orderings that make this independent of input order and of hash-map iteration.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=FindingDeduplicatorTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Findings/ tests/Feature/Services/Findings/FindingDeduplicatorTest.php
git commit -m "feat(audit): deduplicate findings across scanners on a tool-agnostic fingerprint"
```

---

### Task 6: Grouping into problem families

**Files:**
- Create: `app/Services/AuditReport/Findings/FindingGroup.php`
- Create: `app/Services/AuditReport/Findings/FindingGrouper.php`
- Test: `tests/Feature/Services/Findings/FindingGrouperTest.php`

**Interfaces:**
- Consumes: `DedupedFinding` (Task 5), `config('audit.findings.*')` (Task 3).
- Produces:
  ```php
  final readonly class FindingGroup {
      public string $ruleFamily;
      public string $directory;
      public Severity $severity;   // max within the group
      public int $count;
      public int $score;           // Σ weight(severity)
      public array $examples;      // list<array{path: string, line: ?int}>, capped
      public array $tools;         // sorted list<string>
  }

  class FindingGrouper {
      /** @param list<DedupedFinding> $findings @return list<FindingGroup> */
      public function group(array $findings): array;
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Findings/FindingGrouperTest.php`:

```php
<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingGrouperTest extends FeatureTest
{
    private function deduped(
        string $family,
        string $path,
        Severity $severity,
        ?int $line = 1,
        string $dimension = 'security_hygiene',
    ): DedupedFinding {
        return new DedupedFinding(
            new Finding(
                tool: 'semgrep',
                ruleId: $family.'.rule',
                ruleFamily: $family,
                severity: $severity,
                path: $path,
                line: $line,
                message: 'Description of the rule.',
                dimension: $dimension,
            ),
            ['semgrep'],
        );
    }

    public function test_a_group_carries_the_dimension_of_its_findings(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('common.configuration', 'config/app.php', Severity::MEDIUM, 1, 'structure'),
        ]);

        $this->assertSame('structure', $groups[0]->dimension);
    }

    public function test_groups_by_rule_family_and_directory(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('php.injection', 'app/Http/UserController.php', Severity::HIGH),
            $this->deduped('php.injection', 'app/Http/OrderController.php', Severity::HIGH, 2),
            $this->deduped('php.injection', 'app/Models/User.php', Severity::HIGH, 3),
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame('app/Http', $groups[0]->directory);
        $this->assertSame(2, $groups[0]->count);
    }

    public function test_score_is_the_sum_of_severity_weights(): void
    {
        // critical 100 + low 3 = 103
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('secrets.credential', 'app/A.php', Severity::CRITICAL),
            $this->deduped('secrets.credential', 'app/B.php', Severity::LOW, 2),
        ]);

        $this->assertSame(103, $groups[0]->score);
    }

    public function test_one_critical_outranks_many_low_findings(): void
    {
        $findings = [$this->deduped('secrets.credential', 'app/A.php', Severity::CRITICAL)];

        // Twenty low findings: 20 × 3 = 60, below one critical's 100.
        for ($i = 0; $i < 20; $i++) {
            $findings[] = $this->deduped('style.formatting', 'src/B.php', Severity::LOW, $i);
        }

        $groups = app(FindingGrouper::class)->group($findings);

        $this->assertSame('secrets.credential', $groups[0]->ruleFamily);
    }

    public function test_group_severity_is_the_maximum_within_it(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('php.injection', 'app/A.php', Severity::LOW),
            $this->deduped('php.injection', 'app/B.php', Severity::CRITICAL, 2),
        ]);

        $this->assertSame(Severity::CRITICAL, $groups[0]->severity);
    }

    public function test_examples_are_capped_and_carry_no_content(): void
    {
        config()->set('audit.findings.max_group_examples', 3);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped('php.injection', "app/Http/File{$i}.php", Severity::HIGH, $i);
        }

        $group = app(FindingGrouper::class)->group($findings)[0];

        $this->assertCount(3, $group->examples);
        $this->assertSame(['path', 'line'], array_keys($group->examples[0]));
    }

    public function test_group_count_is_not_capped_by_the_example_cap(): void
    {
        config()->set('audit.findings.max_group_examples', 3);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped('php.injection', "app/Http/File{$i}.php", Severity::HIGH, $i);
        }

        // The report must say "10 findings", not "3".
        $this->assertSame(10, app(FindingGrouper::class)->group($findings)[0]->count);
    }

    public function test_number_of_groups_is_capped(): void
    {
        config()->set('audit.findings.max_groups', 2);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped("family.number{$i}", "app/Dir{$i}/File.php", Severity::HIGH, $i);
        }

        $this->assertCount(2, app(FindingGrouper::class)->group($findings));
    }

    public function test_grouping_is_deterministic_under_shuffled_input(): void
    {
        $findings = [];
        for ($i = 1; $i <= 30; $i++) {
            $findings[] = $this->deduped(
                'family.'.($i % 4),
                'app/Dir'.($i % 5).'/File'.$i.'.php',
                [Severity::CRITICAL, Severity::HIGH, Severity::MEDIUM, Severity::LOW][$i % 4],
                $i,
            );
        }

        $grouper = app(FindingGrouper::class);
        $first = $grouper->group($findings);

        $shuffled = $findings;
        shuffle($shuffled);
        $second = $grouper->group($shuffled);

        $this->assertSame(
            json_encode(array_map(fn ($g) => (array) $g, $first)),
            json_encode(array_map(fn ($g) => (array) $g, $second)),
        );
    }

    public function test_handles_an_empty_finding_list(): void
    {
        $this->assertSame([], app(FindingGrouper::class)->group([]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FindingGrouperTest`
Expected: FAIL — `Class "App\Services\AuditReport\Findings\FindingGrouper" not found`.

- [ ] **Step 3: Create the FindingGroup value object**

Create `app/Services/AuditReport/Findings/FindingGroup.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

/**
 * A problem family: one rule family within one directory.
 *
 * This is the unit the model narrates and the unit persisted to
 * audit_finding_groups. `examples` holds locations only — never content,
 * so a secret cannot reach storage or the prompt through a group.
 */
final readonly class FindingGroup
{
    /**
     * @param  list<array{path: string, line: int|null}>  $examples
     * @param  list<string>  $tools
     */
    public function __construct(
        public string $ruleFamily,
        public string $directory,
        public Severity $severity,
        public int $count,
        public int $score,
        public array $examples,
        public array $tools,
        /**
         * The score dimension this group feeds, taken from its findings.
         * A rule family always maps to one dimension — a family spanning two
         * is a ruleset defect, caught by the metadata test in Task 10.
         */
        public string $dimension,
    ) {}
}
```

- [ ] **Step 4: Create the grouper**

Create `app/Services/AuditReport/Findings/FindingGrouper.php`:

```php
<?php

namespace App\Services\AuditReport\Findings;

class FindingGrouper
{
    /**
     * Collapse deduplicated findings into ranked problem families.
     *
     * Every ordering here is total. Ranking ties break on the group key, and
     * example selection breaks on path then line, so the same repository
     * produces byte-identical groups on every run (spec §6.3) — score deltas
     * and persisted examples both depend on that.
     *
     * @param  list<DedupedFinding>  $findings
     * @return list<FindingGroup>
     */
    public function group(array $findings): array
    {
        $depth = (int) config('audit.findings.directory_depth');
        $maxGroups = (int) config('audit.findings.max_groups');
        $maxExamples = (int) config('audit.findings.max_group_examples');

        /** @var array<string, list<DedupedFinding>> $buckets */
        $buckets = [];

        foreach ($findings as $deduped) {
            $key = $deduped->finding->ruleFamily.'|'.$deduped->finding->directory($depth);
            $buckets[$key][] = $deduped;
        }

        ksort($buckets);

        $groups = [];

        foreach ($buckets as $key => $bucket) {
            [$ruleFamily, $directory] = explode('|', $key, 2);

            $severities = array_map(fn (DedupedFinding $d): Severity => $d->finding->severity, $bucket);
            $score = array_sum(array_map(fn (Severity $s): int => $s->weight(), $severities));

            $tools = array_values(array_unique(array_merge(...array_map(fn (DedupedFinding $d): array => $d->tools, $bucket))));
            sort($tools);

            // Uniform across a rule family by construction; take the lowest
            // sorted value so a malformed ruleset still groups deterministically.
            $dimensions = array_unique(array_map(
                fn (DedupedFinding $d): string => $d->finding->dimension,
                $bucket,
            ));
            sort($dimensions);

            $groups[] = new FindingGroup(
                ruleFamily: $ruleFamily,
                directory: $directory,
                severity: Severity::max(...$severities),
                count: count($bucket),
                score: $score,
                examples: $this->examples($bucket, $maxExamples),
                tools: $tools,
                dimension: $dimensions[0],
            );
        }

        usort($groups, fn (FindingGroup $a, FindingGroup $b): int => [$b->score, $a->ruleFamily, $a->directory]
            <=> [$a->score, $b->ruleFamily, $b->directory]);

        return array_slice($groups, 0, $maxGroups);
    }

    /**
     * Highest severity first, then path, then line — a total order, so the
     * same findings always cite the same examples.
     *
     * @param  list<DedupedFinding>  $bucket
     * @return list<array{path: string, line: int|null}>
     */
    private function examples(array $bucket, int $max): array
    {
        usort($bucket, function (DedupedFinding $a, DedupedFinding $b): int {
            $bySeverity = $b->finding->severity->isAtLeast($a->finding->severity) <=> $a->finding->severity->isAtLeast($b->finding->severity);

            return $bySeverity !== 0
                ? $bySeverity
                : [$a->finding->path, $a->finding->line ?? -1] <=> [$b->finding->path, $b->finding->line ?? -1];
        });

        return array_map(
            fn (DedupedFinding $d): array => ['path' => $d->finding->path, 'line' => $d->finding->line],
            array_slice($bucket, 0, $max),
        );
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=FindingGrouperTest`
Expected: PASS, 9 tests. The determinism test is the important one — if it fails, an ordering somewhere is partial.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Findings/ tests/Feature/Services/Findings/FindingGrouperTest.php
git commit -m "feat(audit): group findings into ranked problem families deterministically"
```

---

### Task 7: Persisting finding groups

**Files:**
- Create: `database/migrations/2026_08_02_000003_create_audit_finding_groups_table.php`
- Create: `app/Models/AuditFindingGroup.php`
- Create: `database/factories/AuditFindingGroupFactory.php`
- Modify: `app/Models/AuditRequest.php` (add `findingGroups()` relation)
- Test: `tests/Feature/Models/AuditFindingGroupTest.php`

**Interfaces:**
- Consumes: `FindingGroup` (Task 6).
- Produces: `App\Models\AuditFindingGroup` Eloquent model; `AuditRequest::findingGroups(): HasMany`; `AuditFindingGroup::fromValueObject(AuditRequest $request, FindingGroup $group): array` returning an attribute array ready for `insert()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/AuditFindingGroupTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class AuditFindingGroupTest extends FeatureTest
{
    private function group(): FindingGroup
    {
        return new FindingGroup(
            ruleFamily: 'php.injection',
            directory: 'app/Http',
            severity: Severity::HIGH,
            count: 37,
            score: 1480,
            examples: [['path' => 'app/Http/UserController.php', 'line' => 42]],
            tools: ['semgrep'],
            dimension: 'security_hygiene',
        );
    }

    public function test_persists_a_group_from_its_value_object(): void
    {
        $request = AuditRequest::factory()->create();

        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $this->assertDatabaseHas('audit_finding_groups', [
            'audit_request_id' => $request->id,
            'rule_family' => 'php.injection',
            'directory' => 'app/Http',
            'severity' => 'high',
            'count' => 37,
            'score' => 1480,
        ]);
    }

    public function test_casts_examples_and_tools_to_arrays(): void
    {
        $request = AuditRequest::factory()->create();
        $stored = AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $fresh = $stored->fresh();

        $this->assertSame([['path' => 'app/Http/UserController.php', 'line' => 42]], $fresh->examples);
        $this->assertSame(['semgrep'], $fresh->tools);
    }

    public function test_groups_are_reachable_from_the_request(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $this->assertCount(1, $request->fresh()->findingGroups);
    }

    public function test_groups_are_deleted_with_their_request(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $request->delete();

        $this->assertDatabaseCount('audit_finding_groups', 0);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AuditFindingGroupTest`
Expected: FAIL — `Class "App\Models\AuditFindingGroup" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_02_000003_create_audit_finding_groups_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_finding_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_request_id')->constrained()->cascadeOnDelete();
            $table->string('rule_family');
            $table->string('directory');
            $table->string('severity');
            // Which score dimension this group fed — makes it possible to
            // explain a score after the fact without re-deriving the mapping.
            $table->string('dimension');
            $table->unsignedInteger('count');
            $table->unsignedInteger('score');
            $table->json('examples');
            $table->json('tools');
            $table->timestamps();

            // Supports later cross-run comparison of the same family in the
            // same directory (spec §6.5, deferred group-level deltas).
            $table->index(['rule_family', 'directory']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_finding_groups');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/AuditFindingGroup.php`:

```php
<?php

namespace App\Models;

use App\Services\AuditReport\Findings\FindingGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFindingGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_request_id', 'rule_family', 'directory', 'severity', 'dimension',
        'count', 'score', 'examples', 'tools',
    ];

    protected $casts = [
        'examples' => 'array',
        'tools' => 'array',
        'count' => 'integer',
        'score' => 'integer',
    ];

    /** @return array<string, mixed> */
    public static function fromValueObject(AuditRequest $request, FindingGroup $group): array
    {
        return [
            'audit_request_id' => $request->id,
            'rule_family' => $group->ruleFamily,
            'directory' => $group->directory,
            'severity' => $group->severity->value,
            'dimension' => $group->dimension,
            'count' => $group->count,
            'score' => $group->score,
            'examples' => $group->examples,
            'tools' => $group->tools,
        ];
    }

    /** @return BelongsTo<AuditRequest, $this> */
    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/AuditFindingGroupFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AuditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFindingGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_request_id' => AuditRequest::factory(),
            'rule_family' => 'php.injection',
            'directory' => 'app/Http',
            'severity' => 'high',
            'dimension' => 'security_hygiene',
            'count' => 5,
            'score' => 200,
            'examples' => [['path' => 'app/Http/Controller.php', 'line' => 10]],
            'tools' => ['semgrep'],
        ];
    }
}
```

- [ ] **Step 6: Add the relation**

In `app/Models/AuditRequest.php`, add:

```php
/** @return HasMany<AuditFindingGroup, $this> */
public function findingGroups(): HasMany
{
    return $this->hasMany(AuditFindingGroup::class)->orderByDesc('score');
}
```

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports. Ordering by score in the relation means every read site gets ranked groups without repeating the sort.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=AuditFindingGroupTest`
Expected: PASS, 4 tests.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_02_000003_create_audit_finding_groups_table.php app/Models/ database/factories/AuditFindingGroupFactory.php tests/Feature/Models/AuditFindingGroupTest.php
git commit -m "feat(audit): persist finding groups against audit requests"
```

---

### Task 8: Scanner interface and the absorbing runner

This task builds the harness with **no real scanner**. Every behaviour is tested against fake
scanners, so the absorb-and-classify contract is pinned before any binary is involved.

**Files:**
- Create: `app/Services/AuditReport/Scanners/Scanner.php`
- Create: `app/Services/AuditReport/Scanners/ScannerOutcome.php`
- Create: `app/Services/AuditReport/Scanners/ScannerRun.php`
- Create: `app/Services/AuditReport/Scanners/ScannerSuiteResult.php`
- Create: `app/Services/AuditReport/Scanners/RepoContext.php`
- Create: `app/Services/AuditReport/Scanners/SccInventory.php`
- Create: `app/Services/AuditReport/Scanners/ScannerRunner.php`
- Test: `tests/Feature/Services/Scanners/ScannerRunnerTest.php`

**Interfaces:**
- Consumes: `Finding` (Task 4), `TierProfile` (Task 3).
- Produces:
  ```php
  interface Scanner {
      public function name(): string;
      public function isAvailable(): bool;
      public function version(): string;
      /** @return list<Finding> */
      public function scan(RepoContext $ctx): array;
  }

  enum ScannerOutcome: string { case OK; case FAILED; case TIMEOUT; case UNAVAILABLE; }

  final readonly class ScannerRun {
      public string $name; public string $version; public int $wallMs;
      public int $findingCount; public ScannerOutcome $outcome; public ?string $reason;
      public function toArray(): array;
  }

  final readonly class ScannerSuiteResult {
      public array $findings;  // list<Finding>
      public array $runs;      // list<ScannerRun>
      public function ranSuccessfully(string $scanner): bool;
      public function runsAsArray(): array;
  }

  final class RepoContext {
      public string $path; public TierProfile $tier;
      public ?SccInventory $inventory;                 // mutable: scc fills it for later scanners
      public function withInventory(SccInventory $i): void;
  }

  class ScannerRunner {
      /** @param list<string> $names */
      public function run(array $names, RepoContext $ctx): ScannerSuiteResult;
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Scanners/ScannerRunnerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\Scanner;
use App\Services\AuditReport\Scanners\ScannerOutcome;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\FeatureTest;

class ScannerRunnerTest extends FeatureTest
{
    private function context(): RepoContext
    {
        return new RepoContext(
            path: '/tmp/does-not-matter',
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );
    }

    private function fakeScanner(string $name, callable $scan, bool $available = true): Scanner
    {
        return new class($name, $scan, $available) implements Scanner
        {
            public function __construct(
                private string $scannerName,
                private $scanCallback,
                private bool $available,
            ) {}

            public function name(): string
            {
                return $this->scannerName;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function scan(RepoContext $ctx): array
            {
                return ($this->scanCallback)($ctx);
            }
        };
    }

    private function finding(string $tool): Finding
    {
        return new Finding(
            tool: $tool,
            ruleId: $tool.'.rule',
            ruleFamily: 'php.injection',
            severity: Severity::HIGH,
            path: 'app/A.php',
            line: 1,
            message: 'Description.',
            dimension: 'security_hygiene',
        );
    }

    private function runnerWith(Scanner ...$scanners): ScannerRunner
    {
        foreach ($scanners as $scanner) {
            $this->app->bind('audit.scanner.'.$scanner->name(), fn () => $scanner);
        }

        return app(ScannerRunner::class);
    }

    public function test_collects_findings_from_every_scanner(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha')]),
            $this->fakeScanner('beta', fn () => [$this->finding('beta')]),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        $this->assertCount(2, $result->findings);
    }

    public function test_runs_scanners_in_the_order_given(): void
    {
        $order = [];

        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', function () use (&$order) { $order[] = 'alpha'; return []; }),
            $this->fakeScanner('beta', function () use (&$order) { $order[] = 'beta'; return []; }),
        );

        $runner->run(['alpha', 'beta'], $this->context());

        $this->assertSame(['alpha', 'beta'], $order);
    }

    public function test_a_throwing_scanner_does_not_fail_the_run(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new RuntimeException('boom')),
            $this->fakeScanner('beta', fn () => [$this->finding('beta')]),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        // beta still ran and contributed.
        $this->assertCount(1, $result->findings);
        $this->assertSame(ScannerOutcome::FAILED, $result->runs[0]->outcome);
    }

    public function test_a_timeout_is_classified_separately_from_a_failure(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new ProcessTimedOutException(
                new Process(['true']), ProcessTimedOutException::TYPE_GENERAL,
            )),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame(ScannerOutcome::TIMEOUT, $result->runs[0]->outcome);
    }

    public function test_an_unavailable_binary_takes_the_same_degrade_path(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha')], available: false),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame([], $result->findings);
        $this->assertSame(ScannerOutcome::UNAVAILABLE, $result->runs[0]->outcome);
    }

    public function test_failure_reason_is_classified_and_never_contains_tool_output(): void
    {
        // Semgrep's stderr can echo matched source lines; those must never
        // reach the pipeline log or Bugsink (spec §5.4).
        $secret = 'AKIAIOSFODNN7EXAMPLE';

        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new RuntimeException("scan failed near {$secret}")),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame('nonzero_exit', $result->runs[0]->reason);
        $this->assertStringNotContainsString($secret, json_encode($result->runsAsArray()));
    }

    public function test_records_provenance_for_every_scanner(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha'), $this->finding('alpha')]),
        );

        $run = $runner->run(['alpha'], $this->context())->runs[0];

        $this->assertSame('alpha', $run->name);
        $this->assertSame('1.0.0', $run->version);
        $this->assertSame(2, $run->findingCount);
        $this->assertSame(ScannerOutcome::OK, $run->outcome);
        $this->assertGreaterThanOrEqual(0, $run->wallMs);
    }

    public function test_reports_whether_a_named_scanner_succeeded(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => []),
            $this->fakeScanner('beta', fn () => throw new RuntimeException('boom')),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        $this->assertTrue($result->ranSuccessfully('alpha'));
        $this->assertFalse($result->ranSuccessfully('beta'));
        // A scanner that was never asked to run also did not succeed.
        $this->assertFalse($result->ranSuccessfully('semgrep'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ScannerRunnerTest`
Expected: FAIL — `Class "App\Services\AuditReport\Scanners\RepoContext" not found`.

- [ ] **Step 3: Create the outcome enum and provenance objects**

Create `app/Services/AuditReport/Scanners/ScannerOutcome.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

enum ScannerOutcome: string
{
    case OK = 'ok';
    case FAILED = 'failed';
    case TIMEOUT = 'timeout';
    case UNAVAILABLE = 'unavailable';
}
```

Create `app/Services/AuditReport/Scanners/ScannerRun.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

/**
 * Provenance for one scanner within one run.
 *
 * `reason` is a bounded classification, never captured stdout or stderr —
 * Semgrep's stderr can echo matched source lines, and this value reaches both
 * the pipeline log and Bugsink (spec §5.4).
 */
final readonly class ScannerRun
{
    public function __construct(
        public string $name,
        public string $version,
        public int $wallMs,
        public int $findingCount,
        public ScannerOutcome $outcome,
        public ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'wall_ms' => $this->wallMs,
            'finding_count' => $this->findingCount,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
        ];
    }
}
```

Create `app/Services/AuditReport/Scanners/ScannerSuiteResult.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;

final readonly class ScannerSuiteResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<ScannerRun>  $runs
     */
    public function __construct(
        public array $findings,
        public array $runs,
    ) {}

    /**
     * Whether the named scanner ran to completion. A scanner that was never
     * asked to run returns false — the score dimensions it feeds must be
     * marked not-measured either way (spec §7.2).
     */
    public function ranSuccessfully(string $scanner): bool
    {
        foreach ($this->runs as $run) {
            if ($run->name === $scanner) {
                return $run->outcome === ScannerOutcome::OK;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function runsAsArray(): array
    {
        return array_map(fn (ScannerRun $run): array => $run->toArray(), $this->runs);
    }

    public function totalWallMs(): int
    {
        return array_sum(array_map(fn (ScannerRun $run): int => $run->wallMs, $this->runs));
    }
}
```

- [ ] **Step 4: Create the context and inventory**

Create `app/Services/AuditReport/Scanners/SccInventory.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

/**
 * The repository's file inventory, produced by scc (or by the Finder fallback
 * when scc is unavailable — spec §10). Later scanners cap their file sets
 * against this, and the excerpt collector selects from it.
 */
final readonly class SccInventory
{
    /**
     * @param  list<array{path: string, loc: int, complexity: int}>  $files  descending by loc
     * @param  array<string, array{files: int, loc: int}>  $languages
     */
    public function __construct(
        public array $files,
        public array $languages,
        public int $totalLoc,
        public int $totalComplexity,
    ) {}

    /** @return list<string> */
    public function paths(int $limit): array
    {
        return array_map(
            fn (array $file): string => $file['path'],
            array_slice($this->files, 0, $limit),
        );
    }
}
```

Create `app/Services/AuditReport/Scanners/RepoContext.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Tiers\TierProfile;

/**
 * Per-run state shared across the scanner sequence.
 *
 * Mutable by design in two respects: scc runs first and fills `inventory`,
 * which every later scanner reads to cap its file set (spec §3.2); and any
 * scanner may `record()` a scalar measurement the pipeline needs afterwards.
 *
 * Both live HERE rather than on the scanner instances because Horizon workers
 * are long-lived: state on a scanner would persist across audit runs in one
 * process, so a scanner that failed on run B could be read with run A's value.
 * Scoping it to the context makes that impossible by construction rather than
 * guarded against.
 */
final class RepoContext
{
    /** @var array<string, float|int> */
    public private(set) array $measurements = [];

    public function __construct(
        public readonly string $path,
        public readonly TierProfile $tier,
        public ?SccInventory $inventory = null,
    ) {}

    public function withInventory(SccInventory $inventory): void
    {
        $this->inventory = $inventory;
    }

    public function record(string $key, float|int $value): void
    {
        $this->measurements[$key] = $value;
    }

    public function measurement(string $key, float|int $default = 0): float|int
    {
        return $this->measurements[$key] ?? $default;
    }
}
```

- [ ] **Step 5: Create the runner**

Create `app/Services/AuditReport/Scanners/ScannerRunner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use Illuminate\Contracts\Container\Container;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Runs the tier's scanners in the committed order, absorbing every failure.
 *
 * F5.12.2: a failed scanner contributes no findings, is recorded, and never
 * fails the run. A missing binary takes the identical path — no special case,
 * so a half-provisioned host degrades rather than erroring.
 */
class ScannerRunner
{
    public function __construct(private Container $container) {}

    /**
     * @param  list<string>  $names  scanner names, in execution order
     */
    public function run(array $names, RepoContext $context): ScannerSuiteResult
    {
        $findings = [];
        $runs = [];

        foreach ($names as $name) {
            $scanner = $this->container->make('audit.scanner.'.$name);
            $startedAt = hrtime(true);

            if (! $scanner->isAvailable()) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::UNAVAILABLE,
                    reason: 'unavailable',
                );

                continue;
            }

            try {
                $produced = $scanner->scan($context);

                $findings = array_merge($findings, $produced);
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: count($produced),
                    outcome: ScannerOutcome::OK,
                );
            } catch (ProcessTimedOutException) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::TIMEOUT,
                    reason: 'timeout',
                );
            } catch (Throwable $e) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::FAILED,
                    // Classified, never $e->getMessage() — that can carry tool output.
                    reason: $e instanceof \JsonException ? 'parse_failure' : 'nonzero_exit',
                );
            }
        }

        return new ScannerSuiteResult(array_values($findings), $runs);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
```

- [ ] **Step 6: Create the Scanner interface**

Create `app/Services/AuditReport/Scanners/Scanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;

/**
 * An external static analyzer.
 *
 * Distinct from Collector: a Scanner throwing is NORMAL and is absorbed by
 * ScannerRunner. A Collector throwing is a bug and fails the run. One
 * interface would force one policy, and the absorbing policy is wrong for
 * internal code (spec §3.1).
 */
interface Scanner
{
    public function name(): string;

    /** Whether the configured binary exists and is executable. */
    public function isAvailable(): bool;

    /** The configured, pinned version — recorded per run as provenance. */
    public function version(): string;

    /** @return list<Finding> */
    public function scan(RepoContext $context): array;
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=ScannerRunnerTest`
Expected: PASS, 8 tests.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Scanners/ tests/Feature/Services/Scanners/
git commit -m "feat(audit): add the scanner interface and absorbing scanner runner"
```

---

### Task 9: SARIF normalization and the Gitleaks scanner

**Files:**
- Create: `app/Services/AuditReport/Findings/Normalizers/SarifNormalizer.php`
- Create: `app/Services/AuditReport/Scanners/GitleaksScanner.php`
- Create: `tests/Feature/Services/Fixtures/Scanners/gitleaks.sarif.json`
- Test: `tests/Feature/Services/Scanners/GitleaksScannerTest.php`

**Interfaces:**
- Consumes: `Scanner`, `RepoContext` (Task 8); `Finding`, `Severity` (Task 4).
- Produces: `SarifNormalizer::normalize(array $sarif, string $tool, callable $severityFor, callable $familyFor): array` returning `list<Finding>`. `GitleaksScanner` implements `Scanner` with `name() === 'gitleaks'`.

- [ ] **Step 1: Create the SARIF fixture**

Create `tests/Feature/Services/Fixtures/Scanners/gitleaks.sarif.json`. This is real Gitleaks
SARIF shape, including a `snippet` — the field that must never survive normalization:

```json
{
  "version": "2.1.0",
  "runs": [
    {
      "tool": { "driver": { "name": "gitleaks", "rules": [
        { "id": "aws-access-token", "description": { "text": "AWS Access Token" } }
      ] } },
      "results": [
        {
          "ruleId": "aws-access-token",
          "level": "error",
          "message": { "text": "aws-access-token has detected a secret." },
          "locations": [
            {
              "physicalLocation": {
                "artifactLocation": { "uri": "config/services.php" },
                "region": {
                  "startLine": 17,
                  "snippet": { "text": "'key' => 'AKIAIOSFODNN7EXAMPLE'," }
                }
              }
            }
          ]
        },
        {
          "ruleId": "generic-api-key",
          "level": "error",
          "message": { "text": "generic-api-key has detected a secret." },
          "locations": [
            {
              "physicalLocation": {
                "artifactLocation": { "uri": "app/Services/Payment.php" },
                "region": { "startLine": 88, "snippet": { "text": "$key = 'sk_live_supersecret';" } }
              }
            }
          ]
        }
      ]
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Services/Scanners/GitleaksScannerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\GitleaksScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class GitleaksScannerTest extends FeatureTest
{
    private function sarif(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/gitleaks.sarif.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(GitleaksScanner::class)->normalize($this->sarif());
    }

    public function test_normalizes_every_result(): void
    {
        $this->assertCount(2, $this->normalize());
    }

    public function test_every_gitleaks_finding_is_critical(): void
    {
        // Gitleaks emits no severity of its own; a live credential is the
        // highest-consequence finding the pipeline can produce (spec §5.6).
        foreach ($this->normalize() as $finding) {
            $this->assertSame(Severity::CRITICAL, $finding->severity);
        }
    }

    public function test_carries_path_and_line(): void
    {
        $finding = $this->normalize()[0];

        $this->assertSame('config/services.php', $finding->path);
        $this->assertSame(17, $finding->line);
    }

    public function test_the_matched_secret_never_survives_normalization(): void
    {
        // The fixture's snippet contains these values. F5.2.6 is counts and
        // paths only — no field on Finding may carry them.
        $serialized = json_encode(array_map(fn ($f) => (array) $f, $this->normalize()));

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $serialized);
        $this->assertStringNotContainsString('sk_live_supersecret', $serialized);
    }

    public function test_rule_family_is_the_secrets_family(): void
    {
        $this->assertSame('secrets.credential', $this->normalize()[0]->ruleFamily);
    }

    public function test_tool_is_recorded(): void
    {
        $this->assertSame('gitleaks', $this->normalize()[0]->tool);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.gitleaks.bin', '/nonexistent/gitleaks');

        $this->assertFalse(app(GitleaksScanner::class)->isAvailable());
    }

    public function test_a_sarif_document_with_no_runs_yields_no_findings(): void
    {
        $this->assertSame([], app(SarifNormalizer::class)->normalize(
            ['version' => '2.1.0', 'runs' => []],
            'gitleaks',
            fn () => Severity::CRITICAL,
            fn () => 'secrets.credential',
            fn () => 'security_hygiene',
        ));
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=GitleaksScannerTest`
Expected: FAIL — `Class "App\Services\AuditReport\Scanners\GitleaksScanner" not found`.

- [ ] **Step 4: Create the SARIF normalizer**

Create `app/Services/AuditReport/Findings/Normalizers/SarifNormalizer.php`:

```php
<?php

namespace App\Services\AuditReport\Findings\Normalizers;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;

/**
 * Shared normalization for the two SARIF-emitting scanners (Gitleaks, Semgrep).
 *
 * The `snippet` field carried by SARIF regions is deliberately never read.
 * That is the second of the two independent guards on F5.2.6 — the first is
 * `--redact` at invocation. Trusting one flag at one call site is not enough
 * for a guarantee this consequential (spec §5.4).
 */
class SarifNormalizer
{
    /**
     * @param  callable(array<string, mixed>): Severity  $severityFor
     * @param  callable(array<string, mixed>, string): string  $familyFor
     * @param  callable(string): string  $dimensionFor  rule id → score dimension
     * @return list<Finding>
     */
    public function normalize(
        array $sarif,
        string $tool,
        callable $severityFor,
        callable $familyFor,
        callable $dimensionFor,
    ): array {
        $findings = [];

        foreach ($sarif['runs'] ?? [] as $run) {
            $ruleDescriptions = $this->ruleDescriptions($run);

            foreach ($run['results'] ?? [] as $result) {
                $location = $result['locations'][0]['physicalLocation'] ?? [];
                $path = $location['artifactLocation']['uri'] ?? null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $ruleId = (string) ($result['ruleId'] ?? 'unknown');
                $line = $location['region']['startLine'] ?? null;

                $findings[] = new Finding(
                    tool: $tool,
                    ruleId: $ruleId,
                    ruleFamily: $familyFor($result, $ruleId),
                    severity: $severityFor($result),
                    path: ltrim($path, './'),
                    line: is_int($line) ? $line : null,
                    // The rule's own description, never the matched snippet.
                    message: $ruleDescriptions[$ruleId] ?? (string) ($result['message']['text'] ?? $ruleId),
                    dimension: $dimensionFor($ruleId),
                );
            }
        }

        return $findings;
    }

    /** @return array<string, string> */
    private function ruleDescriptions(array $run): array
    {
        $descriptions = [];

        foreach ($run['tool']['driver']['rules'] ?? [] as $rule) {
            if (isset($rule['id'])) {
                $descriptions[(string) $rule['id']] = (string) ($rule['description']['text'] ?? $rule['id']);
            }
        }

        return $descriptions;
    }
}
```

- [ ] **Step 5: Create the Gitleaks scanner**

Create `app/Services/AuditReport/Scanners/GitleaksScanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class GitleaksScanner implements Scanner
{
    public function __construct(private SarifNormalizer $normalizer) {}

    public function name(): string
    {
        return 'gitleaks';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.gitleaks.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.gitleaks.version');
    }

    public function scan(RepoContext $context): array
    {
        $report = tempnam(sys_get_temp_dir(), 'gitleaks-').'.sarif';

        try {
            Process::timeout((int) config('audit.scanners.gitleaks.timeout'))
                ->run([
                    (string) config('audit.scanners.gitleaks.bin'),
                    'dir', $context->path,
                    '--report-format', 'sarif',
                    '--report-path', $report,
                    // First of two guards on F5.2.6 — the normalizer is the second.
                    '--redact',
                    '--no-banner',
                    // Repo-supplied config must never steer the analyzer (spec §5.4).
                    '--config', config('audit.scanners.gitleaks.config'),
                ]);

            // Gitleaks exits non-zero when it finds leaks, so the exit code is
            // not an error signal here — a missing report file is.
            if (! file_exists($report)) {
                throw new RuntimeException('gitleaks produced no report');
            }

            return $this->normalize($this->decode($report));
        } finally {
            @unlink($report);
        }
    }

    /** @return list<\App\Services\AuditReport\Findings\Finding> */
    public function normalize(array $sarif): array
    {
        return $this->normalizer->normalize(
            $sarif,
            $this->name(),
            // Gitleaks emits no severity; every leak is critical (spec §5.6).
            fn (): Severity => Severity::CRITICAL,
            fn (): string => 'secrets.credential',
            fn (): string => 'security_hygiene',
        );
    }

    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('gitleaks report is not an object');
        }

        return $decoded;
    }
}
```

- [ ] **Step 6: Register the scanner binding**

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->bind('audit.scanner.gitleaks', GitleaksScanner::class);
```

Add the import. Each scanner task adds its own binding line here.

- [ ] **Step 7: Add the Gitleaks config keys**

In `config/audit.php`, add the `scanners` block (Task 14 fills in the remaining tools):

```php
'scanners' => [
    'gitleaks' => [
        'bin' => env('AUDIT_GITLEAKS_BIN', '/opt/flexpick/bin/gitleaks'),
        'version' => '8.28.0',
        'timeout' => 120,
        'config' => resource_path('scanners/gitleaks.toml'),
    ],
],
```

Create `resources/scanners/gitleaks.toml` with the upstream default ruleset extended, so
the repository's own `.gitleaks.toml` is never consulted:

```toml
title = "FlexPick Gitleaks config"

[extend]
useDefault = true
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter=GitleaksScannerTest`
Expected: PASS, 8 tests. The secret-survival test is the load-bearing one.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ app/Providers/AppServiceProvider.php config/audit.php resources/scanners/ tests/Feature/Services/
git commit -m "feat(audit): add SARIF normalization and the Gitleaks secret scanner"
```

---

### Task 10: Semgrep scanner and the in-house ruleset

Q33 is resolved by shipping **only rules we own** — no Semgrep Registry rules, so no
rule-by-rule licensing review gates launch (spec §5.3).

**Files:**
- Create: `app/Services/AuditReport/Scanners/SemgrepScanner.php`
- Create: `resources/semgrep/flexpick/php/sql-interpolation.yaml`
- Create: `resources/semgrep/flexpick/php/missing-authorization.yaml`
- Create: `resources/semgrep/flexpick/js/tls-verify-disabled.yaml`
- Create: `resources/semgrep/flexpick/common/unsafe-deserialize.yaml`
- Create: `resources/semgrep/flexpick/common/debug-in-production.yaml`
- Create: `resources/semgrep/flexpick/common/unbounded-upload.yaml`
- Create: `tests/Feature/Services/Fixtures/Scanners/semgrep.sarif.json`
- Test: `tests/Feature/Services/Scanners/SemgrepScannerTest.php`
- Test: `tests/Feature/Services/Scanners/SemgrepRulesetTest.php`

**Interfaces:**
- Consumes: `SarifNormalizer` (Task 9), `Scanner`, `RepoContext` (Task 8).
- Produces: `SemgrepScanner` implementing `Scanner` with `name() === 'semgrep'`, plus
  `SemgrepScanner::dimensionFor(string $ruleId): ?string` returning the score dimension a
  rule feeds, read from the rule's `metadata.dimension`.

- [ ] **Step 1: Write the ruleset metadata test**

Create `tests/Feature/Services/Scanners/SemgrepRulesetTest.php`. This is the guard that stops
a rule from narrating in the report while silently affecting no score (spec §5.3):

```php
<?php

namespace Tests\Feature\Services\Scanners;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\FeatureTest;

class SemgrepRulesetTest extends FeatureTest
{
    private const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    /** @return list<array{file: string, rule: array<string, mixed>}> */
    private function rules(): array
    {
        $rules = [];

        foreach ((new Finder)->files()->in(resource_path('semgrep/flexpick'))->name('*.yaml') as $file) {
            foreach (Yaml::parseFile($file->getRealPath())['rules'] ?? [] as $rule) {
                $rules[] = ['file' => $file->getRelativePathname(), 'rule' => $rule];
            }
        }

        return $rules;
    }

    public function test_the_ruleset_is_not_empty(): void
    {
        $this->assertNotEmpty($this->rules(), 'No Semgrep rules found — tier 1 would ship with no SAST signal.');
    }

    public function test_every_rule_declares_a_family(): void
    {
        foreach ($this->rules() as $entry) {
            $this->assertArrayHasKey(
                'family',
                $entry['rule']['metadata'] ?? [],
                "Rule {$entry['rule']['id']} in {$entry['file']} declares no metadata.family; it could not be grouped.",
            );
        }
    }

    public function test_every_rule_declares_a_known_dimension(): void
    {
        foreach ($this->rules() as $entry) {
            $dimension = $entry['rule']['metadata']['dimension'] ?? null;

            $this->assertContains(
                $dimension,
                self::DIMENSIONS,
                "Rule {$entry['rule']['id']} in {$entry['file']} declares dimension [{$dimension}]; "
                .'an unmapped rule narrates in the report but affects no score.',
            );
        }
    }

    public function test_rule_ids_are_unique_and_namespaced(): void
    {
        $ids = array_map(fn (array $entry): string => $entry['rule']['id'], $this->rules());

        $this->assertSame(array_unique($ids), $ids, 'Duplicate Semgrep rule id.');

        foreach ($ids as $id) {
            $this->assertStringStartsWith('flexpick.', $id, "Rule id [{$id}] is not namespaced to flexpick.");
        }
    }

    public function test_every_rule_declares_a_severity(): void
    {
        foreach ($this->rules() as $entry) {
            $this->assertContains(
                $entry['rule']['severity'] ?? null,
                ['ERROR', 'WARNING', 'INFO'],
                "Rule {$entry['rule']['id']} declares no valid severity.",
            );
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=SemgrepRulesetTest`
Expected: FAIL — the resource directory does not exist.

- [ ] **Step 3: Write the ruleset**

Create `resources/semgrep/flexpick/php/sql-interpolation.yaml`:

```yaml
rules:
  - id: flexpick.php.sql-interpolation
    languages: [php]
    severity: ERROR
    message: >-
      SQL is assembled by string interpolation. A value reaching this query from
      request input allows an attacker to change the query's meaning.
    metadata:
      family: php.injection
      dimension: security_hygiene
    patterns:
      - pattern-either:
          - pattern: $DB->query("...{$...}...")
          - pattern: $DB->raw("...{$...}...")
          - pattern: DB::select("...{$...}...")
          - pattern: DB::statement("...{$...}...")
```

Create `resources/semgrep/flexpick/php/missing-authorization.yaml`:

```yaml
rules:
  - id: flexpick.php.missing-authorization
    languages: [php]
    severity: ERROR
    message: >-
      A controller action that writes or deletes state performs no authorization
      check. Any authenticated user can invoke it against any record.
    metadata:
      family: php.authorization
      dimension: security_hygiene
    patterns:
      - pattern-either:
          - pattern: |
              public function update(...) { ... }
          - pattern: |
              public function destroy(...) { ... }
      - pattern-not: |
          public function $M(...) { ... $this->authorize(...); ... }
      - pattern-not: |
          public function $M(...) { ... Gate::authorize(...); ... }
      - pattern-not: |
          public function $M(...) { ... $this->authorizeResource(...); ... }
```

Create `resources/semgrep/flexpick/js/tls-verify-disabled.yaml`:

```yaml
rules:
  - id: flexpick.js.tls-verify-disabled
    languages: [javascript, typescript]
    severity: ERROR
    message: >-
      TLS certificate verification is disabled. Traffic that appears encrypted
      can be intercepted and modified without detection.
    metadata:
      family: js.transport-security
      dimension: security_hygiene
    pattern-either:
      - pattern: rejectUnauthorized: false
      - pattern: process.env.NODE_TLS_REJECT_UNAUTHORIZED = "0"
```

Create `resources/semgrep/flexpick/common/unsafe-deserialize.yaml`:

```yaml
rules:
  - id: flexpick.common.unsafe-deserialize
    languages: [php, python]
    severity: ERROR
    message: >-
      Untrusted data is deserialized. Deserializing attacker-controlled input
      can instantiate arbitrary objects and execute code.
    metadata:
      family: common.deserialization
      dimension: security_hygiene
    pattern-either:
      - pattern: unserialize($X)
      - pattern: pickle.loads($X)
      - pattern: yaml.load($X)
```

Create `resources/semgrep/flexpick/common/debug-in-production.yaml`:

```yaml
rules:
  - id: flexpick.common.debug-in-production
    languages: [php]
    severity: WARNING
    message: >-
      Debug mode is enabled unconditionally rather than read from environment
      configuration. In production this exposes stack traces and configuration
      values to end users.
    metadata:
      family: common.configuration
      dimension: structure
    pattern-either:
      - pattern: "'debug' => true"
      - pattern: ini_set('display_errors', 1)
      - pattern: error_reporting(E_ALL)
```

Create `resources/semgrep/flexpick/common/unbounded-upload.yaml`:

```yaml
rules:
  - id: flexpick.common.unbounded-upload
    languages: [php]
    severity: WARNING
    message: >-
      An uploaded file is stored without validating its size or type. This
      permits resource exhaustion and storage of executable content.
    metadata:
      family: common.upload
      dimension: security_hygiene
    patterns:
      - pattern: $REQUEST->file(...)->store(...)
      - pattern-not-inside: |
          $this->validate(...);
          ...
      - pattern-not-inside: |
          $REQUEST->validate(...);
          ...
```

These six are the launch set. Add rules over time; the metadata test makes each addition
safe, and §12 of the spec records the ruleset as the phase's ongoing quality lever.

- [ ] **Step 4: Run the ruleset test**

Run: `php artisan test --filter=SemgrepRulesetTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Create the Semgrep SARIF fixture**

Create `tests/Feature/Services/Fixtures/Scanners/semgrep.sarif.json`:

```json
{
  "version": "2.1.0",
  "runs": [
    {
      "tool": { "driver": { "name": "semgrep", "rules": [
        { "id": "flexpick.php.sql-interpolation",
          "description": { "text": "SQL is assembled by string interpolation." } },
        { "id": "flexpick.common.debug-in-production",
          "description": { "text": "Debug mode is enabled unconditionally." } }
      ] } },
      "results": [
        {
          "ruleId": "flexpick.php.sql-interpolation",
          "level": "error",
          "message": { "text": "SQL is assembled by string interpolation." },
          "locations": [ { "physicalLocation": {
            "artifactLocation": { "uri": "app/Http/Controllers/UserController.php" },
            "region": { "startLine": 42 } } } ]
        },
        {
          "ruleId": "flexpick.common.debug-in-production",
          "level": "warning",
          "message": { "text": "Debug mode is enabled unconditionally." },
          "locations": [ { "physicalLocation": {
            "artifactLocation": { "uri": "config/app.php" },
            "region": { "startLine": 12 } } } ]
        }
      ]
    }
  ]
}
```

- [ ] **Step 6: Write the failing scanner test**

Create `tests/Feature/Services/Scanners/SemgrepScannerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\SemgrepScanner;
use Tests\Feature\FeatureTest;

class SemgrepScannerTest extends FeatureTest
{
    private function normalize(): array
    {
        $sarif = json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/semgrep.sarif.json')),
            true,
        );

        return app(SemgrepScanner::class)->normalize($sarif);
    }

    public function test_normalizes_every_result(): void
    {
        $this->assertCount(2, $this->normalize());
    }

    public function test_maps_sarif_levels_to_severities(): void
    {
        $findings = $this->normalize();

        $this->assertSame(Severity::HIGH, $findings[0]->severity);    // error
        $this->assertSame(Severity::MEDIUM, $findings[1]->severity);  // warning
    }

    public function test_rule_family_comes_from_the_rule_id_namespace(): void
    {
        $this->assertSame('php.injection', $this->normalize()[0]->ruleFamily);
        $this->assertSame('common.configuration', $this->normalize()[1]->ruleFamily);
    }

    public function test_dimension_is_resolved_from_rule_metadata(): void
    {
        $scanner = app(SemgrepScanner::class);

        $this->assertSame('security_hygiene', $scanner->dimensionFor('flexpick.php.sql-interpolation'));
        $this->assertSame('structure', $scanner->dimensionFor('flexpick.common.debug-in-production'));
    }

    public function test_dimension_is_null_for_an_unknown_rule(): void
    {
        $this->assertNull(app(SemgrepScanner::class)->dimensionFor('flexpick.nonexistent.rule'));
    }

    public function test_findings_carry_the_dimension_declared_by_their_rule(): void
    {
        // This is what ScoreCalculator routes on — a structure-tagged rule
        // must not end up scoring security_hygiene (spec §5.3, §7.1).
        $findings = $this->normalize();

        $this->assertSame('security_hygiene', $findings[0]->dimension);
        $this->assertSame('structure', $findings[1]->dimension);
    }

    public function test_message_is_the_rule_description_not_matched_source(): void
    {
        $this->assertSame('SQL is assembled by string interpolation.', $this->normalize()[0]->message);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.semgrep.bin', '/nonexistent/semgrep');

        $this->assertFalse(app(SemgrepScanner::class)->isAvailable());
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `php artisan test --filter=SemgrepScannerTest`
Expected: FAIL — `Class "App\Services\AuditReport\Scanners\SemgrepScanner" not found`.

- [ ] **Step 8: Create the scanner**

Create `app/Services/AuditReport/Scanners/SemgrepScanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use Illuminate\Support\Facades\Process;
use JsonException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Quality and security SAST. The most expensive scanner, so it runs last —
 * an earlier failure loses the least (F5.12.2).
 *
 * Only in-house rules ship (Q33). The Semgrep Registry's rule licence forbids
 * use in a competing commercial product; the LGPL-2.1 engine is merely invoked.
 */
class SemgrepScanner implements Scanner
{
    /** @var array<string, array{family: string, dimension: string}>|null */
    private ?array $ruleMetadata = null;

    public function __construct(private SarifNormalizer $normalizer) {}

    public function name(): string
    {
        return 'semgrep';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.semgrep.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.semgrep.version');
    }

    public function scan(RepoContext $context): array
    {
        $report = tempnam(sys_get_temp_dir(), 'semgrep-').'.sarif';

        try {
            Process::timeout((int) config('audit.scanners.semgrep.timeout'))
                ->run([
                    (string) config('audit.scanners.semgrep.bin'),
                    'scan',
                    // Only our rules. Never a registry identifier — that would
                    // both fetch over the network and import licensed rules.
                    '--config', (string) config('audit.scanners.semgrep.rules_path'),
                    '--sarif',
                    '--output', $report,
                    '--metrics=off',
                    '--disable-version-check',
                    // Repo-supplied .semgrepignore must not steer the scan (spec §5.4).
                    '--no-git-ignore',
                    $context->path,
                ]);

            return $this->normalize($this->decode($report));
        } finally {
            @unlink($report);
        }
    }

    /** @return list<\App\Services\AuditReport\Findings\Finding> */
    public function normalize(array $sarif): array
    {
        return $this->normalizer->normalize(
            $sarif,
            $this->name(),
            fn (array $result): Severity => match ($result['level'] ?? 'warning') {
                'error' => Severity::HIGH,
                'note' => Severity::LOW,
                default => Severity::MEDIUM,
            },
            fn (array $result, string $ruleId): string => $this->familyFor($ruleId),
            fn (string $ruleId): string => $this->dimensionFor($ruleId) ?? 'security_hygiene',
        );
    }

    /**
     * The score dimension a rule feeds, from its metadata.dimension (spec §5.3).
     * This is the ONLY rule-to-dimension mapping in the system: ScoreCalculator
     * routes on the dimension the finding carries, never on which tool found it.
     */
    public function dimensionFor(string $ruleId): ?string
    {
        return $this->metadata()[$ruleId]['dimension'] ?? null;
    }

    private function familyFor(string $ruleId): string
    {
        return $this->metadata()[$ruleId]['family']
            // Fall back to the id's namespace: flexpick.php.sql-interpolation → php.injection
            // is unavailable, so use the middle segments verbatim.
            ?? implode('.', array_slice(explode('.', $ruleId), 1, 1)) ?: 'semgrep.other';
    }

    /** @return array<string, array{family: string, dimension: string}> */
    private function metadata(): array
    {
        if ($this->ruleMetadata !== null) {
            return $this->ruleMetadata;
        }

        $metadata = [];
        $rulesPath = (string) config('audit.scanners.semgrep.rules_path');

        if (is_dir($rulesPath)) {
            foreach ((new Finder)->files()->in($rulesPath)->name('*.yaml') as $file) {
                foreach (Yaml::parseFile($file->getRealPath())['rules'] ?? [] as $rule) {
                    $metadata[(string) $rule['id']] = [
                        'family' => (string) ($rule['metadata']['family'] ?? 'semgrep.other'),
                        'dimension' => (string) ($rule['metadata']['dimension'] ?? 'structure'),
                    ];
                }
            }
        }

        return $this->ruleMetadata = $metadata;
    }
}
```

- [ ] **Step 9: Register the binding and config**

In `app/Providers/AppServiceProvider.php`:

```php
$this->app->bind('audit.scanner.semgrep', SemgrepScanner::class);
```

In `config/audit.php`, extend the `scanners` block:

```php
'semgrep' => [
    'bin' => env('AUDIT_SEMGREP_BIN', '/opt/flexpick/bin/semgrep'),
    'version' => '1.99.0',
    'timeout' => 300,
    'rules_path' => resource_path('semgrep/flexpick'),
],
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test --filter=Semgrep`
Expected: PASS — 5 ruleset tests plus 7 scanner tests.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Scanners/SemgrepScanner.php app/Providers/AppServiceProvider.php config/audit.php resources/semgrep/ tests/Feature/Services/
git commit -m "feat(audit): add the Semgrep scanner with an in-house ruleset (Q33)"
```

---

### Task 11: scc scanner and the file inventory

**Files:**
- Create: `app/Services/AuditReport/Findings/Normalizers/SccNormalizer.php`
- Create: `app/Services/AuditReport/Scanners/SccScanner.php`
- Create: `tests/Feature/Services/Fixtures/Scanners/scc.json`
- Test: `tests/Feature/Services/Scanners/SccScannerTest.php`

**Interfaces:**
- Consumes: `Scanner`, `RepoContext`, `SccInventory` (Task 8).
- Produces: `SccScanner` implementing `Scanner` with `name() === 'scc'`. It produces **no
  findings** — it returns `[]` and populates `RepoContext::$inventory` as a side effect, which
  every later scanner and the excerpt collector read. Also
  `SccScanner::fallbackInventory(string $path): SccInventory` for the degrade path (spec §10).

- [ ] **Step 1: Create the fixture**

Create `tests/Feature/Services/Fixtures/Scanners/scc.json` — scc's `--by-file` JSON shape:

```json
[
  {
    "Name": "PHP",
    "Files": [
      { "Location": "app/Http/Controllers/UserController.php", "Lines": 420, "Code": 350, "Complexity": 48 },
      { "Location": "app/Models/User.php", "Lines": 180, "Code": 140, "Complexity": 12 }
    ]
  },
  {
    "Name": "JavaScript",
    "Files": [
      { "Location": "resources/js/app.js", "Lines": 90, "Code": 70, "Complexity": 4 }
    ]
  }
]
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Services/Scanners/SccScannerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class SccScannerTest extends FeatureTest
{
    private function inventory(): \App\Services\AuditReport\Scanners\SccInventory
    {
        $raw = json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/scc.json')),
            true,
        );

        return app(SccScanner::class)->toInventory($raw);
    }

    public function test_produces_no_findings(): void
    {
        // scc measures; it does not find defects. Its output sizes the budgets
        // for every later stage (F5.12.2).
        $context = new RepoContext(
            path: base_path('tests/Feature/Services/Fixtures/Scanners'),
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );

        $this->assertSame([], app(SccScanner::class)->normalize([]));
        $this->assertNull($context->inventory);
    }

    public function test_inventory_lists_files_descending_by_lines(): void
    {
        $files = $this->inventory()->files;

        $this->assertSame('app/Http/Controllers/UserController.php', $files[0]['path']);
        $this->assertSame(420, $files[0]['loc']);
        $this->assertSame(48, $files[0]['complexity']);
        $this->assertSame('resources/js/app.js', $files[2]['path']);
    }

    public function test_inventory_aggregates_languages(): void
    {
        $languages = $this->inventory()->languages;

        $this->assertSame(['files' => 2, 'loc' => 600], $languages['PHP']);
        $this->assertSame(['files' => 1, 'loc' => 90], $languages['JavaScript']);
    }

    public function test_inventory_totals_lines_and_complexity(): void
    {
        $inventory = $this->inventory();

        $this->assertSame(690, $inventory->totalLoc);
        $this->assertSame(64, $inventory->totalComplexity);
    }

    public function test_paths_returns_a_capped_ordered_list(): void
    {
        $this->assertSame(
            ['app/Http/Controllers/UserController.php', 'app/Models/User.php'],
            $this->inventory()->paths(2),
        );
    }

    public function test_fallback_inventory_walks_the_tree_when_scc_is_unavailable(): void
    {
        // Spec §10: scc failing must not leave later stages without a basis.
        $inventory = app(SccScanner::class)->fallbackInventory(base_path('app/Services/AuditReport'));

        $this->assertNotEmpty($inventory->files);
        $this->assertGreaterThan(0, $inventory->totalLoc);
        $this->assertSame(0, $inventory->totalComplexity);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.scc.bin', '/nonexistent/scc');

        $this->assertFalse(app(SccScanner::class)->isAvailable());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=SccScannerTest`
Expected: FAIL — `Class "App\Services\AuditReport\Scanners\SccScanner" not found`.

- [ ] **Step 4: Create the scanner**

Create `app/Services/AuditReport/Scanners/SccScanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/**
 * Size, language breakdown, and per-file complexity.
 *
 * Always first: its inventory sizes the budgets for everything after
 * (F5.12.2). It produces no findings — it populates RepoContext::$inventory
 * as its output.
 */
class SccScanner implements Scanner
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', 'dist', 'build', '.git', 'storage', '.next', 'coverage'];

    public function name(): string
    {
        return 'scc';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.scc.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.scc.version');
    }

    public function scan(RepoContext $context): array
    {
        $result = Process::timeout((int) config('audit.scanners.scc.timeout'))
            ->run([
                (string) config('audit.scanners.scc.bin'),
                '--format', 'json',
                '--by-file',
                '--exclude-dir', implode(',', self::EXCLUDED_DIRS),
                $context->path,
            ]);

        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        $context->withInventory($this->toInventory(is_array($decoded) ? $decoded : []));

        return $this->normalize($decoded);
    }

    /** scc measures; it never reports a defect. @return list<never> */
    public function normalize(mixed $raw): array
    {
        return [];
    }

    /** @param array<int, array<string, mixed>> $raw */
    public function toInventory(array $raw): SccInventory
    {
        $files = [];
        $languages = [];
        $totalComplexity = 0;

        foreach ($raw as $language) {
            $name = (string) ($language['Name'] ?? 'Unknown');

            foreach ($language['Files'] ?? [] as $file) {
                $loc = (int) ($file['Lines'] ?? 0);
                $complexity = (int) ($file['Complexity'] ?? 0);

                $files[] = [
                    'path' => ltrim((string) $file['Location'], './'),
                    'loc' => $loc,
                    'complexity' => $complexity,
                ];

                $languages[$name]['files'] = ($languages[$name]['files'] ?? 0) + 1;
                $languages[$name]['loc'] = ($languages[$name]['loc'] ?? 0) + $loc;
                $totalComplexity += $complexity;
            }
        }

        // Total order — descending loc, then path — so repeat runs select the
        // same excerpts and cite the same files (spec §6.3).
        usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        ksort($languages);

        return new SccInventory(
            files: $files,
            languages: $languages,
            totalLoc: array_sum(array_column($files, 'loc')),
            totalComplexity: $totalComplexity,
        );
    }

    /**
     * Used when scc failed or is unavailable. Complexity is unavailable from a
     * plain walk, so it is zero — and the dimensions that depend on it are
     * marked not-measured rather than scored (spec §7.2, §10).
     */
    public function fallbackInventory(string $repoPath): SccInventory
    {
        $files = [];
        $languages = [];

        $finder = (new Finder)->files()->in($repoPath)->exclude(self::EXCLUDED_DIRS)->size('< 2M');

        foreach ($finder as $file) {
            $loc = substr_count($file->getContents(), "\n") + 1;
            $extension = strtolower($file->getExtension()) ?: 'unknown';

            $files[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'complexity' => 0];
            $languages[$extension]['files'] = ($languages[$extension]['files'] ?? 0) + 1;
            $languages[$extension]['loc'] = ($languages[$extension]['loc'] ?? 0) + $loc;
        }

        usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        ksort($languages);

        return new SccInventory(
            files: $files,
            languages: $languages,
            totalLoc: array_sum(array_column($files, 'loc')),
            totalComplexity: 0,
        );
    }
}
```

- [ ] **Step 5: Register the binding and config**

In `app/Providers/AppServiceProvider.php`:

```php
$this->app->bind('audit.scanner.scc', SccScanner::class);
```

In `config/audit.php`, extend `scanners`:

```php
'scc' => [
    'bin' => env('AUDIT_SCC_BIN', '/opt/flexpick/bin/scc'),
    'version' => '3.5.0',
    'timeout' => 60,
],
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=SccScannerTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Scanners/SccScanner.php app/Providers/AppServiceProvider.php config/audit.php tests/Feature/Services/
git commit -m "feat(audit): add the scc scanner and repository file inventory"
```

---

### Task 12: jscpd scanner and clone-pair normalization

**Files:**
- Create: `app/Services/AuditReport/Findings/Normalizers/JscpdNormalizer.php`
- Create: `app/Services/AuditReport/Scanners/JscpdScanner.php`
- Create: `tests/Feature/Services/Fixtures/Scanners/jscpd.json`
- Test: `tests/Feature/Services/Scanners/JscpdScannerTest.php`

**Interfaces:**
- Consumes: `Scanner`, `RepoContext`, `SccInventory` (Tasks 8, 11).
- Produces: `JscpdScanner` implementing `Scanner` with `name() === 'jscpd'`, plus
  `JscpdScanner::duplicationPercentage(array $raw): float` — the figure `ScoreCalculator`
  v2 uses for the `duplication` dimension (Task 17).

- [ ] **Step 1: Create the fixture**

Create `tests/Feature/Services/Fixtures/Scanners/jscpd.json` — jscpd's JSON report shape.
Note each clone carries **two** locations:

```json
{
  "statistics": {
    "total": { "lines": 10000, "duplicatedLines": 1250, "percentage": 12.5 }
  },
  "duplicates": [
    {
      "format": "php",
      "lines": 40,
      "firstFile":  { "name": "app/Http/Controllers/OrderController.php", "start": 12, "end": 52 },
      "secondFile": { "name": "app/Http/Controllers/InvoiceController.php", "start": 30, "end": 70 }
    },
    {
      "format": "php",
      "lines": 25,
      "firstFile":  { "name": "app/Services/Billing.php", "start": 5, "end": 30 },
      "secondFile": { "name": "app/Services/Refunds.php", "start": 8, "end": 33 }
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Services/Scanners/JscpdScannerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\JscpdScanner;
use Tests\Feature\FeatureTest;

class JscpdScannerTest extends FeatureTest
{
    private function raw(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/jscpd.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(JscpdScanner::class)->normalize($this->raw());
    }

    public function test_emits_one_finding_per_occurrence_not_per_pair(): void
    {
        // Two clone pairs, four occurrences. A block duplicated into four
        // directories must group in all four (spec §6.1).
        $this->assertCount(4, $this->normalize());
    }

    public function test_each_occurrence_carries_its_own_file_and_start_line(): void
    {
        $paths = array_map(fn ($f) => $f->path.':'.$f->line, $this->normalize());

        $this->assertContains('app/Http/Controllers/OrderController.php:12', $paths);
        $this->assertContains('app/Http/Controllers/InvoiceController.php:30', $paths);
        $this->assertContains('app/Services/Billing.php:5', $paths);
        $this->assertContains('app/Services/Refunds.php:8', $paths);
    }

    public function test_all_duplication_findings_share_one_rule_family(): void
    {
        foreach ($this->normalize() as $finding) {
            $this->assertSame('duplication.clone', $finding->ruleFamily);
        }
    }

    public function test_severity_is_medium(): void
    {
        $this->assertSame(Severity::MEDIUM, $this->normalize()[0]->severity);
    }

    public function test_message_names_the_duplicated_line_count_and_no_source(): void
    {
        $message = $this->normalize()[0]->message;

        $this->assertStringContainsString('40', $message);
        $this->assertStringNotContainsString('OrderController', $message);
    }

    public function test_extracts_the_duplication_percentage_for_scoring(): void
    {
        $this->assertSame(12.5, app(JscpdScanner::class)->duplicationPercentage($this->raw()));
    }

    public function test_duplication_percentage_defaults_to_zero_when_absent(): void
    {
        $this->assertSame(0.0, app(JscpdScanner::class)->duplicationPercentage([]));
    }

    public function test_the_scanner_holds_no_per_run_state(): void
    {
        // Scanners outlive a run inside a Horizon worker. Any per-run value
        // must travel on RepoContext, never on the scanner instance.
        $properties = (new \ReflectionClass(JscpdScanner::class))->getProperties();

        $this->assertSame(
            [],
            array_map(fn (\ReflectionProperty $p): string => $p->getName(), $properties),
            'JscpdScanner declares instance state; record it on RepoContext instead.',
        );
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.jscpd.bin', '/nonexistent/jscpd');

        $this->assertFalse(app(JscpdScanner::class)->isAvailable());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=JscpdScannerTest`
Expected: FAIL — `Class "App\Services\AuditReport\Scanners\JscpdScanner" not found`.

- [ ] **Step 4: Create the scanner**

Create `app/Services/AuditReport/Scanners/JscpdScanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use Illuminate\Support\Facades\Process;

/**
 * Cross-language duplication. Supersedes the md5 line-hash heuristic that
 * MetricsCollector carried (F5.12.2).
 */
class JscpdScanner implements Scanner
{
    public function name(): string
    {
        return 'jscpd';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.jscpd.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.jscpd.version');
    }

    public function scan(RepoContext $context): array
    {
        $outputDir = sys_get_temp_dir().'/jscpd-'.bin2hex(random_bytes(8));
        mkdir($outputDir, 0755, true);

        try {
            Process::timeout((int) config('audit.scanners.jscpd.timeout'))
                ->run([
                    (string) config('audit.scanners.jscpd.bin'),
                    $context->path,
                    '--reporters', 'json',
                    '--output', $outputDir,
                    '--silent',
                    // Repo-supplied .jscpd.json must not steer the scan (spec §5.4).
                    '--config', (string) config('audit.scanners.jscpd.config'),
                    '--max-size', (string) config('audit.scanners.jscpd.max_file_size'),
                ]);

            $report = $outputDir.'/jscpd-report.json';

            if (! file_exists($report)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $decoded = is_array($decoded) ? $decoded : [];

            // Recorded on the per-run context, never on $this — the scanner
            // instance outlives the run inside a Horizon worker.
            $context->record('duplication_pct', $this->duplicationPercentage($decoded));

            return $this->normalize($decoded);
        } finally {
            $this->deleteDirectory($outputDir);
        }
    }

    /**
     * One Finding per occurrence, not per pair — a block copied into four
     * directories must produce findings in all four so it groups where a
     * reader would look for it (spec §6.1).
     *
     * @return list<Finding>
     */
    public function normalize(array $raw): array
    {
        $findings = [];

        foreach ($raw['duplicates'] ?? [] as $duplicate) {
            $lines = (int) ($duplicate['lines'] ?? 0);

            foreach (['firstFile', 'secondFile'] as $side) {
                $file = $duplicate[$side] ?? null;

                if (! is_array($file) || ! isset($file['name'])) {
                    continue;
                }

                $findings[] = new Finding(
                    tool: $this->name(),
                    ruleId: 'jscpd.clone',
                    ruleFamily: 'duplication.clone',
                    severity: Severity::MEDIUM,
                    path: ltrim((string) $file['name'], './'),
                    line: (int) ($file['start'] ?? 0) ?: null,
                    message: "A block of {$lines} lines is duplicated elsewhere in the repository.",
                    dimension: 'duplication',
                );
            }
        }

        return $findings;
    }

    /** The repository-wide duplication percentage, for the duplication score. */
    public function duplicationPercentage(array $raw): float
    {
        return round((float) ($raw['statistics']['total']['percentage'] ?? 0.0), 1);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : @unlink($file);
        }

        @rmdir($directory);
    }
}
```

- [ ] **Step 5: Register the binding, config, and jscpd config file**

In `app/Providers/AppServiceProvider.php`:

```php
$this->app->bind('audit.scanner.jscpd', JscpdScanner::class);
```

A plain `bind` is correct because scanners hold **no per-run state** — the duplication
percentage travels on `RepoContext` (Task 8), which is constructed once per run and
discarded with it. Keeping scanners stateless is what makes that safe inside a long-lived
Horizon worker.

In `config/audit.php`, extend `scanners`:

```php
'jscpd' => [
    'bin' => env('AUDIT_JSCPD_BIN', '/opt/flexpick/bin/jscpd'),
    'version' => '4.0.5',
    'timeout' => 180,
    'config' => resource_path('scanners/jscpd.json'),
    'max_file_size' => '2mb',
],
```

Create `resources/scanners/jscpd.json`:

```json
{
  "minLines": 10,
  "minTokens": 70,
  "ignore": [
    "**/vendor/**", "**/node_modules/**", "**/dist/**", "**/build/**",
    "**/.git/**", "**/storage/**", "**/coverage/**", "**/*.min.js"
  ],
  "absolute": false,
  "gitignore": false
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=JscpdScannerTest`
Expected: PASS, 8 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Scanners/JscpdScanner.php app/Providers/AppServiceProvider.php config/audit.php resources/scanners/jscpd.json tests/Feature/Services/
git commit -m "feat(audit): add the jscpd duplication scanner"
```

---

### Task 13: OSV scanner adapter

The existing `DependencyAuditor` already queries OSV and degrades to zero. This task adapts
it to the `Scanner` interface so dependency vulnerabilities flow into the same findings model
as everything else. `DependencyAuditor` itself is **not** rewritten.

**Files:**
- Create: `app/Services/AuditReport/Findings/Normalizers/OsvNormalizer.php`
- Create: `app/Services/AuditReport/Scanners/OsvScanner.php`
- Test: `tests/Feature/Services/Scanners/OsvScannerTest.php`

**Interfaces:**
- Consumes: `DependencyAuditor` (existing), `Scanner`, `RepoContext` (Task 8).
- Produces: `OsvScanner` implementing `Scanner` with `name() === 'osv'`, and
  `OsvScanner::normalize(array $audit): array`.

- [ ] **Step 1: Confirm the DependencyAuditor output shape**

Run: `php artisan test --filter=DependencyAuditorTest`
Read `tests/Feature/Services/DependencyAuditorTest.php` and
`app/Services/AuditReport/DependencyAuditor.php` to confirm the exact array shape `audit()`
returns. The plan below assumes:

```php
[
    'packages_scanned' => int,
    'vulnerable_count' => int,
    'vulnerabilities' => [
        ['package' => string, 'version' => string, 'id' => string,
         'severity' => ?string, 'summary' => string, 'manifest' => string],
    ],
    // or: ['error' => string] when the query failed
]
```

If the real shape differs — particularly the per-vulnerability keys and whether `manifest` is
present — adjust the normalizer in Step 3 to match, and adjust the test fixture in Step 2 to
the real shape. Do not change `DependencyAuditor`.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Services/Scanners/OsvScannerTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\OsvScanner;
use Tests\Feature\FeatureTest;

class OsvScannerTest extends FeatureTest
{
    private function audit(): array
    {
        return [
            'packages_scanned' => 120,
            'vulnerable_count' => 3,
            'vulnerabilities' => [
                ['package' => 'acme/parser', 'version' => '1.2.0', 'id' => 'GHSA-aaaa',
                 'severity' => '9.8', 'summary' => 'Remote code execution in the parser.',
                 'manifest' => 'composer.lock'],
                ['package' => 'left-pad', 'version' => '0.1.0', 'id' => 'GHSA-bbbb',
                 'severity' => '5.3', 'summary' => 'Denial of service on long input.',
                 'manifest' => 'package-lock.json'],
                ['package' => 'mystery/lib', 'version' => '2.0.0', 'id' => 'GHSA-cccc',
                 'severity' => null, 'summary' => 'Unspecified issue.',
                 'manifest' => 'composer.lock'],
            ],
        ];
    }

    private function normalize(): array
    {
        return app(OsvScanner::class)->normalize($this->audit());
    }

    public function test_emits_one_finding_per_vulnerability(): void
    {
        $this->assertCount(3, $this->normalize());
    }

    public function test_maps_cvss_to_severity_bands(): void
    {
        $findings = $this->normalize();

        $this->assertSame(Severity::CRITICAL, $findings[0]->severity);  // 9.8
        $this->assertSame(Severity::MEDIUM, $findings[1]->severity);    // 5.3
    }

    public function test_missing_cvss_falls_back_to_medium(): void
    {
        $this->assertSame(Severity::MEDIUM, $this->normalize()[2]->severity);
    }

    public function test_path_is_the_declaring_manifest_and_line_is_null(): void
    {
        // OSV findings are manifest-level; they have no source location.
        // They group under dependencies × the manifest's directory (spec §6.1).
        $finding = $this->normalize()[0];

        $this->assertSame('composer.lock', $finding->path);
        $this->assertNull($finding->line);
    }

    public function test_rule_family_is_the_dependency_family(): void
    {
        $this->assertSame('dependencies.vulnerable', $this->normalize()[0]->ruleFamily);
    }

    public function test_message_names_the_package_and_advisory(): void
    {
        $message = $this->normalize()[0]->message;

        $this->assertStringContainsString('acme/parser', $message);
        $this->assertStringContainsString('GHSA-aaaa', $message);
    }

    public function test_an_errored_audit_yields_no_findings(): void
    {
        // Existing degrade-to-zero behaviour is retained as-is (F5.12.2).
        $this->assertSame([], app(OsvScanner::class)->normalize(['error' => 'osv unreachable']));
    }

    public function test_is_always_available_because_it_needs_no_binary(): void
    {
        $this->assertTrue(app(OsvScanner::class)->isAvailable());
    }
}
```

- [ ] **Step 3: Create the scanner**

Create `app/Services/AuditReport/Scanners/OsvScanner.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\DependencyAuditor;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;

/**
 * Adapts the existing DependencyAuditor to the Scanner interface so dependency
 * vulnerabilities flow into the same findings model as everything else.
 *
 * The auditor itself is retained unchanged, including its degrade-to-zero
 * behaviour on an unreachable OSV endpoint (F5.12.2).
 */
class OsvScanner implements Scanner
{
    public function __construct(private DependencyAuditor $auditor) {}

    public function name(): string
    {
        return 'osv';
    }

    /** No binary to provision — the auditor speaks HTTP. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function version(): string
    {
        return 'querybatch';
    }

    public function scan(RepoContext $context): array
    {
        $audit = $this->auditor->audit($context->path);

        // Scanners stay stateless — anything the pipeline needs later goes on
        // the per-run context (Task 8), never on the instance.
        $context->record('packages_scanned', (int) ($audit['packages_scanned'] ?? 0));

        return $this->normalize($audit);
    }

    /** @return list<Finding> */
    public function normalize(array $audit): array
    {
        if (isset($audit['error'])) {
            return [];
        }

        $findings = [];

        foreach ($audit['vulnerabilities'] ?? [] as $vulnerability) {
            $package = (string) ($vulnerability['package'] ?? 'unknown');
            $id = (string) ($vulnerability['id'] ?? 'unknown');

            $findings[] = new Finding(
                tool: $this->name(),
                ruleId: 'osv.'.$id,
                ruleFamily: 'dependencies.vulnerable',
                severity: $this->severityFor($vulnerability['severity'] ?? null),
                path: (string) ($vulnerability['manifest'] ?? 'composer.lock'),
                line: null,
                message: "{$package} {$vulnerability['version']} is affected by {$id}: "
                    .($vulnerability['summary'] ?? 'no summary provided.'),
                dimension: 'dependencies',
            );
        }

        return $findings;
    }

    private function severityFor(mixed $cvss): Severity
    {
        if (! is_numeric($cvss)) {
            return Severity::MEDIUM;
        }

        return match (true) {
            (float) $cvss >= 9.0 => Severity::CRITICAL,
            (float) $cvss >= 7.0 => Severity::HIGH,
            (float) $cvss >= 4.0 => Severity::MEDIUM,
            default => Severity::LOW,
        };
    }
}
```

- [ ] **Step 4: Register the binding**

In `app/Providers/AppServiceProvider.php`:

```php
$this->app->bind('audit.scanner.osv', OsvScanner::class);
```

No config block — OSV keeps the existing `audit.osv_endpoint` key that `DependencyAuditor`
already reads.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=OsvScannerTest`
Expected: PASS, 8 tests.

Run: `php artisan test --filter=DependencyAuditorTest`
Expected: PASS — the auditor is untouched.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/Scanners/OsvScanner.php app/Providers/AppServiceProvider.php tests/Feature/Services/
git commit -m "feat(audit): adapt the OSV dependency auditor to the scanner interface"
```

---

### Task 14: Provisioning, pins, and repo-config isolation

**Files:**
- Modify: `deploy.php` (add `provision:scanners`)
- Modify: `docker/8.4/Dockerfile` (same pins)
- Modify: `.env.example` (scanner bin overrides)
- Test: `tests/Feature/Services/Scanners/ScannerConfigTest.php`
- Test: `tests/Feature/Services/Scanners/RepoConfigIsolationTest.php`

**Interfaces:**
- Consumes: every scanner from Tasks 9–13.
- Produces: `/opt/flexpick/bin/{scc,gitleaks,jscpd,semgrep}` on provisioned hosts and in the
  dev container. No new PHP interfaces.

- [ ] **Step 1: Write the config completeness test**

Create `tests/Feature/Services/Scanners/ScannerConfigTest.php`:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class ScannerConfigTest extends FeatureTest
{
    public function test_every_scanner_named_by_a_tier_is_resolvable(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach (AuditTier::cases() as $tier) {
            foreach ($resolver->for($tier)->scanners as $name) {
                $scanner = app('audit.scanner.'.$name);

                $this->assertSame($name, $scanner->name(), "Binding audit.scanner.{$name} resolved the wrong scanner.");
            }
        }
    }

    public function test_every_binary_backed_scanner_declares_a_pinned_version(): void
    {
        foreach (['scc', 'gitleaks', 'jscpd', 'semgrep'] as $name) {
            $version = config("audit.scanners.{$name}.version");

            $this->assertIsString($version);
            $this->assertNotSame('', $version, "Scanner [{$name}] has no pinned version; a silent upgrade would change findings.");
        }
    }

    public function test_every_binary_backed_scanner_declares_a_timeout(): void
    {
        foreach (['scc', 'gitleaks', 'jscpd', 'semgrep'] as $name) {
            $this->assertGreaterThan(0, (int) config("audit.scanners.{$name}.timeout"));
        }
    }

    public function test_semgrep_is_configured_against_the_in_house_rules_only(): void
    {
        $path = (string) config('audit.scanners.semgrep.rules_path');

        // A registry identifier here would fetch over the network and import
        // rules whose licence forbids commercial use (Q33).
        $this->assertDirectoryExists($path);
        $this->assertStringStartsWith(resource_path('semgrep'), $path);
    }
}
```

- [ ] **Step 2: Write the repo-config isolation test**

Create `tests/Feature/Services/Scanners/RepoConfigIsolationTest.php`. This pins spec §5.4 —
a repository being audited must not be able to suppress its own findings:

```php
<?php

namespace Tests\Feature\Services\Scanners;

use Tests\Feature\FeatureTest;

class RepoConfigIsolationTest extends FeatureTest
{
    public function test_gitleaks_is_invoked_with_an_explicit_config_path(): void
    {
        $config = (string) config('audit.scanners.gitleaks.config');

        $this->assertFileExists($config);
        $this->assertStringStartsWith(resource_path('scanners'), $config);
    }

    public function test_jscpd_is_invoked_with_an_explicit_config_path(): void
    {
        $config = (string) config('audit.scanners.jscpd.config');

        $this->assertFileExists($config);
        $this->assertStringStartsWith(resource_path('scanners'), $config);
    }

    public function test_jscpd_config_disables_repository_gitignore_handling(): void
    {
        $config = json_decode((string) file_get_contents((string) config('audit.scanners.jscpd.config')), true);

        $this->assertFalse($config['gitignore']);
    }

    public function test_scanner_invocations_never_reference_repository_local_config(): void
    {
        // A repository under audit has an obvious motive to suppress its own
        // findings. Every scanner must be pointed at our config, never the
        // clone's (spec §5.4).
        $sources = [
            file_get_contents(app_path('Services/AuditReport/Scanners/GitleaksScanner.php')),
            file_get_contents(app_path('Services/AuditReport/Scanners/JscpdScanner.php')),
            file_get_contents(app_path('Services/AuditReport/Scanners/SemgrepScanner.php')),
        ];

        foreach ($sources as $source) {
            $this->assertStringNotContainsString('$context->path.\'/.gitleaks', $source);
            $this->assertStringNotContainsString('$context->path.\'/.jscpd', $source);
            $this->assertStringNotContainsString('$context->path.\'/.semgrep', $source);
        }

        $this->assertStringContainsString('--no-git-ignore', $sources[2]);
    }
}
```

- [ ] **Step 3: Run both to verify they fail**

Run: `php artisan test --filter=ScannerConfigTest`
Expected: FAIL — `audit.scanners.scc.version` may be present from Task 11 but jscpd/semgrep
keys will be incomplete until every earlier task landed. If all of Tasks 9–13 are done, this
may already pass; that is fine, it is a regression guard.

Run: `php artisan test --filter=RepoConfigIsolationTest`
Expected: PASS if Tasks 9–13 were implemented as written; FAIL identifies a scanner still
reading repo-local config.

- [ ] **Step 4: Add the Deployer provisioning task**

In `deploy.php`, after the `provision:php-extra` task, add:

```php
desc('Provision the audit scanner binaries');
task('provision:scanners', function () {
    $binDir = '/opt/flexpick/bin';

    $scc = '3.5.0';
    $gitleaks = '8.28.0';
    $jscpd = '4.0.5';
    $semgrep = '1.99.0';

    info('Installing audit scanners to '.$binDir);

    run("mkdir -p $binDir");

    // scc — single static Go binary
    run("curl -sSfL https://github.com/boyter/scc/releases/download/v$scc/scc_Linux_x86_64.tar.gz "
        ."| tar -xz -C $binDir scc && chmod +x $binDir/scc");

    // Gitleaks — single static Go binary
    run("curl -sSfL https://github.com/gitleaks/gitleaks/releases/download/v$gitleaks/gitleaks_${gitleaks}_linux_x64.tar.gz "
        ."| tar -xz -C $binDir gitleaks && chmod +x $binDir/gitleaks");

    // jscpd — npm package; Node is already provisioned via fnm
    run("eval \"\$(fnm env)\" && npm install -g jscpd@$jscpd");
    run("ln -sf \$(eval \"\$(fnm env)\" && which jscpd) $binDir/jscpd");

    // Semgrep — Python package; the only scanner needing a Python runtime
    run('apt-get install -y python3 python3-venv', env: ['DEBIAN_FRONTEND' => 'noninteractive']);
    run("python3 -m venv /opt/flexpick/semgrep-venv");
    run("/opt/flexpick/semgrep-venv/bin/pip install --quiet semgrep==$semgrep");
    run("ln -sf /opt/flexpick/semgrep-venv/bin/semgrep $binDir/semgrep");
})->verbose()->limit(1);

after('provision:php-extra', 'provision:scanners');
```

Versions here must match `config/audit.php` exactly. `ScannerConfigTest` asserts the config
side; the smoke gate in Task 24 asserts the host side matches at runtime.

- [ ] **Step 5: Mirror the pins in the dev container**

In `docker/8.4/Dockerfile`, before the final `USER` directive, add:

```dockerfile
# Audit scanners — versions must match config/audit.php and deploy.php.
ARG SCC_VERSION=3.5.0
ARG GITLEAKS_VERSION=8.28.0
ARG JSCPD_VERSION=4.0.5
ARG SEMGREP_VERSION=1.99.0

RUN mkdir -p /opt/flexpick/bin \
    && curl -sSfL "https://github.com/boyter/scc/releases/download/v${SCC_VERSION}/scc_Linux_x86_64.tar.gz" \
       | tar -xz -C /opt/flexpick/bin scc \
    && curl -sSfL "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz" \
       | tar -xz -C /opt/flexpick/bin gitleaks \
    && chmod +x /opt/flexpick/bin/scc /opt/flexpick/bin/gitleaks \
    && npm install -g "jscpd@${JSCPD_VERSION}" \
    && ln -sf "$(which jscpd)" /opt/flexpick/bin/jscpd \
    && python3 -m venv /opt/flexpick/semgrep-venv \
    && /opt/flexpick/semgrep-venv/bin/pip install --quiet "semgrep==${SEMGREP_VERSION}" \
    && ln -sf /opt/flexpick/semgrep-venv/bin/semgrep /opt/flexpick/bin/semgrep
```

If `python3` and `curl` are not already installed in that image, add them to the existing
`apt-get install` layer rather than adding a second one.

- [ ] **Step 6: Document the overrides**

In `.env.example`, after the existing `AUDIT_*` keys, add:

```
# Audit scanner binaries. Defaults assume the provisioned /opt/flexpick/bin
# layout; override only when running scanners from a different location.
AUDIT_SCC_BIN=
AUDIT_GITLEAKS_BIN=
AUDIT_JSCPD_BIN=
AUDIT_SEMGREP_BIN=
```

- [ ] **Step 7: Verify the binaries actually install in the dev container**

```bash
docker compose build laravel.test
docker compose up -d
docker compose exec laravel.test /opt/flexpick/bin/scc --version
docker compose exec laravel.test /opt/flexpick/bin/gitleaks version
docker compose exec laravel.test /opt/flexpick/bin/jscpd --version
docker compose exec laravel.test /opt/flexpick/bin/semgrep --version
```

Expected: each prints a version matching the pin in `config/audit.php`. A mismatch here is
the drift the smoke gate in Task 24 exists to catch — fix the pin, do not adjust the test.

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter=ScannerConfigTest`
Expected: PASS, 4 tests.

Run: `php artisan test --filter=RepoConfigIsolationTest`
Expected: PASS, 4 tests.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add deploy.php docker/8.4/Dockerfile .env.example tests/Feature/Services/Scanners/
git commit -m "build(audit): provision pinned scanner binaries for the host and dev container"
```

---

### Task 15: Extract the collectors

`MetricsCollector`'s eight responsibilities become five collectors plus a composer. This is
**not** behaviour-preserving: the superseded keys (`secret_findings`, `duplication_pct` from
the md5 heuristic, extension-based `languages`) disappear with the code that produced them,
and the surviving non-scanner signals (`has_ci`, `has_readme`, test counting) move into
`ToolingCollector` so the metrics array is complete when this task ends.

Task 16 then adds the structural guards that stop the deleted heuristics coming back, and
rewrites the two existing collector tests. Split this way, Task 15 is the restructure and
Task 16 is the proof — each independently reviewable.

**Files:**
- Create: `app/Services/AuditReport/Collectors/Collector.php`
- Create: `app/Services/AuditReport/Collectors/GitFactsCollector.php`
- Create: `app/Services/AuditReport/Collectors/ManifestCollector.php`
- Create: `app/Services/AuditReport/Collectors/ToolingCollector.php`
- Create: `app/Services/AuditReport/Collectors/HotspotCollector.php`
- Create: `app/Services/AuditReport/Collectors/ExcerptCollector.php`
- Modify: `app/Services/AuditReport/MetricsCollector.php`
- Test: `tests/Feature/Services/Collectors/CollectorsTest.php`

**Interfaces:**
- Consumes: `RepoContext`, `SccInventory` (Task 8).
- Produces:
  ```php
  interface Collector {
      public function name(): string;
      /** @return array<string, mixed> merged into the metrics array under name() */
      public function collect(RepoContext $context): array;
  }
  ```
  Five implementations named `git`, `manifests`, `tooling`, `hotspots`, `excerpts`.
  `ExcerptCollector::collect()` returns `['excerpts' => list<array{path, content}>]`.

**One deliberate divergence from spec §3.** The spec's diagram shows `CollectorSuite over
TierProfile->collectors`, implying tiers select collectors the way they select scanners. They
do not: every tier runs all five, because collectors are near-free PHP and the thing that
actually varies per tier is the *excerpt budget*, which `ExcerptCollector` already reads from
`$context->tier`. So the collector list is injected once in the service provider rather than
added to `TierProfile`. If a later phase genuinely needs a tier to skip a collector, add
`collectors` to `TierProfile` then — not speculatively now.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Collectors/CollectorsTest.php`:

```php
<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\Collector;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\Collectors\GitFactsCollector;
use App\Services\AuditReport\Collectors\HotspotCollector;
use App\Services\AuditReport\Collectors\ManifestCollector;
use App\Services\AuditReport\Collectors\ToolingCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class CollectorsTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/collector-repo-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/app', 0755, true);

        file_put_contents($this->repo.'/composer.json', json_encode([
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['phpstan/phpstan' => '^2.0', 'laravel/pint' => '^1.0'],
        ]));
        file_put_contents($this->repo.'/composer.lock', '{}');
        file_put_contents($this->repo.'/.env.example', 'APP_KEY=');
        file_put_contents($this->repo.'/app/Service.php', str_repeat("<?php // line\n", 50));

        exec('git -C '.escapeshellarg($this->repo).' init -q 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($this->repo).' config user.name Test');
        exec('git -C '.escapeshellarg($this->repo).' add -A 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' commit -q -m init 2>&1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        $context = new RepoContext(
            path: $this->repo,
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );

        $context->withInventory(new SccInventory(
            files: [['path' => 'app/Service.php', 'loc' => 50, 'complexity' => 3]],
            languages: ['PHP' => ['files' => 1, 'loc' => 50]],
            totalLoc: 50,
            totalComplexity: 3,
        ));

        return $context;
    }

    public function test_every_collector_implements_the_interface(): void
    {
        foreach ([GitFactsCollector::class, ManifestCollector::class, ToolingCollector::class,
                  HotspotCollector::class, ExcerptCollector::class] as $class) {
            $this->assertInstanceOf(Collector::class, app($class));
        }
    }

    public function test_git_facts_collector_reports_branch_and_contributors(): void
    {
        $facts = app(GitFactsCollector::class)->collect($this->context());

        $this->assertArrayHasKey('default_branch', $facts);
        $this->assertSame(1, $facts['contributors']);
        $this->assertSame(1, $facts['commits_analyzed']);
    }

    public function test_manifest_collector_counts_dependencies_and_detects_lockfiles(): void
    {
        $manifests = app(ManifestCollector::class)->collect($this->context());

        $this->assertSame(1, $manifests['composer.json']['dependencies']);
        $this->assertSame(2, $manifests['composer.json']['dev_dependencies']);
        $this->assertTrue($manifests['composer.json']['lockfile']);
    }

    public function test_tooling_collector_detects_static_analysis_and_env_example(): void
    {
        $tooling = app(ToolingCollector::class)->collect($this->context());

        $this->assertTrue($tooling['static_analysis']);
        $this->assertTrue($tooling['linter']);
        $this->assertTrue($tooling['env_example']);
        $this->assertFalse($tooling['dockerized']);
    }

    public function test_excerpt_collector_respects_the_tier_budget(): void
    {
        config()->set('audit.tiers.automated.excerpt_files', 1);
        config()->set('audit.tiers.automated.excerpt_bytes', 20);

        $excerpts = app(ExcerptCollector::class)->collect($this->context())['excerpts'];

        $this->assertCount(1, $excerpts);
        $this->assertLessThanOrEqual(20, strlen($excerpts[0]['content']));
    }

    public function test_excerpt_collector_selects_from_the_scc_inventory(): void
    {
        $excerpts = app(ExcerptCollector::class)->collect($this->context())['excerpts'];

        $this->assertSame('app/Service.php', $excerpts[0]['path']);
    }

    public function test_hotspot_collector_returns_an_array(): void
    {
        // A single-commit repository has no file changed twice, so hotspots
        // is legitimately empty — the shape is what matters here.
        $this->assertIsArray(app(HotspotCollector::class)->collect($this->context()));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=CollectorsTest`
Expected: FAIL — `Class "App\Services\AuditReport\Collectors\Collector" not found`.

- [ ] **Step 3: Create the interface**

Create `app/Services/AuditReport/Collectors/Collector.php`:

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

/**
 * An internal metric probe.
 *
 * Distinct from Scanner: a Collector throwing is a BUG and fails the run.
 * A Scanner throwing is normal and is absorbed. One interface would force
 * one policy, and absorbing is wrong for internal code (spec §3.1).
 */
interface Collector
{
    /** Key under which this collector's output is merged into metrics. */
    public function name(): string;

    /** @return array<string, mixed> */
    public function collect(RepoContext $context): array;
}
```

- [ ] **Step 4: Extract the five collectors**

Each collector moves an existing private method out of `MetricsCollector` **verbatim**, with
only the signature adapted. Do not change behaviour in this task.

Create `app/Services/AuditReport/Collectors/GitFactsCollector.php` — move
`MetricsCollector::gitInfo()`:

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use Illuminate\Support\Facades\Process;

class GitFactsCollector implements Collector
{
    public function name(): string
    {
        return 'git';
    }

    public function collect(RepoContext $context): array
    {
        $path = $context->path;

        $branch = Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $lastCommit = Process::path($path)->run(['git', 'log', '-1', '--format=%cI']);
        $authors = Process::path($path)->run(['git', 'log', '--format=%ae']);

        $emails = array_filter(explode("\n", trim($authors->output())));
        $byAuthor = $emails === [] ? [] : array_count_values($emails);

        return [
            'default_branch' => trim($branch->output()) ?: null,
            'last_commit_at' => trim($lastCommit->output()) ?: null,
            'commits_analyzed' => count($emails),
            'contributors' => count($byAuthor),
            'top_contributor_pct' => $emails === [] ? null : (int) round(max($byAuthor) / count($emails) * 100),
        ];
    }
}
```

Create `app/Services/AuditReport/Collectors/ManifestCollector.php` — move
`MetricsCollector::manifests()`, replacing `$repoPath` with `$context->path` and returning
the array directly. `name()` returns `'manifests'`.

Create `app/Services/AuditReport/Collectors/ToolingCollector.php` — move
`MetricsCollector::tooling()` the same way. `name()` returns `'tooling'`.

Create `app/Services/AuditReport/Collectors/HotspotCollector.php` — move
`MetricsCollector::hotspots()`, sourcing LOC from the inventory instead of `$fileStats`:

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use Illuminate\Support\Facades\Process;

class HotspotCollector implements Collector
{
    public function name(): string
    {
        return 'hotspots';
    }

    public function collect(RepoContext $context): array
    {
        $log = Process::path($context->path)->run(['git', 'log', '--name-only', '--format=']);
        $changes = array_count_values(array_filter(explode("\n", trim($log->output()))));

        $locByPath = array_column($context->inventory?->files ?? [], 'loc', 'path');

        $hotspots = [];
        foreach ($changes as $path => $count) {
            if ($count < 2 || ! isset($locByPath[$path])) {
                continue;
            }
            $hotspots[] = ['path' => $path, 'changes' => $count, 'loc' => $locByPath[$path]];
        }

        // Total order — churn×size, then path — so repeat runs list the same
        // hotspots in the same order (spec §6.3).
        usort($hotspots, fn (array $a, array $b): int => [$b['changes'] * $b['loc'], $a['path']]
            <=> [$a['changes'] * $a['loc'], $b['path']]);

        return array_slice($hotspots, 0, 10);
    }
}
```

Note the added `$a['path']` tie-break — the original `usort` was a partial order, which made
equal-product hotspots order incidentally. That is the determinism exposure spec §6.3 names.

Create `app/Services/AuditReport/Collectors/ExcerptCollector.php`:

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

class ExcerptCollector implements Collector
{
    public function name(): string
    {
        return 'excerpts';
    }

    public function collect(RepoContext $context): array
    {
        $excerpts = [];
        $files = $context->inventory?->files ?? [];

        foreach (array_slice($files, 0, $context->tier->excerptFiles) as $file) {
            $absolute = $context->path.'/'.$file['path'];

            if (! is_file($absolute)) {
                continue;
            }

            $excerpts[] = [
                'path' => $file['path'],
                'content' => (string) file_get_contents($absolute, length: $context->tier->excerptBytes),
            ];
        }

        return ['excerpts' => $excerpts];
    }
}
```

- [ ] **Step 4b: Fold the surviving non-scanner signals into ToolingCollector**

`has_ci`, `has_readme`, and test counting are not superseded by any scanner, but they lived
in `MetricsCollector`. Move them into `ToolingCollector::collect()` alongside the existing
tooling detection, so the metrics array stays complete when `MetricsCollector` becomes a
composer in the next step:

```php
'has_ci' => is_dir($context->path.'/.github/workflows')
    || file_exists($context->path.'/.gitlab-ci.yml')
    || file_exists($context->path.'/bitbucket-pipelines.yml'),
'has_readme' => count(glob($context->path.'/README*') ?: []) > 0,
```

Test counting sources from the inventory rather than a second tree walk:

```php
$files = $context->inventory?->files ?? [];
$testFiles = count(array_filter(
    $files,
    fn (array $f): bool => preg_match('#(^|/)(tests?|spec|__tests__)/#i', $f['path']) === 1
        || preg_match('/(Test|\.test|\.spec)\.[a-z]+$/i', $f['path']) === 1,
));

// ... and in the returned array:
'test_files' => $testFiles,
'test_ratio_pct' => $files === [] ? 0.0 : round($testFiles / count($files) * 100, 1),
```

`ScoreCalculator` v2 (Task 17) reads `metrics.tooling.test_ratio_pct` and
`metrics.tooling.has_ci`, which is why they belong under this collector's key.

- [ ] **Step 5: Reduce MetricsCollector to a composer**

Rewrite `app/Services/AuditReport/MetricsCollector.php` so it delegates. The secret-pattern
constants, the md5 duplication loop, the extension-based language counting, the
`sourceFiles()` walk, and the `DependencyAuditor` dependency all go — every one is superseded
by a scanner (F5.12.2), and leaving them would mean two implementations of the same
measurement disagreeing with each other.

```php
<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Collectors\Collector;
use App\Services\AuditReport\Scanners\RepoContext;

class MetricsCollector
{
    /** @param list<Collector> $collectors */
    public function __construct(private array $collectors) {}

    /** @return array{metrics: array<string, mixed>, excerpts: list<array{path: string, content: string}>} */
    public function collect(RepoContext $context): array
    {
        $metrics = [];
        $excerpts = [];

        foreach ($this->collectors as $collector) {
            $output = $collector->collect($context);

            if ($collector->name() === 'excerpts') {
                $excerpts = $output['excerpts'];

                continue;
            }

            $metrics[$collector->name()] = $output;
        }

        $inventory = $context->inventory;

        $metrics['files_total'] = count($inventory?->files ?? []);
        $metrics['loc_total'] = $inventory?->totalLoc ?? 0;
        $metrics['complexity_total'] = $inventory?->totalComplexity ?? 0;
        $metrics['languages'] = $inventory?->languages ?? [];
        $metrics['largest_files'] = array_map(
            fn (array $file): array => ['path' => $file['path'], 'loc' => $file['loc']],
            array_slice($inventory?->files ?? [], 0, 20),
        );

        return ['metrics' => $metrics, 'excerpts' => $excerpts];
    }
}
```

- [ ] **Step 6: Bind the collector list**

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->bind(MetricsCollector::class, fn ($app) => new MetricsCollector([
    $app->make(GitFactsCollector::class),
    $app->make(ManifestCollector::class),
    $app->make(ToolingCollector::class),
    $app->make(HotspotCollector::class),
    $app->make(ExcerptCollector::class),
]));
```

Add the imports.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=CollectorsTest`
Expected: PASS, 7 tests.

Run: `php artisan test --filter=MetricsCollector`
Expected: the two existing collector tests will now FAIL — they assert against the old
single-pass shape and call `collect($path, $profile)`. Task 16 rewrites them. If you want
this task green in isolation, mark them skipped with an explicit reason:
`$this->markTestSkipped('Rewritten in Task 16 against the collector composition.');`

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ app/Providers/AppServiceProvider.php tests/Feature/Services/
git commit -m "refactor(audit): extract metric collectors from MetricsCollector"
```

---

### Task 16: Delete the superseded heuristics

**Files:**
- Modify: `app/Services/AuditReport/MetricsCollector.php` (remove dead constants)
- Modify: `tests/Feature/Services/MetricsCollectorTest.php` (rewrite)
- Modify: `tests/Feature/Services/MetricsCollectorGitTest.php` (rewrite or fold in)
- Test: `tests/Feature/Services/SupersededHeuristicsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new. This task **removes** `MetricsCollector::SECRET_PATTERNS`,
  `SOURCE_EXTENSIONS`, `EXCLUDED_DIRS`, the md5 duplication loop, and the
  `secret_findings` / `duplication_pct` / `test_ratio_pct` metric keys.

- [ ] **Step 1: Write the guard test**

Create `tests/Feature/Services/SupersededHeuristicsTest.php`. This is what stops the deleted
code returning as a second implementation alongside the scanners (spec §3.1):

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\MetricsCollector;
use ReflectionClass;
use Tests\Feature\FeatureTest;

class SupersededHeuristicsTest extends FeatureTest
{
    public function test_metrics_collector_no_longer_carries_secret_patterns(): void
    {
        // Gitleaks supersedes the in-house pattern set (F5.12.2). Two secret
        // implementations would double-count and disagree.
        $this->assertArrayNotHasKey(
            'SECRET_PATTERNS',
            (new ReflectionClass(MetricsCollector::class))->getConstants(),
        );
    }

    public function test_metrics_collector_source_is_small(): void
    {
        // It was 220 lines doing eight jobs. As a composer it should be a
        // fraction of that; a regression here means logic crept back in.
        $lines = count(file(app_path('Services/AuditReport/MetricsCollector.php')));

        $this->assertLessThan(80, $lines, 'MetricsCollector has grown back into a grab-bag.');
    }

    public function test_no_md5_line_hashing_remains(): void
    {
        // jscpd supersedes the line-hash duplication heuristic.
        $source = (string) file_get_contents(app_path('Services/AuditReport/MetricsCollector.php'));

        $this->assertStringNotContainsString('md5(', $source);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=SupersededHeuristicsTest`
Expected: FAIL — the constants and md5 loop are still present from Task 15.

- [ ] **Step 3: Confirm the deletion is complete**

Task 15 removed the superseded code as part of the restructure. Verify none of it survived —
each of these greps must return nothing from `MetricsCollector.php`:

```bash
grep -n "SECRET_PATTERNS\|SOURCE_EXTENSIONS\|EXCLUDED_DIRS" app/Services/AuditReport/MetricsCollector.php
grep -n "md5(\|lineHashes\|duplicateLines\|secretFindings" app/Services/AuditReport/MetricsCollector.php
grep -n "sourceFiles\|DependencyAuditor" app/Services/AuditReport/MetricsCollector.php
```

If any returns a hit, delete that code now — the guard test in Step 1 is asserting exactly
this, and it is the thing that stops a second, disagreeing implementation of a measurement a
scanner already owns.

- [ ] **Step 4: Rewrite the existing collector tests**

`MetricsCollectorTest` and `MetricsCollectorGitTest` assert against the removed keys. Rewrite
`MetricsCollectorTest` to assert the composed shape:

```php
public function test_composes_collector_output_under_named_keys(): void
{
    $collected = app(MetricsCollector::class)->collect($this->context());
    $metrics = $collected['metrics'];

    $this->assertArrayHasKey('git', $metrics);
    $this->assertArrayHasKey('manifests', $metrics);
    $this->assertArrayHasKey('tooling', $metrics);
    $this->assertArrayHasKey('hotspots', $metrics);
    $this->assertArrayHasKey('files_total', $metrics);
    $this->assertArrayHasKey('loc_total', $metrics);
}

public function test_excerpts_are_returned_separately_from_metrics(): void
{
    $collected = app(MetricsCollector::class)->collect($this->context());

    $this->assertArrayNotHasKey('excerpts', $collected['metrics']);
    $this->assertIsArray($collected['excerpts']);
}

public function test_superseded_keys_are_gone(): void
{
    $metrics = app(MetricsCollector::class)->collect($this->context())['metrics'];

    $this->assertArrayNotHasKey('secret_findings', $metrics);
    $this->assertArrayNotHasKey('duplication_pct', $metrics);
}
```

Build `$this->context()` the same way `CollectorsTest` does — a temp git repo plus a
hand-built `SccInventory`. Fold `MetricsCollectorGitTest`'s assertions into
`CollectorsTest::test_git_facts_collector_reports_branch_and_contributors` and delete the
file, since git facts now live in `GitFactsCollector`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=SupersededHeuristicsTest`
Expected: PASS, 3 tests.

Run: `php artisan test --filter=MetricsCollector`
Expected: PASS.

Run: `php artisan test --filter=CollectorsTest`
Expected: PASS.

- [ ] **Step 6: Confirm nothing still reads the deleted keys**

Run: `grep -rn "secret_findings\|duplication_pct" app/ resources/views/ | grep -v Scanners`
Expected: hits only in `ScoreCalculator` (Task 17 rewrites it) and possibly the report
templates (Task 21). Note them; do not fix them here.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ tests/Feature/Services/
git rm tests/Feature/Services/MetricsCollectorGitTest.php
git commit -m "refactor(audit): delete the secret, duplication, and language heuristics superseded by scanners"
```

---

### Task 17: ScoreCalculator v2 and not-measured dimensions

**This is the task Task 2 had to precede.** It changes the formulas, so `ScoreCalculator::VERSION`
bumps to 2 and every report produced from here on is incomparable to a v1 report.

The load-bearing behaviour: **a failed scanner must not look like a clean repository.** Semgrep
times out, contributes zero findings, `security_hygiene` scores 100, and the customer is
charged $49 to be told their code is clean when nothing looked at it (spec §7.2).

**Files:**
- Create: `app/Services/AuditReport/ScoreSet.php`
- Modify: `app/Services/AuditReport/ScoreCalculator.php` (rewrite)
- Test: `tests/Feature/Services/ScoreCalculatorTest.php` (rewrite)

**Interfaces:**
- Consumes: `FindingGroup` (Task 6), `ScannerSuiteResult` (Task 8), `SemgrepScanner::dimensionFor()` (Task 10).
- Produces:
  ```php
  final readonly class ScoreSet {
      public array $scores;        // array<string, int>, only measured dimensions + overall
      public array $notMeasured;   // list<string> dimension names
      public int $scoringVersion;
      public function toPayloadScores(): array;   // scores, with not-measured omitted
  }

  class ScoreCalculator {
      public const VERSION = 2;
      public function calculate(array $metrics, array $groups, ScannerSuiteResult $runs): ScoreSet;
  }
  ```

- [ ] **Step 1: Write the failing test**

Rewrite `tests/Feature/Services/ScoreCalculatorTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\ScoreCalculator;
use App\Services\AuditReport\Scanners\ScannerOutcome;
use App\Services\AuditReport\Scanners\ScannerRun;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use Tests\Feature\FeatureTest;

class ScoreCalculatorTest extends FeatureTest
{
    private function metrics(): array
    {
        return [
            'files_total' => 100,
            'loc_total' => 20000,
            'complexity_total' => 800,
            'largest_files' => [
                ['path' => 'a.php', 'loc' => 1200],
                ['path' => 'b.php', 'loc' => 800],
            ],
            'tooling' => ['test_ratio_pct' => 10.0, 'has_ci' => true],
            'manifests' => ['composer.json' => ['dependencies' => 10, 'dev_dependencies' => 5, 'lockfile' => true]],
            'duplication_pct' => 20.0,
        ];
    }

    /** @param list<string> $ok */
    private function runs(array $ok, array $failed = []): ScannerSuiteResult
    {
        $runs = [];

        foreach ($ok as $name) {
            $runs[] = new ScannerRun($name, '1.0', 10, 0, ScannerOutcome::OK);
        }

        foreach ($failed as $name) {
            $runs[] = new ScannerRun($name, '1.0', 10, 0, ScannerOutcome::TIMEOUT, 'timeout');
        }

        return new ScannerSuiteResult([], $runs);
    }

    private function group(
        string $family,
        Severity $severity,
        int $count,
        string $dimension = 'security_hygiene',
    ): FindingGroup {
        return new FindingGroup(
            ruleFamily: $family,
            directory: 'app',
            severity: $severity,
            count: $count,
            score: $severity->weight() * $count,
            examples: [],
            tools: ['semgrep'],
            dimension: $dimension,
        );
    }

    public function test_a_group_scores_the_dimension_it_declares_not_the_tool_that_found_it(): void
    {
        // A Semgrep rule tagged `dimension: structure` must move structure,
        // not security_hygiene (spec §5.3, §7.1).
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $clean = $calculator->calculate($this->metrics(), [], $runs);
        $structural = $calculator->calculate($this->metrics(), [
            $this->group('common.configuration', Severity::MEDIUM, 10, 'structure'),
        ], $runs);

        $this->assertLessThan($clean->scores['structure'], $structural->scores['structure']);
        $this->assertSame($clean->scores['security_hygiene'], $structural->scores['security_hygiene']);
    }

    private function allScanners(): array
    {
        return ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'];
    }

    public function test_reports_the_current_scoring_version(): void
    {
        $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $this->runs($this->allScanners()));

        $this->assertSame(2, $set->scoringVersion);
        $this->assertSame(2, ScoreCalculator::VERSION);
    }

    public function test_scores_every_dimension_when_all_scanners_ran(): void
    {
        $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $this->runs($this->allScanners()));

        $this->assertSame([], $set->notMeasured);

        foreach (['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'] as $key) {
            $this->assertArrayHasKey($key, $set->scores);
            $this->assertIsInt($set->scores[$key]);
        }
    }

    public function test_a_failed_semgrep_does_not_score_security_as_perfect(): void
    {
        // THE trap: zero findings from a scanner that never ran is not a clean repo.
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'jscpd'], failed: ['semgrep']),
        );

        $this->assertContains('security_hygiene', $set->notMeasured);
        $this->assertArrayNotHasKey('security_hygiene', $set->scores);
    }

    public function test_a_failed_jscpd_marks_duplication_not_measured(): void
    {
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'semgrep'], failed: ['jscpd']),
        );

        $this->assertContains('duplication', $set->notMeasured);
    }

    public function test_overall_renormalizes_over_measured_dimensions_only(): void
    {
        $calculator = app(ScoreCalculator::class);

        $full = $calculator->calculate($this->metrics(), [], $this->runs($this->allScanners()));
        $partial = $calculator->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'jscpd'], failed: ['semgrep']),
        );

        // Dropping a dimension must not drag overall toward zero — the
        // remaining weights are renormalized to sum to 1.
        $this->assertGreaterThan(0, $partial->scores['overall']);
        $this->assertLessThanOrEqual(100, $partial->scores['overall']);
        $this->assertNotSame($full->scores['overall'], $partial->scores['overall']);
    }

    public function test_the_diagnostic_tier_structurally_lacks_duplication_and_security(): void
    {
        // diagnostic runs neither jscpd nor semgrep by design (spec §4.1),
        // so both dimensions are honestly not-measured rather than invented.
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv']),
        );

        $this->assertContains('duplication', $set->notMeasured);
        $this->assertContains('security_hygiene', $set->notMeasured);
    }

    public function test_gitleaks_findings_drive_security_hygiene_down(): void
    {
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $clean = $calculator->calculate($this->metrics(), [], $runs);
        $leaky = $calculator->calculate($this->metrics(), [
            $this->group('secrets.credential', Severity::CRITICAL, 3),
        ], $runs);

        $this->assertLessThan($clean->scores['security_hygiene'], $leaky->scores['security_hygiene']);
    }

    public function test_duplication_score_comes_from_the_measured_percentage(): void
    {
        $metrics = $this->metrics();
        $metrics['duplication_pct'] = 0.0;

        $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

        $this->assertSame(100, $set->scores['duplication']);
    }

    public function test_scores_are_clamped_to_zero_through_one_hundred(): void
    {
        $metrics = $this->metrics();
        $metrics['duplication_pct'] = 500.0;

        $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

        $this->assertSame(0, $set->scores['duplication']);
    }

    public function test_is_deterministic(): void
    {
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $this->assertEquals(
            $calculator->calculate($this->metrics(), [], $runs),
            $calculator->calculate($this->metrics(), [], $runs),
        );
    }

    public function test_payload_scores_omit_not_measured_dimensions(): void
    {
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv']),
        );

        $this->assertArrayNotHasKey('duplication', $set->toPayloadScores());
        $this->assertArrayHasKey('overall', $set->toPayloadScores());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ScoreCalculatorTest`
Expected: FAIL — `calculate()` takes one argument, and `ScoreSet` does not exist.

- [ ] **Step 3: Create the ScoreSet value object**

Create `app/Services/AuditReport/ScoreSet.php`:

```php
<?php

namespace App\Services\AuditReport;

/**
 * Deterministic scores plus an explicit record of what could NOT be measured.
 *
 * A dimension whose contributing scanner did not run is absent from `scores`
 * and named in `notMeasured` — never scored, never counted toward `overall`.
 * Zero findings from a scanner that never ran is not a clean repository
 * (spec §7.2).
 */
final readonly class ScoreSet
{
    /**
     * @param  array<string, int>  $scores       measured dimensions plus `overall`
     * @param  list<string>  $notMeasured        dimension names, sorted
     */
    public function __construct(
        public array $scores,
        public array $notMeasured,
        public int $scoringVersion,
    ) {}

    /** @return array<string, int> */
    public function toPayloadScores(): array
    {
        return $this->scores;
    }

    public function wasMeasured(string $dimension): bool
    {
        return ! in_array($dimension, $this->notMeasured, true);
    }
}
```

- [ ] **Step 4: Rewrite ScoreCalculator**

Rewrite `app/Services/AuditReport/ScoreCalculator.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;

/**
 * Deterministic 0-100 health scores computed from measured metrics and
 * scanner findings. The LLM narrates; these numbers are authoritative, so
 * repeat runs of the same repository score identically (deltas depend on it).
 *
 * Findings feed the formulas; the formulas never feed the findings (F5.12.2).
 */
class ScoreCalculator
{
    /**
     * Bump when any formula below changes. Reports record the version that
     * produced them; deltas and benchmarks only compare within a version.
     *
     * v2 (Phase 11): duplication now from jscpd, security_hygiene from
     * Gitleaks + Semgrep, structure from scc complexity.
     */
    public const VERSION = 2;

    /** Which scanner each dimension depends on. A dimension whose scanner did not run is not measured. */
    private const DIMENSION_SCANNERS = [
        'structure' => ['scc'],
        'duplication' => ['jscpd'],
        'testing' => [],            // collector-driven; always measurable
        'dependencies' => ['osv'],
        'security_hygiene' => ['gitleaks', 'semgrep'],
    ];

    private const WEIGHTS = [
        'structure' => 0.25,
        'duplication' => 0.20,
        'testing' => 0.25,
        'dependencies' => 0.15,
        'security_hygiene' => 0.15,
    ];

    /**
     * @param  list<FindingGroup>  $groups
     */
    public function calculate(array $metrics, array $groups, ScannerSuiteResult $runs): ScoreSet
    {
        $scores = [];
        $notMeasured = [];

        foreach (self::DIMENSION_SCANNERS as $dimension => $required) {
            if (! $this->allRan($required, $runs)) {
                $notMeasured[] = $dimension;

                continue;
            }

            $scores[$dimension] = match ($dimension) {
                'structure' => $this->structure($metrics, $this->forDimension($groups, 'structure')),
                'duplication' => $this->duplication($metrics),
                'testing' => $this->testing($metrics),
                'dependencies' => $this->dependencies($metrics, $this->forDimension($groups, 'dependencies')),
                'security_hygiene' => $this->securityHygiene($this->forDimension($groups, 'security_hygiene')),
            };
        }

        sort($notMeasured);

        $scores['overall'] = $this->overall($scores);

        return new ScoreSet($scores, $notMeasured, self::VERSION);
    }

    /** @param list<string> $required */
    private function allRan(array $required, ScannerSuiteResult $runs): bool
    {
        foreach ($required as $scanner) {
            if (! $runs->ranSuccessfully($scanner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renormalize over measured dimensions so a missing dimension does not
     * drag the overall toward zero — it is unknown, not bad.
     *
     * @param  array<string, int>  $scores
     */
    private function overall(array $scores): int
    {
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach (self::WEIGHTS as $dimension => $weight) {
            if (! isset($scores[$dimension])) {
                continue;
            }

            $weighted += $weight * $scores[$dimension];
            $totalWeight += $weight;
        }

        return $totalWeight > 0.0 ? (int) round($weighted / $totalWeight) : 0;
    }

    /**
     * Findings route to a dimension by the dimension THEY declare — never by
     * which tool produced them. A Semgrep rule tagged `dimension: structure`
     * scores structure, which is the whole point of the rule metadata
     * (spec §5.3, §7.1) and keeps one mapping instead of two.
     *
     * @param  list<FindingGroup>  $groups
     * @return list<FindingGroup>
     */
    private function forDimension(array $groups, string $dimension): array
    {
        return array_values(array_filter(
            $groups,
            fn (FindingGroup $group): bool => $group->dimension === $dimension,
        ));
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `structure` */
    private function structure(array $metrics, array $groups): int
    {
        $files = max(1, (int) ($metrics['files_total'] ?? 1));
        $avgLoc = ((int) ($metrics['loc_total'] ?? 0)) / $files;
        $avgComplexity = ((int) ($metrics['complexity_total'] ?? 0)) / $files;

        $huge = count(array_filter($metrics['largest_files'] ?? [], fn (array $f): bool => ($f['loc'] ?? 0) >= 1000));
        $big = count(array_filter(
            $metrics['largest_files'] ?? [],
            fn (array $f): bool => ($f['loc'] ?? 0) >= 500 && ($f['loc'] ?? 0) < 1000,
        ));

        // Maintainability and correctness rules (dimension: structure) count here.
        $ruleFindings = array_sum(array_map(fn (FindingGroup $g): int => $g->count, $groups));

        return $this->clamp(
            100
            - max(0, $avgLoc - 120) * 0.25
            - max(0, $avgComplexity - 8) * 1.5
            - 8 * $huge
            - 3 * $big
            - min(20, $ruleFindings)
        );
    }

    private function duplication(array $metrics): int
    {
        return $this->clamp(100 - 2.5 * (float) ($metrics['duplication_pct'] ?? 0));
    }

    private function testing(array $metrics): int
    {
        return $this->clamp(
            min(90, 4.5 * (float) ($metrics['tooling']['test_ratio_pct'] ?? 0))
            + (($metrics['tooling']['has_ci'] ?? false) ? 10 : 0)
        );
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `dependencies` */
    private function dependencies(array $metrics, array $groups): int
    {
        $score = 100;

        foreach (($metrics['manifests'] ?? []) as $manifest) {
            if (! ($manifest['lockfile'] ?? false)) {
                $score -= 20;
            }
        }

        $score -= 8 * array_sum(array_map(fn (FindingGroup $g): int => $g->count, $groups));

        return $this->clamp($score);
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `security_hygiene` */
    private function securityHygiene(array $groups): int
    {
        $score = 100;

        foreach ($groups as $group) {
            // A committed credential is categorically worse than a SAST hit,
            // so secrets keep their own weight within the same dimension.
            $score -= str_starts_with($group->ruleFamily, 'secrets.')
                ? 15 * $group->count
                : min(20, $group->count * 2);
        }

        return $this->clamp($score);
    }

    private function clamp(float $value): int
    {
        return (int) round(max(0, min(100, $value)));
    }
}
```

Note the `securityHygiene()` Semgrep arm uses `in_array('semgrep', $group->tools)` rather
than re-deriving the dimension per rule. Groups are already family-scoped, and
`SemgrepScanner::dimensionFor()` (Task 10) exists for the report template to explain *why* a
group affected a dimension.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=ScoreCalculatorTest`
Expected: PASS, 11 tests. The not-measured tests are the load-bearing ones.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ScoreCalculator.php app/Services/AuditReport/ScoreSet.php tests/Feature/Services/ScoreCalculatorTest.php
git commit -m "feat(audit): score from scanner findings and never score an unmeasured dimension"
```

---

### Task 18: PromptComposer v2 and the stored-override guard

The trap here: `PromptComposer::template()` reads an admin override **stored in the
database**, and `templateIsValid()` currently requires only `{metrics}` and `{excerpts}`.
Adding `{groups}` means an override already saved in production keeps validating, keeps
being used, and silently produces prompts containing no findings — the entire scanner
platform invisible, with no error anywhere (spec §7.3).

**Files:**
- Modify: `app/Services/AuditReport/PromptComposer.php`
- Test: `tests/Feature/Services/PromptComposerTest.php` (extend)

**Interfaces:**
- Consumes: `FindingGroup` (Task 6), `ConfigService` (existing).
- Produces:
  ```php
  class PromptComposer {
      public const DEFAULT_TEMPLATE = /* now contains {metrics}, {groups}, {excerpts} */;
      public function template(): string;                  // falls back if override lacks {groups}
      public function templateIsValid(string $t): bool;    // now requires {groups}
      public function storedOverrideIsUsable(): bool;
      public function compose(array $metrics, array $groups, array $excerpts, ?string $adminContext = null): string;
      public function preview(AuditRequest $request): string;
  }
  ```

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Services/PromptComposerTest.php`:

```php
public function test_default_template_carries_all_three_placeholders(): void
{
    $template = PromptComposer::DEFAULT_TEMPLATE;

    $this->assertStringContainsString('{metrics}', $template);
    $this->assertStringContainsString('{groups}', $template);
    $this->assertStringContainsString('{excerpts}', $template);
}

public function test_a_template_without_groups_is_invalid(): void
{
    $composer = app(PromptComposer::class);

    $this->assertFalse($composer->templateIsValid('{metrics} and {excerpts} only'));
    $this->assertTrue($composer->templateIsValid('{metrics} {groups} {excerpts}'));
}

public function test_a_stored_override_lacking_groups_is_not_used(): void
{
    // A pre-Phase-11 override would otherwise keep validating and silently
    // produce prompts with no findings at all (spec §7.3).
    app(ConfigService::class)->set('audit.prompt_template', 'Legacy: {metrics} {excerpts}');

    $this->assertFalse(app(PromptComposer::class)->storedOverrideIsUsable());
    $this->assertSame(PromptComposer::DEFAULT_TEMPLATE, app(PromptComposer::class)->template());
}

public function test_a_valid_stored_override_is_still_honoured(): void
{
    app(ConfigService::class)->set('audit.prompt_template', 'Custom {metrics} {groups} {excerpts}');

    $this->assertTrue(app(PromptComposer::class)->storedOverrideIsUsable());
    $this->assertStringContainsString('Custom', app(PromptComposer::class)->template());
}

public function test_composes_groups_into_the_prompt(): void
{
    $prompt = app(PromptComposer::class)->compose(
        ['files_total' => 10],
        [new FindingGroup('php.injection', 'app/Http', Severity::HIGH, 37, 1480,
            [['path' => 'app/Http/UserController.php', 'line' => 42]], ['semgrep'], 'security_hygiene')],
        [],
    );

    $this->assertStringContainsString('php.injection', $prompt);
    $this->assertStringContainsString('app/Http', $prompt);
    $this->assertStringContainsString('37', $prompt);
}

public function test_group_examples_contribute_paths_but_never_content(): void
{
    $prompt = app(PromptComposer::class)->compose(
        [],
        [new FindingGroup('secrets.credential', 'config', Severity::CRITICAL, 1, 100,
            [['path' => 'config/services.php', 'line' => 17]], ['gitleaks'], 'security_hygiene')],
        [],
    );

    $this->assertStringContainsString('config/services.php', $prompt);
    $this->assertStringContainsString(':17', $prompt);
}

public function test_admin_context_is_appended_identically_by_compose_and_preview(): void
{
    // §18.2 defect: the append block was duplicated verbatim between the two.
    $request = AuditRequest::factory()->create([
        'admin_context' => 'The client reports intermittent 500s at checkout.',
        'metrics' => ['files_total' => 10],
    ]);

    $composed = app(PromptComposer::class)->compose(['files_total' => 10], [], [], $request->admin_context);
    $previewed = app(PromptComposer::class)->preview($request);

    $this->assertStringContainsString('The client reports intermittent 500s at checkout.', $composed);
    $this->assertStringContainsString('The client reports intermittent 500s at checkout.', $previewed);
}
```

Add the imports: `App\Models\AuditRequest`, `App\Services\AuditReport\Findings\FindingGroup`,
`App\Services\AuditReport\Findings\Severity`, `App\Services\ConfigService`,
`App\Services\AuditReport\PromptComposer`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=PromptComposerTest`
Expected: FAIL — `{groups}` is absent from the default template and `compose()` takes three
arguments.

- [ ] **Step 3: Rewrite PromptComposer**

Rewrite `app/Services/AuditReport/PromptComposer.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\ConfigService;

class PromptComposer
{
    public const DEFAULT_TEMPLATE = <<<'TEMPLATE'
Repository metrics (JSON):
{metrics}

Problem groups, ranked by severity and count. Narrate each one: what it is,
what it affects, and what fixing it buys the client. Never enumerate individual
findings — one lint error must never become one report item.
{groups}

File excerpts (largest files, truncated):
{excerpts}

Produce the codebase health report.
TEMPLATE;

    public function __construct(
        private ConfigService $configService,
    ) {}

    /**
     * The template in use. A stored override that predates the groups
     * placeholder is NOT used — it would silently produce prompts containing
     * no findings, making the whole scanner platform invisible (spec §7.3).
     */
    public function template(): string
    {
        return $this->storedOverrideIsUsable()
            ? trim((string) $this->configService->get('audit.prompt_template', ''))
            : self::DEFAULT_TEMPLATE;
    }

    public function storedOverrideIsUsable(): bool
    {
        $override = trim((string) $this->configService->get('audit.prompt_template', ''));

        return $override !== '' && $this->templateIsValid($override);
    }

    public function templateIsValid(string $template): bool
    {
        return str_contains($template, '{metrics}')
            && str_contains($template, '{groups}')
            && str_contains($template, '{excerpts}');
    }

    /**
     * @param  list<FindingGroup>  $groups
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function compose(array $metrics, array $groups, array $excerpts, ?string $adminContext = null): string
    {
        return $this->withAdminContext(
            str_replace(
                ['{metrics}', '{groups}', '{excerpts}'],
                [
                    json_encode($metrics, JSON_PRETTY_PRINT),
                    $this->renderGroups($groups),
                    $this->renderExcerpts($excerpts),
                ],
                $this->template(),
            ),
            $adminContext,
        );
    }

    /**
     * The prompt the next run would use — stored metrics if any, excerpts and
     * groups marked as runtime-computed (neither is persisted on the request).
     */
    public function preview(AuditRequest $request): string
    {
        return $this->withAdminContext(
            str_replace(
                ['{metrics}', '{groups}', '{excerpts}'],
                [
                    json_encode($request->metrics ?? ['note' => 'metrics are collected at run time'], JSON_PRETTY_PRINT),
                    "\n[problem groups are computed at run time]\n",
                    "\n[file excerpts are computed at run time]\n",
                ],
                $this->template(),
            ),
            $request->admin_context,
        );
    }

    /** Single append path, shared by compose() and preview() — §18.2 defect. */
    private function withAdminContext(string $prompt, ?string $adminContext): string
    {
        if ($adminContext === null || trim($adminContext) === '') {
            return $prompt;
        }

        return $prompt."\n\nAdditional context from the audit team:\n".trim($adminContext);
    }

    /** @param list<FindingGroup> $groups */
    private function renderGroups(array $groups): string
    {
        if ($groups === []) {
            return "\n[no problem groups were produced for this run]\n";
        }

        $rendered = '';

        foreach ($groups as $group) {
            $locations = implode(', ', array_map(
                fn (array $example): string => $example['path'].($example['line'] !== null ? ':'.$example['line'] : ''),
                $group->examples,
            ));

            $rendered .= sprintf(
                "\n- %s in %s — %d finding(s), severity %s, reported by %s\n  examples: %s\n",
                $group->ruleFamily,
                $group->directory,
                $group->count,
                $group->severity->value,
                implode('+', $group->tools),
                $locations !== '' ? $locations : 'none recorded',
            );
        }

        return $rendered;
    }

    /** @param list<array{path: string, content: string}> $excerpts */
    private function renderExcerpts(array $excerpts): string
    {
        $rendered = '';

        foreach ($excerpts as $excerpt) {
            $rendered .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        return $rendered;
    }
}
```

- [ ] **Step 4: Surface the rejected override to operators**

A silently-ignored override is better than a silently-used broken one, but the operator still
needs to know. Find the audit-settings surface that edits the template:

```bash
grep -rn "prompt_template" app/Filament/ app/Livewire/ resources/views/
```

In that component, show a warning when `storedOverrideIsUsable()` is false and a non-empty
override exists — text along the lines of *"Your saved prompt template is missing the
{groups} placeholder and is not being used. The default template is active."* Route the
string through the translation layer per F5.11.3.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=PromptComposerTest`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/PromptComposer.php tests/Feature/Services/PromptComposerTest.php app/Filament/ resources/views/
git commit -m "feat(audit): compose prompts from problem groups and reject stale template overrides"
```

---

### Task 19: ReportPayload v2 with versioned validation

The validator must **keep validating v1**. `AuditReportController` renders stored payloads on
every historical report view, so dropping v1 validation breaks every report already delivered
(spec §7.4).

**Files:**
- Modify: `app/Services/AuditReport/ReportPayload.php`
- Test: `tests/Feature/Services/ReportPayloadTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  ```php
  class ReportPayload {
      public const VERSION = 2;
      public static function validate(mixed $payload, ?int $version = null): array;
  }
  ```
  v2 payloads add `groups[]`, each `{rule_family, directory, severity, count, narrative: {what, affects, benefit}}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/ReportPayloadTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\ReportPayload;
use Tests\Feature\FeatureTest;

class ReportPayloadTest extends FeatureTest
{
    private function v1Payload(): array
    {
        return [
            'summary' => 'The codebase is serviceable but under-tested.',
            'scores' => [
                'structure' => 70, 'duplication' => 60, 'testing' => 40,
                'dependencies' => 80, 'security_hygiene' => 90, 'overall' => 68,
            ],
            'risks' => [
                ['title' => 'No tests', 'impact' => 'high', 'evidence' => 'Test ratio 4%.',
                 'recommendation' => 'Add characterization tests.'],
            ],
            'fix_first_plan' => [
                ['step' => 'Add tests to the checkout path', 'why' => 'Highest churn.', 'effort' => 'M'],
            ],
        ];
    }

    private function v2Payload(): array
    {
        return $this->v1Payload() + [
            'groups' => [
                [
                    'rule_family' => 'php.injection',
                    'directory' => 'app/Http',
                    'severity' => 'high',
                    'count' => 37,
                    'narrative' => [
                        'what' => 'SQL assembled by string interpolation.',
                        'affects' => 'Every controller reachable from the public API.',
                        'benefit' => 'Removes the most direct route to data exfiltration.',
                    ],
                ],
            ],
        ];
    }

    public function test_a_v2_payload_validates(): void
    {
        $this->assertIsArray(ReportPayload::validate($this->v2Payload(), 2));
    }

    public function test_a_v1_payload_still_validates_under_v1(): void
    {
        // Historical reports render on every view; dropping v1 validation
        // breaks every report already delivered (spec §7.4).
        $this->assertIsArray(ReportPayload::validate($this->v1Payload(), 1));
    }

    public function test_a_v1_payload_is_rejected_under_v2(): void
    {
        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($this->v1Payload(), 2);
    }

    public function test_version_defaults_to_the_current_contract(): void
    {
        $this->assertSame(2, ReportPayload::VERSION);
        $this->assertIsArray(ReportPayload::validate($this->v2Payload()));
    }

    public function test_v2_allows_partial_scores_for_not_measured_dimensions(): void
    {
        // A diagnostic run legitimately has no duplication or security score
        // (spec §7.2). Requiring all six would fail every free-tier report.
        $payload = $this->v2Payload();
        unset($payload['scores']['duplication'], $payload['scores']['security_hygiene']);

        $this->assertIsArray(ReportPayload::validate($payload, 2));
    }

    public function test_v2_still_requires_an_overall_score(): void
    {
        $payload = $this->v2Payload();
        unset($payload['scores']['overall']);

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_malformed_group(): void
    {
        $payload = $this->v2Payload();
        unset($payload['groups'][0]['narrative']['benefit']);

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_group_with_an_unknown_severity(): void
    {
        $payload = $this->v2Payload();
        $payload['groups'][0]['severity'] = 'catastrophic';

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_non_array_payload(): void
    {
        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate('not an object');
    }

    public function test_rejects_a_malformed_risk_entry(): void
    {
        $payload = $this->v2Payload();
        $payload['risks'][0]['impact'] = 'apocalyptic';

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ReportPayloadTest`
Expected: FAIL — `validate()` takes one argument and `VERSION` is 1.

- [ ] **Step 3: Rewrite the validator**

Rewrite `app/Services/AuditReport/ReportPayload.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;

/**
 * The canonical payload contract.
 *
 * validate() dispatches on version and retains v1 so historical reports keep
 * rendering — AuditReportController validates stored payloads on every view
 * (spec §7.4).
 */
class ReportPayload
{
    /** Bump when the payload contract changes. */
    public const VERSION = 2;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    private const V1_SCORES = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'];

    public static function validate(mixed $payload, ?int $version = null): array
    {
        $version ??= self::VERSION;

        if (! is_array($payload)) {
            throw new AiAnalysisException('Analysis payload is not an object');
        }

        self::validateCommon($payload);

        return match ($version) {
            1 => self::validateV1($payload),
            2 => self::validateV2($payload),
            default => throw new AiAnalysisException("Unknown payload schema version: {$version}"),
        };
    }

    private static function validateCommon(array $payload): void
    {
        if (! is_string($payload['summary'] ?? null)) {
            throw new AiAnalysisException('Missing summary');
        }

        if (! is_array($payload['risks'] ?? null)) {
            throw new AiAnalysisException('Missing risks');
        }

        foreach ($payload['risks'] as $risk) {
            if (! in_array($risk['impact'] ?? null, ['high', 'medium', 'low'], true)
                || ! is_string($risk['title'] ?? null)
                || ! is_string($risk['evidence'] ?? null)
                || ! is_string($risk['recommendation'] ?? null)) {
                throw new AiAnalysisException('Malformed risk entry');
            }
        }

        if (! is_array($payload['fix_first_plan'] ?? null)) {
            throw new AiAnalysisException('Missing fix_first_plan');
        }

        foreach ($payload['fix_first_plan'] as $step) {
            if (! is_string($step['step'] ?? null)
                || ! is_string($step['why'] ?? null)
                || ! in_array($step['effort'] ?? null, ['S', 'M', 'L'], true)) {
                throw new AiAnalysisException('Malformed fix_first_plan entry');
            }
        }
    }

    private static function validateV1(array $payload): array
    {
        foreach (self::V1_SCORES as $key) {
            if (! is_int($payload['scores'][$key] ?? null)) {
                throw new AiAnalysisException("Missing or non-integer score: {$key}");
            }
        }

        return $payload;
    }

    private static function validateV2(array $payload): array
    {
        // Dimensions may be absent when their scanner did not run (spec §7.2);
        // `overall` is always present because it renormalizes over what ran.
        if (! is_int($payload['scores']['overall'] ?? null)) {
            throw new AiAnalysisException('Missing or non-integer score: overall');
        }

        foreach ($payload['scores'] ?? [] as $key => $value) {
            if (! in_array($key, self::V1_SCORES, true)) {
                throw new AiAnalysisException("Unknown score dimension: {$key}");
            }

            if (! is_int($value)) {
                throw new AiAnalysisException("Non-integer score: {$key}");
            }
        }

        if (! is_array($payload['groups'] ?? null)) {
            throw new AiAnalysisException('Missing groups');
        }

        foreach ($payload['groups'] as $group) {
            if (! is_string($group['rule_family'] ?? null)
                || ! is_string($group['directory'] ?? null)
                || ! in_array($group['severity'] ?? null, self::SEVERITIES, true)
                || ! is_int($group['count'] ?? null)
                || ! is_string($group['narrative']['what'] ?? null)
                || ! is_string($group['narrative']['affects'] ?? null)
                || ! is_string($group['narrative']['benefit'] ?? null)) {
                throw new AiAnalysisException('Malformed group entry');
            }
        }

        return $payload;
    }
}
```

- [ ] **Step 4: Update the report read path**

`AuditReportController` validates stored payloads. Find the call:

```bash
grep -rn "ReportPayload::validate" app/
```

Pass the stored version so historical reports validate under v1:

```php
ReportPayload::validate($report->payload, $report->payload_schema_version);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=ReportPayloadTest`
Expected: PASS, 10 tests.

Run: `php artisan test --filter=AuditReportController`
Expected: PASS — historical rendering intact.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ReportPayload.php app/Http/Controllers/AuditReportController.php tests/Feature/Services/ReportPayloadTest.php
git commit -m "feat(audit): version the payload contract and add narrated problem groups"
```

---

### Task 20: AnalysisResult and token capture

`ClaudeAnalyzer` currently discards `$message->usage` entirely, which makes cost telemetry
(F5.12.6) impossible. This changes the `AiAnalyzer` interface — the only implementation is
`ClaudeAnalyzer`, so the blast radius is that class and its tests (spec §3.3).

**Files:**
- Create: `app/Services/AuditReport/AnalysisResult.php`
- Modify: `app/Services/AuditReport/AiAnalyzer.php`
- Modify: `app/Services/AuditReport/ClaudeAnalyzer.php`
- Test: `tests/Feature/Services/AnalysisResultTest.php`

**Interfaces:**
- Consumes: `TierProfile` (Task 3), `FindingGroup` (Task 6), `PromptComposer` (Task 18), `ReportPayload` (Task 19).
- Produces:
  ```php
  final readonly class AnalysisResult {
      public array $payload; public int $inputTokens; public int $outputTokens;
  }

  interface AiAnalyzer {
      public function analyze(array $metrics, array $groups, array $excerpts,
                             TierProfile $tier, ?string $adminContext = null): AnalysisResult;
  }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/AnalysisResultTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AnalysisResult;
use App\Services\AuditReport\Tiers\TierProfile;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use ReflectionNamedType;
use Tests\Feature\FeatureTest;

class AnalysisResultTest extends FeatureTest
{
    public function test_carries_the_payload_and_both_token_counts(): void
    {
        $result = new AnalysisResult(['summary' => 'ok'], inputTokens: 1200, outputTokens: 800);

        $this->assertSame(['summary' => 'ok'], $result->payload);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(800, $result->outputTokens);
    }

    public function test_the_analyzer_interface_returns_an_analysis_result(): void
    {
        $returnType = (new \ReflectionMethod(AiAnalyzer::class, 'analyze'))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(AnalysisResult::class, $returnType->getName());
    }

    public function test_the_analyzer_interface_accepts_groups_and_a_tier_profile(): void
    {
        $parameters = (new \ReflectionMethod(AiAnalyzer::class, 'analyze'))->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $parameters);

        $this->assertSame(['metrics', 'groups', 'excerpts', 'tier', 'adminContext'], $names);
        $this->assertSame(TierProfile::class, $parameters[3]->getType()->getName());
    }

    public function test_a_fake_analyzer_can_satisfy_the_interface(): void
    {
        $fake = new class implements AiAnalyzer
        {
            public function analyze(array $metrics, array $groups, array $excerpts,
                TierProfile $tier, ?string $adminContext = null): AnalysisResult
            {
                return new AnalysisResult(['summary' => 'faked'], 10, 20);
            }
        };

        $result = $fake->analyze([], [], [], app(TierProfileResolver::class)->for(AuditTier::AUTOMATED));

        $this->assertSame('faked', $result->payload['summary']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AnalysisResultTest`
Expected: FAIL — `Class "App\Services\AuditReport\AnalysisResult" not found`.

- [ ] **Step 3: Create AnalysisResult**

Create `app/Services/AuditReport/AnalysisResult.php`:

```php
<?php

namespace App\Services\AuditReport;

/**
 * The analysis payload plus its direct cost drivers.
 *
 * Token counts exist here because F5.12.6 requires cost per audit to be
 * measurable per tier from the first paid runs, and the model call is the
 * dominant cost. Returning a bare array made that impossible.
 */
final readonly class AnalysisResult
{
    public function __construct(
        public array $payload,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
```

- [ ] **Step 4: Change the interface**

Rewrite `app/Services/AuditReport/AiAnalyzer.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Tiers\TierProfile;

interface AiAnalyzer
{
    /**
     * @param  list<FindingGroup>  $groups              ranked, already capped to the tier's narrated_groups
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function analyze(
        array $metrics,
        array $groups,
        array $excerpts,
        TierProfile $tier,
        ?string $adminContext = null,
    ): AnalysisResult;
}
```

- [ ] **Step 5: Update ClaudeAnalyzer**

In `app/Services/AuditReport/ClaudeAnalyzer.php`:

Change the system prompt to instruct group narration, and make the score properties optional
so a not-measured dimension is not invented:

```php
private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor producing a codebase health report for a prospective client.
You are given repository metrics measured by static analysis, the ranked problem groups those
analyzers produced, and excerpts of the largest files. Ground every score, risk, and
recommendation in the provided material — never invent facts about code you have not seen.

Narrate each problem group: what it is, what it affects, and what fixing it buys the client.
Never enumerate individual findings; one lint error must never become one report item.

The metrics include computed_scores measured deterministically; treat them as authoritative —
output them verbatim as your scores. A dimension absent from computed_scores was NOT measured
on this run: omit it from your scores entirely rather than estimating it, and do not describe
it as healthy. Scores are 0-100, higher is healthier. Rank risks by impact. The fix-first plan
must be concrete and ordered by leverage.
PROMPT;
```

Extend `SCHEMA` — make the five dimensions optional, keep `overall` required, and add
`groups`:

```php
'scores' => [
    'type' => 'object',
    'properties' => [
        'structure' => ['type' => 'integer'],
        'duplication' => ['type' => 'integer'],
        'testing' => ['type' => 'integer'],
        'dependencies' => ['type' => 'integer'],
        'security_hygiene' => ['type' => 'integer'],
        'overall' => ['type' => 'integer'],
    ],
    'required' => ['overall'],
    'additionalProperties' => false,
],
'groups' => [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'properties' => [
            'rule_family' => ['type' => 'string'],
            'directory' => ['type' => 'string'],
            'severity' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low', 'info']],
            'count' => ['type' => 'integer'],
            'narrative' => [
                'type' => 'object',
                'properties' => [
                    'what' => ['type' => 'string'],
                    'affects' => ['type' => 'string'],
                    'benefit' => ['type' => 'string'],
                ],
                'required' => ['what', 'affects', 'benefit'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['rule_family', 'directory', 'severity', 'count', 'narrative'],
        'additionalProperties' => false,
    ],
],
```

Add `'groups'` to the top-level `required` list.

Rewrite `analyze()`:

```php
public function analyze(
    array $metrics,
    array $groups,
    array $excerpts,
    TierProfile $tier,
    ?string $adminContext = null,
): AnalysisResult {
    $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

    $message = $client->messages->create(
        model: (string) config('services.anthropic.model'),
        // Per-tier budget, never hardcoded (F5.12.1).
        maxTokens: $tier->aiMaxTokens,
        thinking: ['type' => 'adaptive'],
        system: self::SYSTEM_PROMPT,
        messages: [[
            'role' => 'user',
            'content' => $this->promptComposer->compose($metrics, $groups, $excerpts, $adminContext),
        ]],
        outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
    );

    if ($message->stopReason !== 'end_turn') {
        throw new AiAnalysisException('Analysis stopped early: '.$message->stopReason);
    }

    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            return new AnalysisResult(
                payload: ReportPayload::validate(json_decode($block->text, true)),
                inputTokens: (int) ($message->usage->inputTokens ?? 0),
                outputTokens: (int) ($message->usage->outputTokens ?? 0),
            );
        }
    }

    throw new AiAnalysisException('Analysis returned no text content');
}
```

Add `use App\Services\AuditReport\Tiers\TierProfile;`.

If the SDK's usage property names differ from `inputTokens` / `outputTokens`, check the
installed version before guessing:

```bash
grep -rn "inputTokens\|input_tokens" vendor/anthropic-ai/ --include=*.php | head -20
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=AnalysisResultTest`
Expected: PASS, 4 tests.

Run: `php artisan test --filter=AuditPipelineTest`
Expected: FAIL — the pipeline still calls the old three-argument `analyze()`. Task 21 fixes
this; that is the expected intermediate state.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/AnalysisResult.php app/Services/AuditReport/AiAnalyzer.php app/Services/AuditReport/ClaudeAnalyzer.php tests/Feature/Services/AnalysisResultTest.php
git commit -m "feat(audit): return token usage from analysis and narrate groups"
```

---

### Task 21: Wire the pipeline

This is where every piece from Tasks 1–20 meets. `AuditPipeline` grows from 56 lines to a
stage orchestrator, and the run persists groups, versions, and scanner provenance.

**Files:**
- Modify: `app/Services/AuditReport/AuditPipeline.php`
- Modify: `app/Services/AuditReport/AuditReportService.php` (persist versions)
- Modify: `app/Models/AuditRequest.php` (`scanner_runs` in `$fillable` and `$casts`)
- Create: `database/migrations/2026_08_02_000004_add_scanner_runs_to_audit_requests_table.php`
- Test: `tests/Feature/Services/AuditPipelineTest.php` (rewrite)

**Interfaces:**
- Consumes: everything from Tasks 1–20.
- Produces: no new public interfaces. `AuditRequest::$scanner_runs` is a JSON array of the
  `ScannerRun::toArray()` shape.

- [ ] **Step 1: Write the failing test**

Rewrite `tests/Feature/Services/AuditPipelineTest.php`. Preserve any existing tests that
cover clone failure and `markNeedsFollowup()` — they must keep passing. Add:

```php
public function test_persists_finding_groups_for_the_run(): void
{
    $request = $this->runPipelineWithFakes(groups: [
        new FindingGroup('php.injection', 'app/Http', Severity::HIGH, 37, 1480,
            [['path' => 'app/Http/A.php', 'line' => 42]], ['semgrep'], 'security_hygiene'),
    ]);

    $this->assertDatabaseHas('audit_finding_groups', [
        'audit_request_id' => $request->id,
        'rule_family' => 'php.injection',
        'count' => 37,
    ]);
}

public function test_records_scanner_provenance_on_the_request(): void
{
    $request = $this->runPipelineWithFakes();

    $runs = $request->fresh()->scanner_runs;

    $this->assertIsArray($runs);
    $this->assertSame('scc', $runs[0]['name']);
    $this->assertArrayHasKey('outcome', $runs[0]);
    $this->assertArrayHasKey('wall_ms', $runs[0]);
}

public function test_a_failed_scanner_does_not_fail_the_run(): void
{
    // F5.12.2 end to end: the report is still produced and sent.
    $request = $this->runPipelineWithFakes(failingScanners: ['semgrep']);

    $this->assertNotNull($request->fresh()->report);
    $this->assertNotNull($request->fresh()->analysis_completed_at);
}

public function test_records_the_scoring_and_payload_versions_on_the_report(): void
{
    $report = $this->runPipelineWithFakes()->fresh()->report;

    $this->assertSame(ScoreCalculator::VERSION, $report->scoring_version);
    $this->assertSame(ReportPayload::VERSION, $report->payload_schema_version);
}

public function test_narration_is_capped_to_the_tier_budget(): void
{
    config()->set('audit.tiers.diagnostic.narrated_groups', 2);

    $groups = [];
    for ($i = 0; $i < 10; $i++) {
        $groups[] = new FindingGroup("family.{$i}", 'app', Severity::HIGH, 1, 40, [], ['semgrep'], 'security_hygiene');
    }

    // The analyzer receives at most the tier's narrated_groups; the full set
    // is still persisted. Grouping is the prompt-size cost control (F5.12.2).
    $request = $this->runPipelineWithFakes(tier: AuditTier::DIAGNOSTIC, groups: $groups);

    $this->assertSame(10, $request->findingGroups()->count());
    $this->assertCount(2, $this->lastAnalyzerGroups);
}

public function test_scc_failure_falls_back_to_a_walked_inventory(): void
{
    // Spec §10: later stages must retain a basis when scc is unavailable.
    $request = $this->runPipelineWithFakes(failingScanners: ['scc']);

    $this->assertNotNull($request->fresh()->report);
    $this->assertGreaterThan(0, $request->fresh()->metrics['files_total']);
}
```

Build `runPipelineWithFakes()` as a private helper that binds a fake `AiAnalyzer` (capturing
its `$groups` argument into `$this->lastAnalyzerGroups` and returning a valid v2
`AnalysisResult`), binds fake scanners via `audit.scanner.*` as `ScannerRunnerTest` does,
points `RepositoryCloner` at a temp git repository, and returns the `AuditRequest`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AuditPipelineTest`
Expected: FAIL — `Unknown column 'scanner_runs'`, and the pipeline does not yet call the
scanner runner.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_02_000004_add_scanner_runs_to_audit_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->json('scanner_runs')->nullable()->after('pipeline_log');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn('scanner_runs');
        });
    }
};
```

In `app/Models/AuditRequest.php`, add `'scanner_runs'` to `$fillable` and
`'scanner_runs' => 'array'` to `$casts`.

- [ ] **Step 4: Rewrite AuditPipeline**

Rewrite `app/Services/AuditReport/AuditPipeline.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use App\Services\AuditRequestService;

class AuditPipeline
{
    public function __construct(
        private RepositoryCloner $cloner,
        private MetricsCollector $metricsCollector,
        private AiAnalyzer $analyzer,
        private AuditReportService $reportService,
        private AuditRequestService $requestService,
        private ScoreCalculator $scoreCalculator,
        private TierProfileResolver $tierProfileResolver,
        private ScannerRunner $scannerRunner,
        private FindingDeduplicator $deduplicator,
        private FindingGrouper $grouper,
        private SccScanner $sccScanner,
    ) {}

    public function run(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now(),
        ]);
        $auditRequest->appendPipelineLog('started', 'Analysis started');

        try {
            $this->cloner->preflight($auditRequest->repo_url);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid);
            $auditRequest->appendPipelineLog('cloned', 'Repository cloned');

            $profile = $this->tierProfileResolver->for($auditRequest->tier);
            $context = new RepoContext($path, $profile);

            // Scanners first — scc's inventory sizes the budgets for everything
            // after it, including excerpt selection (spec §3.2).
            $suite = $this->scannerRunner->run($profile->scanners, $context);
            $this->logScannerOutcomes($auditRequest, $suite);

            // scc failing must not leave later stages without a basis (spec §10).
            if ($context->inventory === null) {
                $context->withInventory($this->sccScanner->fallbackInventory($path));
                $auditRequest->appendPipelineLog('inventory', 'scc unavailable; used a walked file inventory');
            }

            $groups = $this->grouper->group($this->deduplicator->dedupe($suite->findings));

            $collected = $this->metricsCollector->collect($context);
            $metrics = $collected['metrics'];
            // Recorded by JscpdScanner on the per-run context (Task 12).
            $metrics['duplication_pct'] = (float) $context->measurement('duplication_pct', 0.0);

            $scoreSet = $this->scoreCalculator->calculate($metrics, $groups, $suite);
            $metrics['computed_scores'] = $scoreSet->toPayloadScores();
            $metrics['not_measured'] = $scoreSet->notMeasured;

            $auditRequest->update([
                'metrics' => $metrics,
                'scanner_runs' => $suite->runsAsArray(),
            ]);
            $this->persistGroups($auditRequest, $groups);
            $auditRequest->appendPipelineLog('metrics', 'Metrics collected, findings grouped and scored');

            $result = $this->analyzer->analyze(
                $metrics,
                array_slice($groups, 0, $profile->narratedGroups),
                $collected['excerpts'],
                $profile,
                $auditRequest->admin_context,
            );

            $payload = $result->payload;
            $payload['scores'] = $scoreSet->toPayloadScores();
            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            $report = $this->reportService->create($auditRequest, $payload, $scoreSet->scoringVersion);
            $this->reportService->send($report);

            $auditRequest->update(['analysis_completed_at' => now()]);
            $auditRequest->appendPipelineLog('report', 'Report stored and sent');
        } catch (AuditNotAnalyzableException $e) {
            $auditRequest->appendPipelineLog('not_analyzable', $e->getMessage());
            $this->requestService->markNeedsFollowup($auditRequest, $e->getMessage());
        } finally {
            $this->cloner->cleanup($auditRequest->uuid);
        }
    }

    private function logScannerOutcomes(AuditRequest $request, ScannerSuiteResult $suite): void
    {
        foreach ($suite->runs as $run) {
            if ($run->outcome->value === 'ok') {
                continue;
            }

            // Classified reason only — never tool output (spec §5.4).
            $request->appendPipelineLog(
                'scanner_degraded',
                "Scanner {$run->name} did not complete: {$run->reason}",
            );
        }
    }

    /** @param list<\App\Services\AuditReport\Findings\FindingGroup> $groups */
    private function persistGroups(AuditRequest $request, array $groups): void
    {
        $request->findingGroups()->delete();

        foreach ($groups as $group) {
            AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $group));
        }
    }

    // No duplicationPercentage() helper: JscpdScanner records the figure on
    // RepoContext during its own scan, and ScoreCalculator marks the
    // duplication dimension not-measured when jscpd did not run — so a
    // missing measurement can never be mistaken for a duplication-free repo.
}
```

Deleting existing groups before inserting makes a re-run idempotent — a retried audit must
not accumulate duplicate groups.

- [ ] **Step 5: Persist the versions on the report**

In `app/Services/AuditReport/AuditReportService.php`, change `create()` to accept and store
the versions:

```php
public function create(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport
{
    return AuditReport::create([
        // ... existing attributes ...
        'payload' => $payload,
        'scoring_version' => $scoringVersion,
        'payload_schema_version' => ReportPayload::VERSION,
    ]);
}
```

Update any other caller found by `grep -rn "reportService->create\|AuditReportService" app/`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=AuditPipelineTest`
Expected: PASS, including the pre-existing clone-failure tests.

Run: `php artisan test`
Expected: the full suite. Anything still failing here is a call site not yet updated — fix
those before committing.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/ app/Models/AuditRequest.php database/migrations/ tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(audit): run scanners in the pipeline and persist groups, versions, and provenance"
```

---

### Task 22: Report templates

**Files:**
- Modify: `resources/views/reports/audit.blade.php` (PDF)
- Modify: `resources/views/reports/audit-web.blade.php`
- Modify: `lang/en/*.php` (new strings — find the file the audit views already use)
- Test: `tests/Feature/Http/Controllers/AuditReportRenderTest.php`

**Interfaces:**
- Consumes: v2 payload (Task 19), `ScoreSet::$notMeasured` via `metrics.not_measured` (Task 21).
- Produces: no PHP interfaces.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Controllers/AuditReportRenderTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditTier;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditReportRenderTest extends FeatureTest
{
    private function reportWith(array $payload, array $metrics = [], AuditTier $tier = AuditTier::AUTOMATED): AuditReport
    {
        $request = AuditRequest::factory()->create(['tier' => $tier->value, 'metrics' => $metrics]);

        return AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => $payload,
            'payload_schema_version' => 2,
            'scoring_version' => 2,
            'unlocked_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'Serviceable but under-tested.',
            'scores' => ['structure' => 70, 'testing' => 40, 'overall' => 60],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [
                ['rule_family' => 'php.injection', 'directory' => 'app/Http',
                 'severity' => 'high', 'count' => 37,
                 'narrative' => ['what' => 'SQL by string interpolation.',
                                 'affects' => 'Public controllers.',
                                 'benefit' => 'Closes the main exfiltration route.']],
            ],
        ], $overrides);
    }

    public function test_renders_group_narration(): void
    {
        $report = $this->reportWith($this->payload());

        $this->get(route('audit.report.show', $report))
            ->assertOk()
            ->assertSee('SQL by string interpolation.')
            ->assertSee('Public controllers.')
            ->assertSee('Closes the main exfiltration route.');
    }

    public function test_shows_the_finding_count_not_a_finding_list(): void
    {
        $report = $this->reportWith($this->payload());

        $this->get(route('audit.report.show', $report))->assertSee('37');
    }

    public function test_marks_unmeasured_dimensions_rather_than_scoring_them(): void
    {
        // The customer must never see a score the run did not earn (spec §7.2).
        $report = $this->reportWith(
            $this->payload(),
            ['not_measured' => ['duplication', 'security_hygiene']],
        );

        $this->get(route('audit.report.show', $report))
            ->assertOk()
            ->assertSee(__('Not measured'));
    }

    public function test_a_historical_v1_report_still_renders(): void
    {
        $request = AuditRequest::factory()->create(['tier' => 'diagnostic']);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload_schema_version' => 1,
            'scoring_version' => 1,
            'unlocked_at' => now(),
            'payload' => [
                'summary' => 'Legacy report.',
                'scores' => ['structure' => 70, 'duplication' => 60, 'testing' => 40,
                             'dependencies' => 80, 'security_hygiene' => 90, 'overall' => 68],
                'risks' => [], 'fix_first_plan' => [],
            ],
        ]);

        $this->get(route('audit.report.show', $report))
            ->assertOk()
            ->assertSee('Legacy report.');
    }

    public function test_a_locked_diagnostic_report_shows_only_the_teaser_groups(): void
    {
        $payload = $this->payload();
        $payload['groups'][] = ['rule_family' => 'duplication.clone', 'directory' => 'app',
            'severity' => 'medium', 'count' => 12,
            'narrative' => ['what' => 'Duplicated blocks.', 'affects' => 'Maintenance cost.',
                            'benefit' => 'Cheaper changes.']];

        $request = AuditRequest::factory()->create(['tier' => 'diagnostic']);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => $payload,
            'payload_schema_version' => 2,
            'scoring_version' => 2,
            'unlocked_at' => null,
        ]);

        $this->get(route('audit.report.show', $report))
            ->assertOk()
            ->assertDontSee('Cheaper changes.');
    }
}
```

Confirm the real route name first: `php artisan route:list | grep -i report`. Adjust
`route('audit.report.show', ...)` and the locked-state assertions to the actual controller
behaviour — the existing `AuditReportUnlockTest` shows how locked rendering is asserted today.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AuditReportRenderTest`
Expected: FAIL — the templates render no group section.

- [ ] **Step 3: Add the group section to both templates**

In `resources/views/reports/audit-web.blade.php`, after the scores section, add:

```blade
@php
    $groups = $report->payload['groups'] ?? [];
    $visibleGroups = $isUnlocked ? $groups : array_slice($groups, 0, 2);
@endphp

@if ($groups !== [])
    <section class="report-groups">
        <h2>{{ __('What we found') }}</h2>

        @foreach ($visibleGroups as $group)
            <article class="report-group report-group--{{ $group['severity'] }}">
                <h3>
                    {{ $group['rule_family'] }}
                    <span class="report-group__location">{{ $group['directory'] }}</span>
                    <span class="report-group__count">
                        {{ trans_choice('{1} :count finding|[2,*] :count findings', $group['count'], ['count' => $group['count']]) }}
                    </span>
                </h3>

                <p><strong>{{ __('What it is') }}:</strong> {{ $group['narrative']['what'] }}</p>
                <p><strong>{{ __('What it affects') }}:</strong> {{ $group['narrative']['affects'] }}</p>
                <p><strong>{{ __('What fixing it buys you') }}:</strong> {{ $group['narrative']['benefit'] }}</p>
            </article>
        @endforeach

        @if (! $isUnlocked && count($groups) > count($visibleGroups))
            <div class="report-group__locked">
                {{ trans_choice(
                    '{1} :count more problem group is included in the full report.'
                    .'|[2,*] :count more problem groups are included in the full report.',
                    count($groups) - count($visibleGroups),
                    ['count' => count($groups) - count($visibleGroups)],
                ) }}
            </div>
        @endif
    </section>
@endif
```

Use whatever variable the template already uses for unlock state instead of `$isUnlocked` —
check the top of the existing file.

- [ ] **Step 4: Add not-measured markers to the scores section**

Wherever the template iterates score dimensions, render the not-measured case instead of a
number:

```blade
@php $notMeasured = $report->auditRequest->metrics['not_measured'] ?? []; @endphp

@foreach (['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'] as $dimension)
    <div class="score">
        <span class="score__label">{{ __('audit.dimension.'.$dimension) }}</span>

        @if (in_array($dimension, $notMeasured, true))
            <span class="score__not-measured" title="{{ __('This analysis did not run the scanner this score depends on.') }}">
                {{ __('Not measured') }}
            </span>
        @else
            <span class="score__value">{{ $report->payload['scores'][$dimension] }}</span>
        @endif
    </div>
@endforeach
```

Guard on `isset($report->payload['scores'][$dimension])` as well, so a v2 payload missing a
dimension never triggers an undefined-index notice.

- [ ] **Step 5: Mirror both changes into the PDF template**

Apply the same two blocks to `resources/views/reports/audit.blade.php`, adapted to that
template's markup. The PDF renders the full report, so it has no locked branch — render all
groups.

- [ ] **Step 6: Add the translation strings**

Find the language file the audit views already use (`grep -rn "audit\." lang/en/`) and add
the dimension labels plus `Not measured`, `What we found`, `What it is`, `What it affects`,
`What fixing it buys you`. Every user-facing string goes through the translation layer
(F5.11.3).

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=AuditReportRenderTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=AuditReport`
Expected: PASS — existing unlock and controller tests intact.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/reports/ lang/ tests/Feature/Http/Controllers/AuditReportRenderTest.php
git commit -m "feat(audit): render grouped narration and not-measured dimensions in reports"
```

---

### Task 23: Cost telemetry

Discrete columns rather than JSON, so cost per audit per tier is a `GROUP BY tier` rather
than a scan-and-decode. Q5 needs the numbers; PR16 blocks on them (spec §8.4).

**Files:**
- Create: `database/migrations/2026_08_02_000005_add_cost_telemetry_to_audit_requests_table.php`
- Modify: `app/Models/AuditRequest.php`
- Modify: `app/Services/AuditReport/AuditPipeline.php`
- Modify: `app/Services/AuditReport/RepositoryCloner.php` (expose cloned size)
- Test: `tests/Feature/Services/CostTelemetryTest.php`

**Interfaces:**
- Consumes: `AnalysisResult` (Task 20), `ScannerSuiteResult::totalWallMs()` (Task 8).
- Produces: `AuditRequest::$ai_input_tokens`, `$ai_output_tokens`, `$scanner_ms`, `$repo_size_kb` — all nullable integers.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CostTelemetryTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class CostTelemetryTest extends FeatureTest
{
    public function test_records_token_counts_from_the_analysis(): void
    {
        $request = $this->runPipelineWithFakes(inputTokens: 12_000, outputTokens: 3_400);

        $this->assertSame(12_000, $request->fresh()->ai_input_tokens);
        $this->assertSame(3_400, $request->fresh()->ai_output_tokens);
    }

    public function test_records_total_scanner_wall_time(): void
    {
        $request = $this->runPipelineWithFakes();

        $this->assertNotNull($request->fresh()->scanner_ms);
        $this->assertGreaterThanOrEqual(0, $request->fresh()->scanner_ms);
    }

    public function test_records_repository_size(): void
    {
        $request = $this->runPipelineWithFakes();

        $this->assertGreaterThan(0, $request->fresh()->repo_size_kb);
    }

    public function test_cost_per_tier_is_aggregable_in_one_query(): void
    {
        // This is the shape Q5 needs on the first 20-30 paid runs.
        AuditRequest::factory()->count(3)->create([
            'tier' => AuditTier::AUTOMATED->value,
            'ai_input_tokens' => 10_000, 'ai_output_tokens' => 2_000,
            'scanner_ms' => 5_000, 'repo_size_kb' => 40_000,
        ]);
        AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'ai_input_tokens' => 2_000, 'ai_output_tokens' => 500,
            'scanner_ms' => 900, 'repo_size_kb' => 40_000,
        ]);

        $byTier = AuditRequest::query()
            ->whereNotNull('ai_input_tokens')
            ->groupBy('tier')
            ->selectRaw('tier, avg(ai_input_tokens) as avg_in, avg(ai_output_tokens) as avg_out, avg(scanner_ms) as avg_ms')
            ->pluck('avg_in', 'tier');

        $this->assertEquals(10_000, (int) $byTier[AuditTier::AUTOMATED->value]);
        $this->assertEquals(2_000, (int) $byTier[AuditTier::DIAGNOSTIC->value]);
    }

    public function test_telemetry_is_null_on_a_run_that_never_reached_analysis(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertNull($request->ai_input_tokens);
    }
}
```

Reuse the `runPipelineWithFakes()` helper from Task 21 — extract it into a trait under
`tests/Support/` so both test classes share one implementation, and extend it to accept
`inputTokens` and `outputTokens` for the fake analyzer's `AnalysisResult`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=CostTelemetryTest`
Expected: FAIL — `Unknown column 'ai_input_tokens'`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_02_000005_add_cost_telemetry_to_audit_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->unsignedInteger('ai_input_tokens')->nullable()->after('scanner_runs');
            $table->unsignedInteger('ai_output_tokens')->nullable()->after('ai_input_tokens');
            $table->unsignedInteger('scanner_ms')->nullable()->after('ai_output_tokens');
            $table->unsignedInteger('repo_size_kb')->nullable()->after('scanner_ms');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn(['ai_input_tokens', 'ai_output_tokens', 'scanner_ms', 'repo_size_kb']);
        });
    }
};
```

Add all four to `$fillable` and cast each to `'integer'` in `app/Models/AuditRequest.php`.

- [ ] **Step 4: Expose the cloned repository size**

In `app/Services/AuditReport/RepositoryCloner.php`, add a method that measures the clone.
`clone()` already enforces `max_repo_size_mb`, so the measurement likely exists — reuse it
rather than adding a second walk:

```php
public function sizeKb(string $path): int
{
    $result = Process::run(['du', '-sk', $path]);

    return (int) strtok(trim($result->output()), "\t");
}
```

If `clone()` already computes a size for the ceiling check, return that value instead.

- [ ] **Step 5: Record telemetry in the pipeline**

In `app/Services/AuditReport/AuditPipeline::run()`, replace the final `analysis_completed_at`
update with:

```php
$auditRequest->update([
    'analysis_completed_at' => now(),
    'ai_input_tokens' => $result->inputTokens,
    'ai_output_tokens' => $result->outputTokens,
    'scanner_ms' => $suite->totalWallMs(),
    'repo_size_kb' => $this->cloner->sizeKb($path),
]);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=CostTelemetryTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=AuditPipelineTest`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/ app/Models/AuditRequest.php app/Services/AuditReport/ tests/
git commit -m "feat(audit): record per-run cost telemetry for tier unit economics"
```

---

### Task 24: Scanner degradation check and the smoke assertion

Two operator signals. §7.2's not-measured marking is visible to the **customer** but not to
the operator, and because a failed scanner never fails the run, telemetry is the only signal
that thin reports are being sold (spec §9).

**Files:**
- Create: `app/Health/Checks/ScannerDegradationCheck.php`
- Modify: `app/Providers/HealthServiceProvider.php`
- Modify: `config/health.php` (`flexpick.scanner_degradation` block)
- Modify: `app/Console/Commands/SmokeCommand.php`
- Test: `tests/Feature/Health/ScannerDegradationCheckTest.php`
- Test: `tests/Feature/Console/SmokeCommandTest.php` (extend)

**Interfaces:**
- Consumes: `AuditRequest::$scanner_runs` (Task 21), `TierProfileResolver` (Task 3).
- Produces: no new PHP interfaces.

- [ ] **Step 1: Read the existing check to match its shape**

```bash
cat app/Health/Checks/AuditPipelineFailureRateCheck.php
cat app/Health/FailureRate.php
sed -n '35,85p' config/health.php
```

Match the existing check's structure exactly — window, `min_samples`, `FailureRate`, band,
and `Result::make()->meta()` usage. Every check must have a band; a test already enforces
that.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Health/ScannerDegradationCheckTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Health\Checks\ScannerDegradationCheck;
use App\Models\AuditRequest;
use Spatie\Health\Enums\Status;
use Tests\Feature\FeatureTest;

class ScannerDegradationCheckTest extends FeatureTest
{
    private function runWith(string $outcome, int $count = 1): void
    {
        AuditRequest::factory()->count($count)->create([
            'tier' => 'automated',
            'analysis_started_at' => now()->subMinutes(10),
            'scanner_runs' => [
                ['name' => 'scc', 'version' => '3.5.0', 'wall_ms' => 100, 'finding_count' => 0,
                 'outcome' => 'ok', 'reason' => null],
                ['name' => 'semgrep', 'version' => '1.99.0', 'wall_ms' => 5000, 'finding_count' => 0,
                 'outcome' => $outcome, 'reason' => $outcome === 'ok' ? null : 'timeout'],
            ],
        ]);
    }

    public function test_is_ok_when_every_scanner_completes(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 5);
        $this->runWith('ok', 10);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status);
    }

    public function test_fails_when_degradation_exceeds_the_threshold(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 5);
        config()->set('health.flexpick.scanner_degradation.max_rate', 0.2);

        $this->runWith('ok', 2);
        $this->runWith('timeout', 8);

        $this->assertSame(Status::failed()->value, app(ScannerDegradationCheck::class)->run()->status);
    }

    public function test_stays_ok_below_the_minimum_sample_size(): void
    {
        // At pre-launch volume a couple of degraded runs must not page.
        config()->set('health.flexpick.scanner_degradation.min_samples', 20);
        $this->runWith('timeout', 3);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status);
    }

    public function test_ignores_runs_outside_the_window(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 1);
        config()->set('health.flexpick.scanner_degradation.window_hours', 1);

        AuditRequest::factory()->create([
            'tier' => 'automated',
            'analysis_started_at' => now()->subDays(3),
            'scanner_runs' => [['name' => 'semgrep', 'version' => '1', 'wall_ms' => 1,
                                'finding_count' => 0, 'outcome' => 'timeout', 'reason' => 'timeout']],
        ]);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status);
    }

    public function test_declares_a_severity_band(): void
    {
        $this->assertNotNull(config('health.flexpick.scanner_degradation.band'));
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=ScannerDegradationCheckTest`
Expected: FAIL — the check class does not exist.

- [ ] **Step 4: Add the config block**

In `config/health.php`, inside the `flexpick` array:

```php
'scanner_degradation' => [
    'window_hours' => 24,
    'min_samples' => 20,
    // Fraction of recent runs where a tier-required scanner did not complete.
    'max_rate' => 0.2,
    // A degraded scanner sells thin reports; it does not take the site down.
    'band' => 'medium',
],
```

Band `medium` means it is reported and alerted in-app but does not page and does not turn
`/health` 503 — matching the existing `paging_bands` of `critical` and `high`.

- [ ] **Step 5: Create the check**

Create `app/Health/Checks/ScannerDegradationCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Health\FailureRate;
use App\Models\AuditRequest;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Rate of runs in which a scanner did not complete.
 *
 * A failed scanner never fails the run (F5.12.2) and the customer sees the
 * affected dimension marked not-measured (spec §7.2) — but nothing tells the
 * *operator*. Without this check, selling systematically thin $49 reports
 * produces no signal at all.
 *
 * Denominator: runs that actually recorded scanner provenance. Numerator:
 * runs where at least one scanner's outcome was not `ok`.
 */
class ScannerDegradationCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.scanner_degradation');
        $since = now()->subHours((int) $config['window_hours']);

        $runs = AuditRequest::query()
            ->whereNotNull('scanner_runs')
            ->where('analysis_started_at', '>=', $since)
            ->pluck('scanner_runs');

        $total = $runs->count();
        $degraded = $runs->filter(function ($scanners): bool {
            foreach ((array) $scanners as $scanner) {
                if (($scanner['outcome'] ?? 'ok') !== 'ok') {
                    return true;
                }
            }

            return false;
        })->count();

        $rate = new FailureRate($total, $degraded, (int) $config['min_samples']);

        $result = Result::make()
            ->meta([
                'runs' => $total,
                'degraded' => $degraded,
                'rate' => $rate->value(),
                'window_hours' => (int) $config['window_hours'],
            ])
            ->shortSummary($degraded.'/'.$total.' degraded');

        return $rate->exceeds((float) $config['max_rate'])
            ? $result->failed("{$degraded} of {$total} audit runs in the last {$config['window_hours']}h had a scanner that did not complete.")
            : $result->ok();
    }
}
```

Adapt `FailureRate`'s API to whatever the existing class actually exposes — read
`app/Health/FailureRate.php` first and mirror `AuditPipelineFailureRateCheck`'s usage
exactly.

- [ ] **Step 6: Register the check**

In `app/Providers/HealthServiceProvider.php`, add `ScannerDegradationCheck::new()` to the
registered checks alongside the existing three.

- [ ] **Step 7: Add the smoke assertion**

In `app/Console/Commands/SmokeCommand.php`, add an assertion that every scanner in the
`automated` tier profile is available and reports its pinned version. Follow the existing
assertion style in that file:

```php
$this->assert('audit scanners provisioned', function (): bool {
    $profile = app(TierProfileResolver::class)->for(AuditTier::AUTOMATED);

    foreach ($profile->scanners as $name) {
        $scanner = app('audit.scanner.'.$name);

        if (! $scanner->isAvailable()) {
            $this->line("  scanner [{$name}] is not available at its configured path");

            return false;
        }
    }

    return true;
});
```

Provisioning drift is otherwise invisible: every run degrades, reports quietly thin out, and
the exit code stays 0 (spec §9.1).

- [ ] **Step 8: Extend the smoke test**

Add to `tests/Feature/Console/SmokeCommandTest.php`:

```php
public function test_fails_when_a_scanner_binary_is_missing(): void
{
    config()->set('audit.scanners.semgrep.bin', '/nonexistent/semgrep');

    $this->artisan('app:smoke')->assertFailed();
}
```

If the existing smoke tests gate some assertions on the production environment, follow the
same pattern — scanner availability should be checked everywhere, since the dev container
provisions them too (Task 14).

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=ScannerDegradationCheckTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=SmokeCommandTest`
Expected: PASS.

Run: `php artisan test --filter=Health`
Expected: PASS — including the existing test that enforces every check declares a band.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Health/ app/Providers/HealthServiceProvider.php app/Console/Commands/SmokeCommand.php config/health.php tests/Feature/
git commit -m "feat(ops): alert on scanner degradation and assert scanner provisioning in smoke"
```

---

### Task 25: Single-source pricing and the catalog rebuild

Every monetary and quota figure moves into `config/pricing.php`. Both the seeder and the
marketing export read it, so they cannot drift — which is exactly what A15 forbids
(spec §8.1, §8.5).

**Files:**
- Create: `config/pricing.php`
- Modify: `database/seeders/AuditMonetizationSeeder.php` (rewrite)
- Test: `tests/Feature/Seeders/AuditMonetizationSeederTest.php` (rewrite)

**Interfaces:**
- Consumes: `AuditTier` (Task 1).
- Produces: `config('pricing.tiers')` and `config('pricing.subscriptions')`. Tier products are
  keyed by slug and carry `tier`, `name`, `price` (cents), `features`. Subscriptions carry
  `name`, `price`, `audit_analyses_per_month`, `audit_deep_ai_credits`.

- [ ] **Step 1: Write the failing test**

Rewrite `tests/Feature/Seeders/AuditMonetizationSeederTest.php`:

```php
<?php

namespace Tests\Feature\Seeders;

use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\AuditMonetizationSeeder;
use Tests\Feature\FeatureTest;

class AuditMonetizationSeederTest extends FeatureTest
{
    private function seed(): void
    {
        $this->seed(AuditMonetizationSeeder::class);
    }

    public function test_seeds_the_three_one_time_tier_products(): void
    {
        $this->seed();

        foreach (['audit-automated' => 4900, 'audit-deep-ai' => 19900, 'audit-expert' => 99900] as $slug => $cents) {
            $product = OneTimeProduct::where('slug', $slug)->first();

            $this->assertNotNull($product, "Missing one-time product [{$slug}].");
            $this->assertTrue($product->is_active);
            $this->assertSame($cents, (int) $product->prices()->first()->price);
        }
    }

    public function test_seeds_the_pitch_subscription_grid(): void
    {
        $this->seed();

        foreach (['audit-starter' => 5900, 'audit-growth' => 14900,
                  'audit-agency' => 49900, 'audit-enterprise' => 150000] as $slug => $cents) {
            $plan = Plan::where('slug', $slug.'-monthly')->first();

            $this->assertNotNull($plan, "Missing plan [{$slug}-monthly].");
            $this->assertSame($cents, (int) $plan->prices()->first()->price);
        }
    }

    public function test_subscription_products_carry_allowance_metadata(): void
    {
        $this->seed();

        $growth = Product::where('slug', 'audit-growth')->firstOrFail();

        $this->assertSame(
            config('pricing.subscriptions.audit-growth.audit_analyses_per_month'),
            (int) $growth->metadata['audit_analyses_per_month'],
        );
        $this->assertArrayHasKey('audit_deep_ai_credits', $growth->metadata);
    }

    public function test_the_legacy_five_dollar_unlock_is_deactivated_not_deleted(): void
    {
        // Q32: existing unlocks are grandfathered. Deleting the row would
        // re-lock reports customers already paid for (spec §8.2).
        OneTimeProduct::updateOrCreate(['slug' => 'audit-report-unlock'], [
            'name' => 'Full audit report unlock', 'is_active' => true, 'is_visible' => true,
        ]);

        $this->seed();

        $unlock = OneTimeProduct::where('slug', 'audit-report-unlock')->first();

        $this->assertNotNull($unlock, 'The unlock product row must survive to back existing purchases.');
        $this->assertFalse((bool) $unlock->is_active);
        $this->assertFalse((bool) $unlock->is_visible);
    }

    public function test_legacy_subscription_plans_are_deactivated_not_deleted(): void
    {
        $this->seed();

        foreach (['audit-scale-monthly'] as $slug) {
            $plan = Plan::where('slug', $slug)->first();

            if ($plan !== null) {
                $this->assertFalse((bool) $plan->is_active);
            }
        }
    }

    public function test_is_idempotent(): void
    {
        // F5.4.9 — a second run must create no duplicates.
        $this->seed();
        $firstCount = OneTimeProduct::count() + Plan::count();

        $this->seed();

        $this->assertSame($firstCount, OneTimeProduct::count() + Plan::count());
    }

    public function test_the_seeder_holds_no_literal_money_figure(): void
    {
        // A15: every figure comes from config/pricing.php, so a price change
        // is one edit and the marketing export cannot drift from the charge.
        $source = (string) file_get_contents(database_path('seeders/AuditMonetizationSeeder.php'));

        foreach (['4900', '19900', '99900', '5900', '14900', '49900', '150000'] as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $source,
                "The seeder contains the literal [{$literal}]; prices must come from config('pricing').",
            );
        }

        $this->assertStringContainsString("config('pricing", $source);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AuditMonetizationSeederTest`
Expected: FAIL — `config/pricing.php` does not exist and the new slugs are unseeded.

- [ ] **Step 3: Create the pricing config**

Create `config/pricing.php`:

```php
<?php

/**
 * The single source of truth for every monetary and quota figure.
 *
 * Read by AuditMonetizationSeeder (which seeds products and plans) and by
 * app:export-pricing (which generates the marketing data file). Neither may
 * hold a literal figure of its own — A15 requires every figure shown anywhere
 * to match backend configuration exactly.
 *
 * Prices are in cents.
 */
return [
    'currency' => 'USD',

    'tiers' => [
        'audit-automated' => [
            'tier' => 'automated',
            'name' => 'Automated Health Report',
            'description' => 'A scanner-backed health report on one repository, with a prioritized fix-first plan.',
            'price' => 4900,
            'features' => [
                'Five static analyzers across security, duplication, and dependencies',
                'Problems grouped and explained, not a raw lint dump',
                'Prioritized fix-first plan',
                'PDF export',
            ],
        ],
        'audit-deep-ai' => [
            'tier' => 'deep_ai',
            'name' => 'Deep AI Code Review',
            'description' => 'Everything in the Automated Health Report, plus AI review of your riskiest files.',
            'price' => 19900,
            'features' => [
                'Everything in the Automated Health Report',
                'AI review of the 20-40 riskiest files',
                'Findings bound to files, with evidence and effort sizing',
                'PDF export',
            ],
        ],
        'audit-expert' => [
            'tier' => 'expert',
            'name' => 'Expert Audit',
            'description' => 'Everything in the Deep AI Code Review, reviewed and signed off by a human auditor.',
            'price' => 99900,
            'features' => [
                'Everything in the Deep AI Code Review',
                'Human expert review and sign-off',
                'False positives removed, priorities adjusted',
                'Remediation roadmap',
            ],
        ],
    ],

    'subscriptions' => [
        'audit-starter' => [
            'name' => 'Starter',
            'price' => 5900,
            'audit_analyses_per_month' => 5,
            'audit_deep_ai_credits' => 0,
            'is_popular' => false,
        ],
        'audit-growth' => [
            'name' => 'Growth',
            'price' => 14900,
            'audit_analyses_per_month' => 20,
            'audit_deep_ai_credits' => 1,
            'is_popular' => true,
        ],
        'audit-agency' => [
            'name' => 'Agency',
            'price' => 49900,
            'audit_analyses_per_month' => 75,
            'audit_deep_ai_credits' => 4,
            'is_popular' => false,
        ],
        'audit-enterprise' => [
            'name' => 'Enterprise',
            'price' => 150000,
            'audit_analyses_per_month' => 250,
            'audit_deep_ai_credits' => 15,
            'is_popular' => false,
        ],
    ],

    /**
     * Q32: retired. The row survives so already-unlocked reports keep
     * rendering; only new purchases stop.
     */
    'retired' => [
        'one_time' => ['audit-report-unlock'],
        'plans' => ['audit-starter-monthly-legacy', 'audit-growth-monthly-legacy', 'audit-scale-monthly'],
    ],
];
```

The legacy plan slugs in `retired.plans` must match what the old seeder actually created —
`audit-starter-monthly`, `audit-growth-monthly`, `audit-scale-monthly`. Since the new Starter
and Growth reuse those slugs at new prices, only `audit-scale-monthly` is genuinely orphaned.
Verify against the database before finalizing:

```bash
php artisan tinker --execute="dump(\App\Models\Plan::pluck('slug'));"
```

- [ ] **Step 4: Rewrite the seeder**

Rewrite `database/seeders/AuditMonetizationSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Seeds the tiered catalog from config/pricing.php.
 *
 * Payment-provider identifiers are intentionally NOT seeded: SaaSykit creates
 * provider products and prices on the fly at first checkout, so placeholder
 * IDs would point at nonexistent Stripe objects and break test checkouts.
 *
 * Retired products are deactivated, never deleted — an unlock row still backs
 * already-unlocked reports, and a plan with active subscriptions cannot be
 * removed without orphaning them (spec §8.1).
 */
class AuditMonetizationSeeder extends Seeder
{
    public function run(): void
    {
        $currency = Currency::where('code', config('pricing.currency'))->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();

        $this->seedTierProducts($currency);
        $this->seedSubscriptions($currency, $month);
        $this->retire();
    }

    private function seedTierProducts(Currency $currency): void
    {
        foreach (config('pricing.tiers') as $slug => $tier) {
            $product = OneTimeProduct::updateOrCreate(['slug' => $slug], [
                'name' => $tier['name'],
                'description' => $tier['description'],
                'features' => array_map(fn (string $f): array => ['feature' => $f], $tier['features']),
                'max_quantity' => 1,
                'is_active' => true,
                'is_visible' => true,
                'metadata' => ['audit_tier' => $tier['tier']],
            ]);

            $product->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $tier['price']]);
        }
    }

    private function seedSubscriptions(Currency $currency, Interval $month): void
    {
        foreach (config('pricing.subscriptions') as $slug => $subscription) {
            $product = Product::updateOrCreate(['slug' => $slug], [
                'name' => $subscription['name'],
                'description' => $subscription['audit_analyses_per_month']
                    .' automated analyses per month, with full reports and PDF export.',
                'features' => [
                    ['feature' => $subscription['audit_analyses_per_month'].' automated analyses / month'],
                    ['feature' => $subscription['audit_deep_ai_credits'].' Deep AI review credits / month'],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'Re-audit trends'],
                ],
                'is_popular' => $subscription['is_popular'],
                'metadata' => [
                    'audit_analyses_per_month' => $subscription['audit_analyses_per_month'],
                    'audit_deep_ai_credits' => $subscription['audit_deep_ai_credits'],
                ],
                'is_default' => false,
            ]);

            $plan = Plan::updateOrCreate(['slug' => $slug.'-monthly'], [
                'name' => $subscription['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $subscription['price']]);
        }
    }

    private function retire(): void
    {
        OneTimeProduct::whereIn('slug', config('pricing.retired.one_time'))
            ->update(['is_active' => false, 'is_visible' => false]);

        Plan::whereIn('slug', config('pricing.retired.plans'))
            ->update(['is_active' => false, 'is_visible' => false]);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=AuditMonetizationSeederTest`
Expected: PASS.

Run: `php artisan test --filter=AuditReportUnlockTest`
Expected: PASS — grandfathered unlocks still render. If it fails because the test seeds the
unlock product and expects it active, update the test to reflect Q32: the product is
inactive, and an *existing* `unlocked_at` still unlocks the report.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/pricing.php database/seeders/AuditMonetizationSeeder.php tests/Feature/Seeders/
git commit -m "feat(catalog): rebuild the catalog around tier products from single-source pricing"
```

---

### Task 26: Purchase-to-tier order listener

The money path for M7: a locked diagnostic's call to action buys `audit-automated`, and the
listener **re-runs the repository at the higher tier** rather than unlocking the thin one
(spec §4.2).

**Files:**
- Create: `app/Listeners/Order/HandleAuditTierOrder.php`
- Modify: `app/Providers/EventServiceProvider.php` (or wherever listeners register)
- Test: `tests/Feature/Listeners/HandleAuditTierOrderTest.php`

**Interfaces:**
- Consumes: `AuditTier` (Task 1), `config('pricing.tiers')` (Task 25).
- Produces: no new public interfaces.

- [ ] **Step 1: Read the existing unlock listener**

```bash
cat app/Listeners/Order/HandleAuditUnlockOrder.php
grep -rn "HandleAuditUnlockOrder" app/Providers/
```

Match its event, registration style, and how it locates the target `AuditRequest` from the
order. The new listener is its sibling, not a replacement — `HandleAuditUnlockOrder` stays
for in-flight legacy orders.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Listeners/HandleAuditTierOrderTest.php`:

```php
<?php

namespace Tests\Feature\Listeners;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Jobs\RunAuditPipeline;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    public function test_a_completed_tier_order_runs_the_repository_at_that_tier(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'email' => 'buyer@example.com',
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $upgraded = AuditRequest::where('email', 'buyer@example.com')
            ->where('tier', AuditTier::AUTOMATED->value)
            ->first();

        $this->assertNotNull($upgraded, 'A tier purchase must produce a run at the purchased tier.');
        $this->assertSame('https://github.com/acme/app', $upgraded->repo_url);

        Queue::assertPushed(RunAuditPipeline::class);
    }

    public function test_the_original_diagnostic_run_is_left_intact(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $this->assertSame(AuditTier::DIAGNOSTIC, $diagnostic->fresh()->tier);
    }

    public function test_a_deep_ai_order_produces_a_deep_ai_run(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-deep-ai', $diagnostic);

        $this->assertDatabaseHas('audit_requests', [
            'email' => 'buyer@example.com',
            'tier' => AuditTier::DEEP_AI->value,
        ]);
    }

    public function test_an_unrelated_product_order_is_ignored(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->completeOrderFor('some-other-product', $diagnostic);

        Queue::assertNothingPushed();
    }

    public function test_the_purchased_run_is_not_charged_against_the_free_quota(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $upgraded = AuditRequest::where('tier', AuditTier::AUTOMATED->value)->firstOrFail();

        $this->assertFalse((bool) $upgraded->free_run);
    }
}
```

Write `completeOrderFor(string $slug, AuditRequest $request)` to build an order for that
one-time product and dispatch whatever event `HandleAuditUnlockOrder` listens to — copy the
setup from `tests/Feature/Services/AuditReportUnlockTest.php`, which already exercises that
path. Confirm the pipeline job's real class name with
`grep -rn "class.*Audit.*Job\|dispatch(" app/Jobs/ app/Services/AuditRequestService.php`.

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=HandleAuditTierOrderTest`
Expected: FAIL — the listener does not exist.

- [ ] **Step 4: Create the listener**

Create `app/Listeners/Order/HandleAuditTierOrder.php`, mirroring `HandleAuditUnlockOrder`'s
event type and order-inspection helpers:

```php
<?php

namespace App\Listeners\Order;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;

/**
 * A completed order for a tier product runs the customer's repository at the
 * purchased tier.
 *
 * It creates a NEW request rather than upgrading the diagnostic in place:
 * the diagnostic ran a reduced scanner set, so its stored metrics, groups, and
 * scores are not the paid tier's — and the customer keeps the original report
 * they were shown (spec §4.2).
 */
class HandleAuditTierOrder
{
    public function __construct(private AuditRequestService $requestService) {}

    public function handle(object $event): void
    {
        foreach ($this->orderedProductSlugs($event) as $slug) {
            $tierValue = config("pricing.tiers.{$slug}.tier");

            if ($tierValue === null) {
                continue;
            }

            $source = $this->sourceRequestFor($event);

            if ($source === null) {
                continue;
            }

            $run = AuditRequest::create([
                'name' => $source->name,
                'email' => $source->email,
                'repo_url' => $source->repo_url,
                'message' => $source->message,
                'user_id' => $source->user_id,
                'tier' => $tierValue,
                'source' => $source->source,
                // A purchased run never consumes the free quota.
                'free_run' => false,
                'email_verified_at' => $source->email_verified_at,
                'marketing_consent' => $source->marketing_consent,
                'consented_at' => $source->consented_at,
            ]);

            $this->requestService->queueAnalysis($run);
        }
    }

    /** @return list<string> */
    private function orderedProductSlugs(object $event): array
    {
        // Mirror HandleAuditUnlockOrder's traversal of the order's items.
        return [];
    }

    private function sourceRequestFor(object $event): ?AuditRequest
    {
        // Mirror HandleAuditUnlockOrder's resolution of the target request.
        return null;
    }
}
```

Fill both private helpers from `HandleAuditUnlockOrder` — it already solves exactly these two
problems (which products an order contains, and which `AuditRequest` the buyer meant).
Likewise confirm the real method name on `AuditRequestService` for enqueuing a run:

```bash
grep -n "public function" app/Services/AuditRequestService.php
```

- [ ] **Step 5: Register the listener**

Register it beside `HandleAuditUnlockOrder` on the same order-completed event, following the
existing registration style.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=HandleAuditTierOrderTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=Listeners`
Expected: PASS — the legacy unlock listener is untouched.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Listeners/Order/HandleAuditTierOrder.php app/Providers/ tests/Feature/Listeners/
git commit -m "feat(catalog): run the purchased tier when a tier product order completes"
```

---

### Task 27: Tier-aware entitlements

**Files:**
- Modify: `app/Services/AuditReport/AuditEntitlementService.php`
- Test: `tests/Feature/Services/AuditSubscriptionEntitlementTest.php` (extend)

**Interfaces:**
- Consumes: `AuditTier` (Task 1), plan metadata from Task 25.
- Produces:
  ```php
  public function subscriptionAllowance(Tenant $tenant): int;          // unchanged signature
  public function deepAiCredits(Tenant $tenant): int;                   // new
  public function dashboardRunsUsedThisMonth(User $user): int;          // now tier-scoped
  public function deepAiRunsUsedThisMonth(User $user): int;             // new
  public function remainingDeepAiRuns(User $user, Tenant $tenant): int; // new
  ```

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Services/AuditSubscriptionEntitlementTest.php`:

```php
public function test_subscription_allowance_meters_automated_runs_only(): void
{
    [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 2]);

    // Three automated dashboard runs consume the allowance.
    AuditRequest::factory()->count(3)->create([
        'user_id' => $user->id, 'source' => 'dashboard',
        'tier' => AuditTier::AUTOMATED->value, 'created_at' => now(),
    ]);

    // A diagnostic run must NOT consume the paid allowance.
    AuditRequest::factory()->create([
        'user_id' => $user->id, 'source' => 'dashboard',
        'tier' => AuditTier::DIAGNOSTIC->value, 'created_at' => now(),
    ]);

    $this->assertSame(2, app(AuditEntitlementService::class)->remainingDashboardRuns($user, $tenant));
}

public function test_deep_ai_credits_are_metered_separately(): void
{
    [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 2]);

    AuditRequest::factory()->create([
        'user_id' => $user->id, 'source' => 'dashboard',
        'tier' => AuditTier::DEEP_AI->value, 'created_at' => now(),
    ]);

    $service = app(AuditEntitlementService::class);

    $this->assertSame(2, $service->deepAiCredits($tenant));
    $this->assertSame(1, $service->remainingDeepAiRuns($user, $tenant));
    // A deep_ai run does not also consume an automated run.
    $this->assertSame(5, $service->remainingDashboardRuns($user, $tenant));
}

public function test_runs_from_a_previous_month_do_not_count(): void
{
    [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 0]);

    AuditRequest::factory()->count(5)->create([
        'user_id' => $user->id, 'source' => 'dashboard',
        'tier' => AuditTier::AUTOMATED->value, 'created_at' => now()->subMonth(),
    ]);

    $this->assertSame(5, app(AuditEntitlementService::class)->remainingDashboardRuns($user, $tenant));
}

public function test_a_plan_without_deep_ai_credits_grants_none(): void
{
    [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5]);

    $this->assertSame(0, app(AuditEntitlementService::class)->remainingDeepAiRuns($user, $tenant));
}
```

Add a `subscribedTenant(array $metadata): array` helper if the file has no equivalent —
create a user, tenant, product carrying that metadata, plan, and active subscription. The
existing tests in this file already build most of it.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=AuditSubscriptionEntitlementTest`
Expected: FAIL — `deepAiCredits()` does not exist, and `dashboardRunsUsedThisMonth()` counts
every dashboard run regardless of tier.

- [ ] **Step 3: Make the service tier-aware**

In `app/Services/AuditReport/AuditEntitlementService.php`, add the import for `AuditTier` and
replace the metering methods:

```php
public function subscriptionAllowance(Tenant $tenant): int
{
    return $this->planMetadata($tenant, 'audit_analyses_per_month');
}

public function deepAiCredits(Tenant $tenant): int
{
    return $this->planMetadata($tenant, 'audit_deep_ai_credits');
}

private function planMetadata(Tenant $tenant, string $key): int
{
    return (int) $this->subscriptionService->findActiveTenantSubscriptions($tenant)
        ->map(fn ($subscription): int => (int) data_get($subscription->plan?->product?->metadata, $key, 0))
        ->max();
}

/** Automated-tier dashboard runs consume the subscription allowance. */
public function dashboardRunsUsedThisMonth(User $user): int
{
    return $this->runsThisMonth($user, AuditTier::AUTOMATED);
}

public function deepAiRunsUsedThisMonth(User $user): int
{
    return $this->runsThisMonth($user, AuditTier::DEEP_AI);
}

private function runsThisMonth(User $user, AuditTier $tier): int
{
    return AuditRequest::query()
        ->where('user_id', $user->id)
        ->where('source', 'dashboard')
        ->where('tier', $tier->value)
        ->where('created_at', '>=', now()->startOfMonth())
        ->count();
}

public function remainingDashboardRuns(User $user, Tenant $tenant): int
{
    return max(0, $this->subscriptionAllowance($tenant) - $this->dashboardRunsUsedThisMonth($user));
}

public function remainingDeepAiRuns(User $user, Tenant $tenant): int
{
    return max(0, $this->deepAiCredits($tenant) - $this->deepAiRunsUsedThisMonth($user));
}
```

`freeRunsLimit()`, `freeRunsUsed()`, `hasFreeRun()`, `consumeFreeRun()`, and
`hasAuditAccess()` are unchanged — the free quota governs `diagnostic` runs, which is what it
already did.

- [ ] **Step 4: Update the dashboard surfaces**

Find where remaining runs are shown and add the Deep AI counter alongside:

```bash
grep -rn "remainingDashboardRuns" app/ resources/views/
```

Route any new user-facing string through the translation layer (F5.11.3).

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=AuditSubscriptionEntitlementTest`
Expected: PASS.

Run: `php artisan test --filter=AuditEntitlementServiceTest`
Expected: PASS — free-quota behaviour untouched.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditReport/AuditEntitlementService.php app/ resources/views/ tests/Feature/Services/
git commit -m "feat(catalog): meter subscription allowances and Deep AI credits by tier"
```

---

### Task 28: Pricing export and marketing synchronization

A15 requires every monetary figure shown anywhere to match backend configuration exactly, and
`CLAUDE.md` requires the two apps to build and deploy independently. Backend stays
authoritative; an artisan command generates a committed data file the frontend reads, and CI
regenerates and diffs to catch drift (spec §8.5).

**Files:**
- Create: `app/Console/Commands/ExportPricingCommand.php`
- Create: `frontend/src/data/pricing.json` (generated, committed)
- Modify: `frontend/src/pages/index.astro:836` (drop the hardcoded `$5`)
- Create: `.github/workflows/pricing-drift.yml`
- Test: `tests/Feature/Console/ExportPricingCommandTest.php`

**Interfaces:**
- Consumes: `config('pricing')` (Task 25).
- Produces: `php artisan app:export-pricing [--check]`. With `--check` it exits non-zero when
  the committed file differs from what config would generate.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/ExportPricingCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use Tests\Feature\FeatureTest;

class ExportPricingCommandTest extends FeatureTest
{
    private function target(): string
    {
        return base_path('../frontend/src/data/pricing.json');
    }

    public function test_writes_every_tier_and_subscription(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = json_decode((string) file_get_contents($this->target()), true);

        $this->assertArrayHasKey('audit-automated', $exported['tiers']);
        $this->assertArrayHasKey('audit-enterprise', $exported['subscriptions']);
    }

    public function test_exports_prices_as_display_strings_and_cents(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = json_decode((string) file_get_contents($this->target()), true);

        // Both, so the marketing site never formats money itself and never
        // drifts from the figure the backend charges.
        $this->assertSame(4900, $exported['tiers']['audit-automated']['price_cents']);
        $this->assertSame('$49', $exported['tiers']['audit-automated']['price_display']);
        $this->assertSame('$1,500', $exported['subscriptions']['audit-enterprise']['price_display']);
    }

    public function test_check_mode_passes_when_the_committed_file_is_current(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $this->artisan('app:export-pricing', ['--check' => true])->assertSuccessful();
    }

    public function test_check_mode_fails_when_configuration_has_drifted(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        config()->set('pricing.tiers.audit-automated.price', 5900);

        $this->artisan('app:export-pricing', ['--check' => true])->assertFailed();
    }

    public function test_the_retired_unlock_price_is_not_exported(): void
    {
        // Q32: the $5 unlock is retired; no marketing surface may show it.
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = (string) file_get_contents($this->target());

        $this->assertStringNotContainsString('audit-report-unlock', $exported);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ExportPricingCommandTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/ExportPricingCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates the marketing site's pricing data from config/pricing.php.
 *
 * A15 requires every figure shown anywhere to match backend configuration
 * exactly. The frontend builds independently, so it reads a COMMITTED data
 * file rather than fetching from a running backend — and --check in CI catches
 * the drift that arrangement would otherwise permit (spec §8.5).
 */
class ExportPricingCommand extends Command
{
    protected $signature = 'app:export-pricing {--check : Exit non-zero if the committed file is stale}';

    protected $description = 'Export backend pricing configuration to the marketing site data file';

    public function handle(): int
    {
        $target = base_path('../frontend/src/data/pricing.json');
        $generated = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if ($this->option('check')) {
            $current = file_exists($target) ? (string) file_get_contents($target) : '';

            if ($current !== $generated) {
                $this->error('frontend/src/data/pricing.json is out of date. Run: php artisan app:export-pricing');

                return self::FAILURE;
            }

            $this->info('Pricing data is current.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $generated);
        $this->info('Wrote '.$target);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $tiers = [];

        foreach (config('pricing.tiers') as $slug => $tier) {
            $tiers[$slug] = [
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price_cents' => $tier['price'],
                'price_display' => $this->display($tier['price']),
                'features' => $tier['features'],
            ];
        }

        $subscriptions = [];

        foreach (config('pricing.subscriptions') as $slug => $subscription) {
            $subscriptions[$slug] = [
                'name' => $subscription['name'],
                'price_cents' => $subscription['price'],
                'price_display' => $this->display($subscription['price']),
                'analyses_per_month' => $subscription['audit_analyses_per_month'],
                'deep_ai_credits' => $subscription['audit_deep_ai_credits'],
                'is_popular' => $subscription['is_popular'],
            ];
        }

        return [
            '_generated' => 'php artisan app:export-pricing — do not edit by hand',
            'currency' => config('pricing.currency'),
            'tiers' => $tiers,
            'subscriptions' => $subscriptions,
        ];
    }

    private function display(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }
}
```

- [ ] **Step 4: Generate the file**

```bash
php artisan app:export-pricing
cat ../frontend/src/data/pricing.json
```

- [ ] **Step 5: Consume it in the frontend**

In `frontend/src/pages/index.astro`, import the data and replace the hardcoded price at
line 836:

```astro
---
import pricing from '~/data/pricing.json';
---

<h3 class="fp-card-title">
  {pricing.tiers['audit-automated'].name} — {pricing.tiers['audit-automated'].price_display}
</h3>
```

Then verify the frontend still builds and no literal price survives:

```bash
cd ../frontend && npm run check && npm run build
grep -rnE '\$ ?(5|49|199|999|59|149|499|1,?500)\b' src/ --include="*.astro"
```

Expected: the grep returns nothing. Any hit is a figure not reading from the data file.

- [ ] **Step 6: Add the CI drift check**

Create `.github/workflows/pricing-drift.yml`:

```yaml
name: Pricing drift

on:
  pull_request:
    paths:
      - 'backend/config/pricing.php'
      - 'frontend/src/data/pricing.json'

jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        working-directory: backend
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Verify the exported pricing data is current
        working-directory: backend
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan app:export-pricing --check
```

This is the repository's first workflow; `.github/` does not exist yet. Phase 9A owns the
full CI story — this file covers only the A15 guarantee and should be folded into the wider
pipeline when that lands.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=ExportPricingCommandTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/ExportPricingCommand.php tests/Feature/Console/ ../frontend/src/data/pricing.json ../frontend/src/pages/index.astro ../.github/workflows/pricing-drift.yml
git commit -m "feat(catalog): generate marketing pricing from backend config with CI drift detection"
```

---

## Final verification

After Task 28, run the whole gate before declaring the phase done.

- [ ] **Step 1: Full suite**

Run: `php artisan test`
Expected: every test passes. Remember the exit code is 1 regardless — read the summary line.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no **new** error category versus the pre-phase baseline (~416 accepted errors at
level 3).

- [ ] **Step 3: Formatting**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no changes remaining.

- [ ] **Step 4: End-to-end run against a real repository**

```bash
docker compose exec laravel.test php artisan tinker --execute="
  \$r = \App\Models\AuditRequest::create([
    'email' => 'you@example.com',
    'repo_url' => 'https://github.com/laravel/framework',
    'tier' => 'automated',
    'email_verified_at' => now(),
  ]);
  app(\App\Services\AuditReport\AuditPipeline::class)->run(\$r);
  dump(\$r->fresh()->only(['status','scanner_runs','ai_input_tokens','scanner_ms','repo_size_kb']));
  dump(\$r->findingGroups()->get(['rule_family','directory','severity','count','score'])->toArray());
"
```

Verify: every scanner reports `ok`, groups are populated and ranked by score, telemetry
columns are non-null, and no secret value appears anywhere in the output.

- [ ] **Step 5: Smoke gate**

Run: `php artisan app:smoke`
Expected: exit 0, including the scanner-provisioning assertion from Task 24.

- [ ] **Step 6: Exit criteria**

Walk the checklist in spec §13 and confirm each line. Anything unmet is remaining work, not
a passing phase.
