# Phase 12 — Deep AI Review ($199) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Deep AI Code Review tier — deterministic risk-file selection feeding a single AI review call that returns findings bound to files, under a per-run token budget, rendered in the report and degrading safely when it fails.

**Architecture:** A new `app/Services/AuditReport/DeepReview/` namespace holds the whole tier-2 stage. `AuditPipeline::run()` gains one conditional stage between the existing `analyzer->analyze()` call and `reportService->create()`, guarded by `$profile->deepReview !== null`. Because the tier-1 payload is already complete and valid at that point, any failure in the deep stage loses a section rather than a report. A shared `SecretFileFilter` sits one level up at `AuditReport/` because it guards excerpt collection on *every* tier, not just tier 2.

**Tech Stack:** PHP 8.4, Laravel 13, PHPUnit 11, Anthropic PHP SDK (`Anthropic\Client`), Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-08-04-deep-ai-review-design.md` (commit `61f58c8`)

## Global Constraints

- **All commands run inside Docker.** Prefix everything with `docker compose exec laravel.test`. Working directory for all paths in this plan is `/var/www/html/flexpick.net/backend`.
- **One test command at a time.** Concurrent runs collide on the test database. Never background a test run.
- **Full suite is ~150s / 839 tests.** Use `--filter` during tasks; run the full suite only at the Task 14 checkpoint.
- **Tests are PHPUnit, not Pest.** Classic `TestCase` classes extending `Tests\Feature\FeatureTest`. Scaffold with `php artisan make:test --phpunit {Name}`. The Pest snippets in `backend/AGENTS.md` do not apply.
- **No test may call the Anthropic API.** Bind a fake via `$this->app->instance(DeepReviewer::class, ...)`.
- **Never run `pint --dirty`** — the container bind-mount excludes `.git`, so it reports `passed` without checking anything. Run plain `vendor/bin/pint`, verify with `vendor/bin/pint --test`.
- **PHPStan runs against a frozen baseline** (`phpstan-baseline.neon`). New code must not add errors; the gate fails if it does.
- **Business logic belongs in Services**, not controllers or models.
- **Scores are never touched by the deep review.** `ScoreCalculator` runs before any AI stage and owns the numbers (spec D7).
- **Findings never carry matched secret content.** The `Finding` value object has no field that could hold it; keep it that way.
- Branch: `growth-retention`. Commit after every task.

---

## File Structure

**Create — `app/Services/AuditReport/DeepReview/`:**

| File | Responsibility |
| --- | --- |
| `DeepReviewProfile.php` | Per-tier deep-review budget value object |
| `SensitivePathMatcher.php` | The sensitive-domain path signal |
| `SelectedFile.php` | One chosen file with its per-signal contributions and content |
| `RiskFileSelection.php` | The selection result: files, truncation state, estimates, version |
| `RiskFileSelector.php` | Ranks candidates, applies the token budget |
| `DeepReviewer.php` | Interface for the review call |
| `ClaudeDeepReviewer.php` | Anthropic implementation: schema, one bounded retry |
| `DeepReviewPromptComposer.php` | Builds the review prompt from deterministic context only |
| `DeepReviewResult.php` | File-bound findings plus token counts |
| `DeepFindingSanitizer.php` | Hallucination guard — drops findings on unsent paths |

**Create — elsewhere:**

| File | Responsibility |
| --- | --- |
| `app/Services/AuditReport/SecretFileFilter.php` | Q17. Shared by `ExcerptCollector` (all tiers) and `RiskFileSelector` |
| `resources/views/reports/partials/deep-findings.blade.php` | Deep section markup, included by both report templates |
| `database/migrations/2026_08_04_000001_add_deep_review_to_audit_requests_table.php` | `risk_files` JSON + three telemetry columns |
| `tests/Support/FakeDeepReviewer.php` | Configurable fake mirroring `FakeAiAnalyzer` |

**Modify:**

| File | Change |
| --- | --- |
| `config/audit.php` | `deep_review` block, `secret_files.denylist`, per-tier `deep_review` sub-config |
| `app/Services/AuditReport/Tiers/TierProfile.php` | Nullable `$deepReview` property |
| `app/Services/AuditReport/Tiers/TierProfileResolver.php` | Build the sub-profile |
| `app/Services/AuditReport/Scanners/RepoContext.php` | `$secretPaths` and `$churn` with setters |
| `app/Services/AuditReport/Collectors/ExcerptCollector.php` | Apply `SecretFileFilter` |
| `app/Services/AuditReport/Collectors/HotspotCollector.php` | Record the full churn map |
| `app/Services/AuditReport/ReportPayload.php` | v3 arm; `VERSION = 3` |
| `app/Services/AuditReport/AuditPipeline.php` | The conditional deep stage |
| `app/Models/AuditRequest.php` | New fillable + casts |
| `app/Providers/AppServiceProvider.php` | Bind `DeepReviewer` |
| `resources/views/reports/audit-web.blade.php` | Include the partial |
| `resources/views/reports/audit.blade.php` | Include the partial |
| `tests/Feature/Services/TierProfileResolverTest.php` | Existing assertion about deep_ai matching automated |

---

### Task 1: Deep-review tier configuration

**Files:**
- Create: `app/Services/AuditReport/DeepReview/DeepReviewProfile.php`
- Modify: `config/audit.php`
- Modify: `app/Services/AuditReport/Tiers/TierProfile.php`
- Modify: `app/Services/AuditReport/Tiers/TierProfileResolver.php`
- Modify: `tests/Feature/Services/TierProfileResolverTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `DeepReviewProfile` with readonly `int $minFiles, $maxFiles, $fileBytes, $minFileBytes, $inputTokenBudget, $maxTokens`. `TierProfile::$deepReview` is `?DeepReviewProfile`, null for tiers that do not run deep review. Every later task gates on `$profile->deepReview !== null`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Services/TierProfileResolverTest.php`:

```php
    public function test_diagnostic_and_automated_have_no_deep_review_profile(): void
    {
        $resolver = app(TierProfileResolver::class);

        $this->assertNull($resolver->for(AuditTier::DIAGNOSTIC)->deepReview);
        $this->assertNull($resolver->for(AuditTier::AUTOMATED)->deepReview);
    }

    public function test_deep_ai_and_expert_carry_a_deep_review_profile(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT] as $tier) {
            $profile = $resolver->for($tier)->deepReview;

            $this->assertNotNull($profile, "{$tier->value} must run deep review");
            $this->assertSame(20, $profile->minFiles);
            $this->assertSame(40, $profile->maxFiles);
            $this->assertSame(12000, $profile->fileBytes);
            $this->assertSame(4000, $profile->minFileBytes);
            $this->assertSame(150000, $profile->inputTokenBudget);
            $this->assertSame(16000, $profile->maxTokens);
        }
    }

    public function test_the_floor_is_reachable_within_the_budget(): void
    {
        // If min_files at min_file_bytes cannot fit, every deep run starts
        // below the floor — a configuration error, not a runtime condition.
        $profile = app(TierProfileResolver::class)->for(AuditTier::DEEP_AI)->deepReview;

        $floorTokens = (int) ceil(
            $profile->minFiles * $profile->minFileBytes / (float) config('audit.deep_review.chars_per_token')
        ) * config('audit.deep_review.safety_margin');

        $this->assertLessThan(
            $profile->inputTokenBudget - config('audit.deep_review.overhead_tokens'),
            $floorTokens,
        );
    }
```

Also update the existing `test_deep_ai_and_expert_match_automated_in_this_phase` — rename it to `test_deep_ai_and_expert_share_the_automated_scanner_budget` and keep its body, since the tier-1 keys genuinely do still match; only `deepReview` diverges.

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=TierProfileResolverTest
```

Expected: FAIL — `Undefined property: App\Services\AuditReport\Tiers\TierProfile::$deepReview`.

- [ ] **Step 3: Create the profile value object**

`app/Services/AuditReport/DeepReview/DeepReviewProfile.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The per-run budget for one tier's deep review.
 *
 * Separate from TierProfile because it is null for the tiers that do not run
 * deep review — which is exactly what AuditPipeline gates the stage on, so no
 * tier name is ever hardcoded in the pipeline.
 */
final readonly class DeepReviewProfile
{
    public function __construct(
        public int $minFiles,
        public int $maxFiles,
        public int $fileBytes,
        public int $minFileBytes,
        public int $inputTokenBudget,
        public int $maxTokens,
    ) {}
}
```

- [ ] **Step 4: Add the configuration**

In `config/audit.php`, add a `deep_review` key to the `deep_ai` and `expert` tier arrays (leave `diagnostic` and `automated` untouched), and replace the "Phase 12 diverges" comment:

```php
        // Deep review is what separates these from `automated`; the tier-1
        // budgets below are deliberately identical.
        'deep_ai' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
            'deep_review' => [
                'min_files' => 20,
                'max_files' => 40,
                'file_bytes' => 12000,
                'min_file_bytes' => 4000,
                'input_token_budget' => 150000,
                'max_tokens' => 16000,
            ],
        ],
        'expert' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
            // F5.12.4: tier 3 is everything in tiers 1-2 plus human review, so
            // it runs the same deep review. Phase 13 adds only the delivery
            // hold and the reviewer queue.
            'deep_review' => [
                'min_files' => 20,
                'max_files' => 40,
                'file_bytes' => 12000,
                'min_file_bytes' => 4000,
                'input_token_budget' => 150000,
                'max_tokens' => 16000,
            ],
        ],
```

Then add two new top-level blocks at the end of the returned array, before the closing `];`:

```php
    'deep_review' => [
        // Bump whenever weights or signal definitions change, so a stored
        // selection can always be reproduced against the policy that made it.
        'selection_version' => 1,
        'weights' => [
            'churn' => 0.4,
            'findings' => 0.4,
            // Lowest because a path heuristic is the crudest of the three.
            'sensitive' => 0.2,
        ],
        'chars_per_token' => 3.5,
        'safety_margin' => 1.15,
        // Fixed reserve for the system prompt, metrics, groups and selection
        // rationale. A fixed figure rather than a measured one because
        // measuring the prompt requires the file list the budget decides.
        'overhead_tokens' => 8000,
        // Bounds the OUTPUT side: the primary defense against the response
        // hitting max_tokens and arriving as truncated, unparseable JSON.
        'max_findings' => 40,
        'path_exclusions' => [
            'vendor/', 'node_modules/', 'dist/', 'build/', 'storage/framework/',
            '*.min.js', '*.min.css', '*.lock', '*.map',
        ],
        'sensitive_patterns' => [
            '*auth*', '*login*', '*session*', '*token*', '*permission*', '*polic*', '*role*',
            '*payment*', '*billing*', '*checkout*', '*invoice*', '*subscription*', '*webhook*',
            '*upload*', '*crypt*', '*password*', '*secret*', '*credential*',
        ],
    ],

    'secret_files' => [
        // Q17. Unconditional, unlike the Gitleaks signal, which is only
        // present when Gitleaks actually ran.
        'denylist' => [
            '.env', '.env.*', '*.pem', '*.key', '*.p12', '*.pfx', 'id_rsa*', 'id_ed25519*',
            '.npmrc', '.netrc', '.pgpass', '*credentials*.json', '*.keystore', '*.jks',
        ],
    ],
```

- [ ] **Step 5: Wire the profile through**

In `app/Services/AuditReport/Tiers/TierProfile.php`, add the import and the property:

```php
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
```

Add as the final constructor parameter, after `$narratedGroups`:

```php
        /** Null for tiers that do not run deep review — the pipeline's gate. */
        public ?DeepReviewProfile $deepReview = null,
```

In `app/Services/AuditReport/Tiers/TierProfileResolver.php`, add the import and pass it:

```php
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
```

```php
        return new TierProfile(
            tier: $tier,
            scanners: array_values($config['scanners']),
            excerptFiles: (int) $config['excerpt_files'],
            excerptBytes: (int) $config['excerpt_bytes'],
            aiMaxTokens: (int) $config['ai_max_tokens'],
            narratedGroups: (int) $config['narrated_groups'],
            deepReview: $this->deepReviewProfile($config),
        );
    }

    /** @param array<string, mixed> $config */
    private function deepReviewProfile(array $config): ?DeepReviewProfile
    {
        $deep = $config['deep_review'] ?? null;

        if (! is_array($deep)) {
            return null;
        }

        return new DeepReviewProfile(
            minFiles: (int) $deep['min_files'],
            maxFiles: (int) $deep['max_files'],
            fileBytes: (int) $deep['file_bytes'],
            minFileBytes: (int) $deep['min_file_bytes'],
            inputTokenBudget: (int) $deep['input_token_budget'],
            maxTokens: (int) $deep['max_tokens'],
        );
    }
```

- [ ] **Step 6: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=TierProfileResolverTest
```

Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
git add config/audit.php app/Services/AuditReport/DeepReview/DeepReviewProfile.php \
  app/Services/AuditReport/Tiers/ tests/Feature/Services/TierProfileResolverTest.php
git commit -m "feat(audit): add the deep-review tier profile and configuration"
```

---

### Task 2: SecretFileFilter

**Files:**
- Create: `app/Services/AuditReport/SecretFileFilter.php`
- Test: `tests/Feature/Services/SecretFileFilterTest.php`

**Interfaces:**
- Consumes: `config('audit.secret_files.denylist')` from Task 1.
- Produces: `SecretFileFilter::excludes(string $path, array $secretPaths): bool`. `$secretPaths` is a `list<string>` of repository-relative paths Gitleaks flagged; pass `[]` when Gitleaks did not run. Tasks 3 and 6 both call this.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/SecretFileFilterTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\SecretFileFilter;
use Tests\Feature\FeatureTest;

class SecretFileFilterTest extends FeatureTest
{
    private function filter(): SecretFileFilter
    {
        return app(SecretFileFilter::class);
    }

    public function test_denylisted_basenames_are_excluded(): void
    {
        foreach (['.env', '.env.production', 'config/app.pem', 'keys/id_rsa', 'deploy/.netrc'] as $path) {
            $this->assertTrue($this->filter()->excludes($path, []), "{$path} should be excluded");
        }
    }

    public function test_ordinary_source_files_are_kept(): void
    {
        foreach (['app/Models/User.php', 'src/env.ts', 'resources/js/app.js'] as $path) {
            $this->assertFalse($this->filter()->excludes($path, []), "{$path} should be kept");
        }
    }

    public function test_gitleaks_flagged_paths_are_excluded(): void
    {
        $this->assertTrue(
            $this->filter()->excludes('app/Services/Legacy.php', ['app/Services/Legacy.php']),
        );
    }

    public function test_the_denylist_still_applies_when_gitleaks_contributed_nothing(): void
    {
        // The degradation case: Gitleaks is the enhancement, the denylist is
        // the guard that actually catches .env files.
        $this->assertTrue($this->filter()->excludes('.env', []));
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->assertTrue($this->filter()->excludes('certs/SERVER.PEM', []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=SecretFileFilterTest
```

Expected: FAIL — `Target class [App\Services\AuditReport\SecretFileFilter] does not exist.`

- [ ] **Step 3: Write the implementation**

`app/Services/AuditReport/SecretFileFilter.php`:

```php
<?php

namespace App\Services\AuditReport;

/**
 * Q17: files whose CONTENTS must never be sent to the model.
 *
 * Two independent sources, because they fail in different directions and
 * neither subsumes the other: Gitleaks is precise but conditional on having
 * run, and catches secrets hardcoded into ordinary source; the denylist is
 * unconditional and catches the .env and key files by name.
 *
 * Exclusion withholds CONTENT only. A Gitleaks hit still reaches the report as
 * a finding — Finding structurally cannot carry the matched value — so the
 * customer still learns they have a leaked credential and where.
 */
class SecretFileFilter
{
    /**
     * @param  string  $path  repository-relative
     * @param  list<string>  $secretPaths  paths Gitleaks flagged; [] when it did not run
     */
    public function excludes(string $path, array $secretPaths): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if (in_array($normalized, $secretPaths, true)) {
            return true;
        }

        $basename = basename($normalized);

        foreach ((array) config('audit.secret_files.denylist') as $pattern) {
            if (fnmatch((string) $pattern, $basename, FNM_CASEFOLD)
                || fnmatch((string) $pattern, $normalized, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=SecretFileFilterTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/SecretFileFilter.php tests/Feature/Services/SecretFileFilterTest.php
git commit -m "feat(audit): add the shared secret-file content filter (Q17)"
```

---

### Task 3: Exclude secret files from excerpts on every tier

**Files:**
- Modify: `app/Services/AuditReport/Scanners/RepoContext.php`
- Modify: `app/Services/AuditReport/Collectors/ExcerptCollector.php`
- Modify: `app/Services/AuditReport/AuditPipeline.php`
- Test: `tests/Feature/Services/Collectors/ExcerptCollectorTest.php` (extend if it exists, create if not)

**Interfaces:**
- Consumes: `SecretFileFilter::excludes()` from Task 2.
- Produces: `RepoContext::$secretPaths` (`list<string>`, default `[]`) with `withSecretPaths(array $paths): void`. `ExcerptCollector::__construct(SecretFileFilter $secretFiles)`. Task 6 reads `$context->secretPaths` too.

This is the behavior change to existing tiers described in spec §6 — diagnostic and automated stop sending secret-bearing files.

- [ ] **Step 1: Write the failing test**

Create or extend `tests/Feature/Services/Collectors/ExcerptCollectorTest.php`:

```php
<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class ExcerptCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/excerpt-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app');
        File::put($this->repo.'/.env', "APP_KEY=base64:supersecret\n");
        File::put($this->repo.'/app/User.php', "<?php\nclass User {}\n");
        File::put($this->repo.'/app/Legacy.php', "<?php\nconst TOKEN = 'sk-live-abc';\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(array $secretPaths = []): RepoContext
    {
        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
            new SccInventory(
                files: [
                    ['path' => '.env', 'loc' => 40, 'complexity' => 0],
                    ['path' => 'app/Legacy.php', 'loc' => 20, 'complexity' => 1],
                    ['path' => 'app/User.php', 'loc' => 10, 'complexity' => 1],
                ],
                languages: [],
                totalLoc: 70,
                totalComplexity: 2,
            ),
        );

        $context->withSecretPaths($secretPaths);

        return $context;
    }

    private function paths(RepoContext $context): array
    {
        return array_column(app(ExcerptCollector::class)->collect($context)['excerpts'], 'path');
    }

    public function test_denylisted_files_never_reach_the_model(): void
    {
        $this->assertNotContains('.env', $this->paths($this->context()));
    }

    public function test_gitleaks_flagged_files_never_reach_the_model(): void
    {
        $paths = $this->paths($this->context(['app/Legacy.php']));

        $this->assertNotContains('app/Legacy.php', $paths);
        $this->assertContains('app/User.php', $paths);
    }

    public function test_ordinary_files_are_still_collected(): void
    {
        $this->assertContains('app/User.php', $this->paths($this->context()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=ExcerptCollectorTest
```

Expected: FAIL — `Call to undefined method ...RepoContext::withSecretPaths()`.

- [ ] **Step 3: Add the state to RepoContext**

In `app/Services/AuditReport/Scanners/RepoContext.php`, add below `$measurements`:

```php
    /**
     * Repository-relative paths Gitleaks flagged. Populated by the pipeline
     * from the deduped findings rather than by GitleaksScanner itself, so
     * scanners stay free of cross-stage knowledge — the same reason inventory
     * flows this way.
     *
     * @var list<string>
     */
    public private(set) array $secretPaths = [];
```

And the setter, next to `withInventory()`:

```php
    /** @param list<string> $paths */
    public function withSecretPaths(array $paths): void
    {
        $this->secretPaths = $paths;
    }
```

- [ ] **Step 4: Filter in ExcerptCollector**

Rewrite `app/Services/AuditReport/Collectors/ExcerptCollector.php`:

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\SecretFileFilter;

class ExcerptCollector implements Collector
{
    public function __construct(private SecretFileFilter $secretFiles) {}

    public function name(): string
    {
        return 'excerpts';
    }

    public function collect(RepoContext $context): array
    {
        $excerpts = [];

        foreach ($context->inventory?->files ?? [] as $file) {
            if (count($excerpts) >= $context->tier->excerptFiles) {
                break;
            }

            // Q17: filter BEFORE reading, so a secret-bearing file is never
            // loaded into memory, let alone sent.
            if ($this->secretFiles->excludes($file['path'], $context->secretPaths)) {
                continue;
            }

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

Note the loop restructure: the old version sliced the inventory to `excerptFiles` first, which would now let excluded files consume slots. Breaking on the collected count keeps the tier budget meaningful.

- [ ] **Step 5: Populate secretPaths in the pipeline**

In `app/Services/AuditReport/AuditPipeline.php`, immediately after the `$groups = $this->grouper->group(...)` line and before `$collected = $this->metricsCollector->collect($context);`:

```php
            // Q17: excerpt collection (every tier) and risk-file selection
            // both read this. Derived from findings, not from the scanner.
            $context->withSecretPaths($this->secretPaths($suite));
```

And add the private method at the bottom of the class:

```php
    /** @return list<string> */
    private function secretPaths(ScannerSuiteResult $suite): array
    {
        $paths = [];

        foreach ($suite->findings as $finding) {
            if ($finding->tool === 'gitleaks') {
                $paths[] = $finding->path;
            }
        }

        return array_values(array_unique($paths));
    }
```

- [ ] **Step 6: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=ExcerptCollectorTest
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Check for collateral damage in neighbouring suites**

```bash
docker compose exec laravel.test php artisan test --filter=MetricsCollectorTest
docker compose exec laravel.test php artisan test --filter=AuditPipelineTest
```

Expected: PASS. If a test asserted an exact excerpt count that a denylisted fixture file used to fill, update the fixture or expectation — spec §6 flags this as intended.

- [ ] **Step 8: Commit**

```bash
git add app/Services/AuditReport/Scanners/RepoContext.php \
  app/Services/AuditReport/Collectors/ExcerptCollector.php \
  app/Services/AuditReport/AuditPipeline.php \
  tests/Feature/Services/Collectors/ExcerptCollectorTest.php
git commit -m "feat(audit): stop sending secret-bearing files to the model on every tier"
```

---

### Task 4: Record the full churn map

**Files:**
- Modify: `app/Services/AuditReport/Scanners/RepoContext.php`
- Modify: `app/Services/AuditReport/Collectors/HotspotCollector.php`
- Test: `tests/Feature/Services/Collectors/HotspotCollectorTest.php` (create)

**Interfaces:**
- Consumes: `RepoContext` from Task 3.
- Produces: `RepoContext::$churn` (`array<string, int>`, path → commit count) with `withChurn(array $churn): void`. Task 6's churn signal reads it.

`HotspotCollector` already parses `git log --name-only` but returns only the top 10. Selection needs churn for every candidate, and re-running `git log` over a 200-commit clone in the selector would be wasteful and could drift from this definition.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Collectors/HotspotCollectorTest.php`:

```php
<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\HotspotCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class HotspotCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/churn-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo);

        $git = 'git -c user.email=t@t -c user.name=t';
        Process::path($this->repo)->run('git init -q -b main')->throw();

        // hot.php changes three times, cold.php once.
        foreach (['a', 'b', 'c'] as $i => $content) {
            File::put($this->repo.'/hot.php', "<?php // {$content}\n");

            if ($i === 0) {
                File::put($this->repo.'/cold.php', "<?php\n");
            }

            Process::path($this->repo)->run("{$git} add -A")->throw();
            Process::path($this->repo)->run("{$git} commit -qm commit{$i}")->throw();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        return new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(
                files: [
                    ['path' => 'hot.php', 'loc' => 5, 'complexity' => 1],
                    ['path' => 'cold.php', 'loc' => 2, 'complexity' => 0],
                ],
                languages: [],
                totalLoc: 7,
                totalComplexity: 1,
            ),
        );
    }

    public function test_the_full_churn_map_is_recorded_on_the_context(): void
    {
        $context = $this->context();
        app(HotspotCollector::class)->collect($context);

        $this->assertSame(3, $context->churn['hot.php']);
        $this->assertSame(1, $context->churn['cold.php']);
    }

    public function test_the_returned_hotspots_still_exclude_single_change_files(): void
    {
        $context = $this->context();
        $hotspots = app(HotspotCollector::class)->collect($context);

        $this->assertSame(['hot.php'], array_column($hotspots, 'path'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=HotspotCollectorTest
```

Expected: FAIL — `Undefined property: ...RepoContext::$churn`.

- [ ] **Step 3: Add churn state to RepoContext**

Below `$secretPaths`:

```php
    /**
     * Commit count per repository-relative path, recorded by HotspotCollector.
     *
     * The collector returns only its top 10 for the metrics block, but
     * risk-file selection needs churn for every candidate — and re-running
     * `git log` there would both cost a second walk of a 200-commit clone and
     * risk drifting from this definition.
     *
     * @var array<string, int>
     */
    public private(set) array $churn = [];
```

And the setter:

```php
    /** @param array<string, int> $churn */
    public function withChurn(array $churn): void
    {
        $this->churn = $churn;
    }
```

- [ ] **Step 4: Record it in HotspotCollector**

In `collect()`, immediately after the `$changes = array_count_values(...)` line:

```php
        $context->withChurn(array_map('intval', $changes));
```

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=HotspotCollectorTest
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AuditReport/Scanners/RepoContext.php \
  app/Services/AuditReport/Collectors/HotspotCollector.php \
  tests/Feature/Services/Collectors/HotspotCollectorTest.php
git commit -m "feat(audit): record the full churn map for risk-file selection"
```

---

### Task 5: SensitivePathMatcher

**Files:**
- Create: `app/Services/AuditReport/DeepReview/SensitivePathMatcher.php`
- Test: `tests/Feature/Services/DeepReview/SensitivePathMatcherTest.php`

**Interfaces:**
- Consumes: `config('audit.deep_review.sensitive_patterns')` from Task 1.
- Produces: `SensitivePathMatcher::matches(string $path): bool`. Task 6 injects it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReview/SensitivePathMatcherTest.php`:

```php
<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\SensitivePathMatcher;
use Tests\Feature\FeatureTest;

class SensitivePathMatcherTest extends FeatureTest
{
    public function test_sensitive_domain_paths_match(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        foreach ([
            'app/Http/Controllers/AuthController.php',
            'app/Policies/OrderPolicy.php',
            'src/billing/checkout.ts',
            'app/Services/PaymentProviders/Stripe.php',
            'app/Http/Controllers/UploadController.php',
            'lib/crypto/password_hash.rb',
        ] as $path) {
            $this->assertTrue($matcher->matches($path), "{$path} should be sensitive");
        }
    }

    public function test_ordinary_paths_do_not_match(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        foreach ([
            'app/Models/Post.php',
            'resources/views/welcome.blade.php',
            'src/utils/format-date.ts',
        ] as $path) {
            $this->assertFalse($matcher->matches($path), "{$path} should not be sensitive");
        }
    }

    public function test_matching_is_case_insensitive_and_directory_aware(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        $this->assertTrue($matcher->matches('App/AUTH/Guard.php'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=SensitivePathMatcherTest
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the implementation**

`app/Services/AuditReport/DeepReview/SensitivePathMatcher.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The sensitive-domain selection signal: authentication, authorization,
 * payments, uploads, and secrets handling.
 *
 * Binary rather than graded. Weighting categories against each other would be
 * invention without data, and the signal already carries the lowest weight of
 * the three because a path heuristic is the crudest of them.
 */
class SensitivePathMatcher
{
    public function matches(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));

        foreach ((array) config('audit.deep_review.sensitive_patterns') as $pattern) {
            if (fnmatch(strtolower((string) $pattern), $normalized)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=SensitivePathMatcherTest
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/DeepReview/SensitivePathMatcher.php \
  tests/Feature/Services/DeepReview/SensitivePathMatcherTest.php
git commit -m "feat(audit): add the sensitive-domain path signal"
```

---

### Task 6: Risk-file ranking

**Files:**
- Create: `app/Services/AuditReport/DeepReview/SelectedFile.php`
- Create: `app/Services/AuditReport/DeepReview/RiskFileSelection.php`
- Create: `app/Services/AuditReport/DeepReview/RiskFileSelector.php`
- Test: `tests/Feature/Services/DeepReview/RiskFileSelectorTest.php`

**Interfaces:**
- Consumes: `SecretFileFilter` (Task 2), `RepoContext::$churn` and `$secretPaths` (Tasks 3–4), `SensitivePathMatcher` (Task 5), `DeepReviewProfile` (Task 1).
- Produces:
  - `SelectedFile` — readonly `string $path`, `int $rank`, `float $score`, `array $signals`, `string $content`, `int $estimatedTokens`; method `toLogArray(): array` (never includes `content`).
  - `RiskFileSelection` — readonly `list<SelectedFile> $files`, `int $candidatesConsidered`, `int $selectedBeforeBudget`, `bool $truncated`, `bool $belowFloor`, `int $estimatedInputTokens`, `int $fileBytesUsed`, `int $selectionVersion`; methods `paths(): list<string>`, `toLogArray(): array`.
  - `RiskFileSelector::select(RepoContext $context, array $dedupedFindings, DeepReviewProfile $profile): RiskFileSelection` where `$dedupedFindings` is `list<DedupedFinding>`.

This task delivers ranking with no budget applied — `maxFiles` caps the list, `truncated` is always false, `fileBytesUsed` is always `fileBytes`. Task 7 adds the budget.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReview/RiskFileSelectorTest.php`:

```php
<?php

namespace Tests\Feature\Services\DeepReview;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class RiskFileSelectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/risk-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app/Auth');
        File::ensureDirectoryExists($this->repo.'/app/Models');
        File::ensureDirectoryExists($this->repo.'/vendor/acme');

        File::put($this->repo.'/app/Auth/Guard.php', "<?php\n// guard\n");
        File::put($this->repo.'/app/Models/Post.php', "<?php\n// post\n");
        File::put($this->repo.'/app/Models/Comment.php', "<?php\n// comment\n");
        File::put($this->repo.'/vendor/acme/Lib.php', "<?php\n// vendored\n");
        File::put($this->repo.'/.env', "SECRET=1\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    /** @param array<string, int> $churn */
    private function context(array $churn = [], array $secretPaths = []): RepoContext
    {
        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(
                files: [
                    ['path' => 'app/Auth/Guard.php', 'loc' => 100, 'complexity' => 5],
                    ['path' => 'app/Models/Post.php', 'loc' => 80, 'complexity' => 3],
                    ['path' => 'app/Models/Comment.php', 'loc' => 60, 'complexity' => 2],
                    ['path' => 'vendor/acme/Lib.php', 'loc' => 900, 'complexity' => 40],
                    ['path' => '.env', 'loc' => 30, 'complexity' => 0],
                ],
                languages: [],
                totalLoc: 1170,
                totalComplexity: 50,
            ),
        );

        $context->withChurn($churn);
        $context->withSecretPaths($secretPaths);

        return $context;
    }

    private function finding(string $path, Severity $severity = Severity::HIGH): DedupedFinding
    {
        return new DedupedFinding(
            new Finding(
                tool: 'semgrep',
                ruleId: 'r1',
                ruleFamily: 'security.injection',
                severity: $severity,
                path: $path,
                line: 1,
                message: 'rule description',
                dimension: 'security_hygiene',
            ),
            ['semgrep'],
        );
    }

    private function profile()
    {
        return app(TierProfileResolver::class)->for(AuditTier::DEEP_AI)->deepReview;
    }

    private function select(RepoContext $context, array $findings = [])
    {
        return app(RiskFileSelector::class)->select($context, $findings, $this->profile());
    }

    public function test_vendored_and_secret_files_are_never_candidates(): void
    {
        $paths = $this->select($this->context(['vendor/acme/Lib.php' => 50]))->paths();

        $this->assertNotContains('vendor/acme/Lib.php', $paths);
        $this->assertNotContains('.env', $paths);
    }

    public function test_a_consensus_file_outranks_single_signal_files(): void
    {
        // Guard.php: churn + findings + sensitive. Post.php: churn only.
        $selection = $this->select(
            $this->context(['app/Auth/Guard.php' => 5, 'app/Models/Post.php' => 9]),
            [$this->finding('app/Auth/Guard.php', Severity::CRITICAL)],
        );

        $this->assertSame('app/Auth/Guard.php', $selection->files[0]->path);
    }

    public function test_a_zero_signal_normalizes_to_zero(): void
    {
        // Comment.php has no findings at all; its finding-density signal must
        // be exactly 0, not a percentile inflated by all the other zeroes.
        $selection = $this->select(
            $this->context(['app/Models/Comment.php' => 2]),
            [$this->finding('app/Models/Post.php')],
        );

        $comment = collect($selection->files)->firstWhere('path', 'app/Models/Comment.php');

        $this->assertSame(0.0, $comment->signals['findings']['normalized']);
    }

    public function test_selection_is_deterministic(): void
    {
        $run = fn () => $this->select(
            $this->context(['app/Auth/Guard.php' => 3, 'app/Models/Post.php' => 3]),
            [$this->finding('app/Models/Post.php')],
        )->paths();

        $this->assertSame($run(), $run());
    }

    public function test_ties_break_by_path_ascending(): void
    {
        // No churn, no findings, no sensitive paths — every score is 0.
        $paths = $this->select($this->context())->paths();

        $sorted = $paths;
        sort($sorted);

        $this->assertSame($sorted, $paths);
    }

    public function test_every_selected_file_records_its_signal_contributions(): void
    {
        $file = $this->select($this->context(['app/Auth/Guard.php' => 4]))->files[0];

        foreach (['churn', 'findings', 'sensitive'] as $signal) {
            $this->assertArrayHasKey('raw', $file->signals[$signal]);
            $this->assertArrayHasKey('normalized', $file->signals[$signal]);
        }
    }

    public function test_the_log_array_never_carries_file_content(): void
    {
        $logged = $this->select($this->context(['app/Auth/Guard.php' => 4]))->toLogArray();

        $this->assertStringNotContainsString('guard', json_encode($logged));
        $this->assertSame(1, $logged['selection_version']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=RiskFileSelectorTest
```

Expected: FAIL — `RiskFileSelector` does not exist.

- [ ] **Step 3: Write the value objects**

`app/Services/AuditReport/DeepReview/SelectedFile.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

final readonly class SelectedFile
{
    /**
     * @param  array<string, array{raw: float, normalized: float}>  $signals
     */
    public function __construct(
        public string $path,
        public int $rank,
        public float $score,
        public array $signals,
        public string $content,
        public int $estimatedTokens,
    ) {}

    /**
     * Persisted form. Content is deliberately absent: the selection log lives
     * on the audit request forever, and storing source there would defeat the
     * point of filtering secrets out of what we transmit.
     *
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'path' => $this->path,
            'rank' => $this->rank,
            'score' => round($this->score, 4),
            'signals' => $this->signals,
            'estimated_tokens' => $this->estimatedTokens,
        ];
    }
}
```

`app/Services/AuditReport/DeepReview/RiskFileSelection.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

final readonly class RiskFileSelection
{
    /** @param list<SelectedFile> $files */
    public function __construct(
        public array $files,
        public int $candidatesConsidered,
        public int $selectedBeforeBudget,
        public bool $truncated,
        public bool $belowFloor,
        public int $estimatedInputTokens,
        public int $fileBytesUsed,
        public int $selectionVersion,
    ) {}

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(fn (SelectedFile $file): string => $file->path, $this->files);
    }

    /** @return array<string, mixed> */
    public function toLogArray(): array
    {
        return [
            'selection_version' => $this->selectionVersion,
            'candidates_considered' => $this->candidatesConsidered,
            'selected_before_budget' => $this->selectedBeforeBudget,
            'files_reviewed' => count($this->files),
            'truncated' => $this->truncated,
            'below_floor' => $this->belowFloor,
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'file_bytes_used' => $this->fileBytesUsed,
            'files' => array_map(fn (SelectedFile $file): array => $file->toLogArray(), $this->files),
        ];
    }
}
```

- [ ] **Step 4: Write the selector**

`app/Services/AuditReport/DeepReview/RiskFileSelector.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\SecretFileFilter;

/**
 * Deterministic risk-file selection (F5.12.3).
 *
 * Three signals with incompatible units — churn x size is an unbounded
 * integer, finding density is severity-weighted counts, sensitive domain is
 * binary — are each rank-normalized to 0-1 and combined by configured weights
 * into ONE ranked list. One list rather than per-signal quotas because the
 * token budget truncates from the bottom, and truncating quotas would need a
 * second policy that could silently delete an entire signal.
 */
class RiskFileSelector
{
    public function __construct(
        private SecretFileFilter $secretFiles,
        private SensitivePathMatcher $sensitivePaths,
    ) {}

    /** @param list<DedupedFinding> $dedupedFindings */
    public function select(
        RepoContext $context,
        array $dedupedFindings,
        DeepReviewProfile $profile,
    ): RiskFileSelection {
        $candidates = $this->candidates($context);
        $density = $this->findingDensity($dedupedFindings);

        $raw = [];

        foreach ($candidates as $file) {
            $path = $file['path'];

            $raw[$path] = [
                'churn' => (float) (($context->churn[$path] ?? 0) * $file['loc']),
                'findings' => (float) ($density[$path] ?? 0),
                'sensitive' => $this->sensitivePaths->matches($path) ? 1.0 : 0.0,
            ];
        }

        $normalized = [
            'churn' => $this->normalize($raw, 'churn'),
            'findings' => $this->normalize($raw, 'findings'),
            'sensitive' => $this->normalize($raw, 'sensitive'),
        ];

        $weights = (array) config('audit.deep_review.weights');
        $scored = [];

        foreach ($raw as $path => $values) {
            $score = 0.0;

            foreach (['churn', 'findings', 'sensitive'] as $signal) {
                $score += (float) $weights[$signal] * $normalized[$signal][$path];
            }

            $scored[$path] = $score;
        }

        // Total order: score descending, then path ascending, so repeat runs
        // on the same repository state produce an identical list.
        $paths = array_keys($scored);
        usort($paths, fn (string $a, string $b): int => [$scored[$b], $a] <=> [$scored[$a], $b]);

        $ranked = array_slice($paths, 0, $profile->maxFiles);

        return $this->build($context, $ranked, $raw, $normalized, $scored, $profile, count($candidates));
    }

    /**
     * Files eligible for review: the inventory, minus vendored and generated
     * code, minus anything whose contents must never be transmitted (Q17).
     *
     * @return list<array{path: string, loc: int, complexity: int}>
     */
    private function candidates(RepoContext $context): array
    {
        $exclusions = (array) config('audit.deep_review.path_exclusions');
        $candidates = [];

        foreach ($context->inventory?->files ?? [] as $file) {
            $path = str_replace('\\', '/', $file['path']);

            if ($this->secretFiles->excludes($path, $context->secretPaths)) {
                continue;
            }

            if ($this->isExcludedPath($path, $exclusions)) {
                continue;
            }

            if (! is_file($context->path.'/'.$path)) {
                continue;
            }

            $candidates[] = $file;
        }

        return $candidates;
    }

    /** @param list<string> $exclusions */
    private function isExcludedPath(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            $exclusion = (string) $exclusion;

            if (str_ends_with($exclusion, '/')) {
                if (str_starts_with($path, $exclusion) || str_contains($path, '/'.$exclusion)) {
                    return true;
                }

                continue;
            }

            if (fnmatch($exclusion, basename($path), FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Severity-weighted finding count per path, reusing the ranking weights
     * the scores already use so one critical outranks a pile of info hits.
     *
     * @param  list<DedupedFinding>  $findings
     * @return array<string, int>
     */
    private function findingDensity(array $findings): array
    {
        $density = [];

        foreach ($findings as $deduped) {
            $path = str_replace('\\', '/', $deduped->finding->path);
            $density[$path] = ($density[$path] ?? 0) + $deduped->finding->severity->weight();
        }

        return $density;
    }

    /**
     * Rank-normalize one signal to 0-1.
     *
     * A raw value of zero maps to exactly zero, and only nonzero values are
     * ranked among themselves. Both finding density and sensitive domain are
     * mostly-zero signals, so a naive percentile would hand a file with no
     * findings a substantial score purely because most others have none —
     * making the signal close to pure noise on a clean repository.
     *
     * @param  array<string, array<string, float>>  $raw
     * @return array<string, float>
     */
    private function normalize(array $raw, string $signal): array
    {
        $values = [];

        foreach ($raw as $path => $signals) {
            if ($signals[$signal] > 0.0) {
                $values[$path] = $signals[$signal];
            }
        }

        $normalized = array_map(fn (): float => 0.0, $raw);

        if ($values === []) {
            return $normalized;
        }

        $distinct = array_values(array_unique($values));
        sort($distinct);
        $count = count($distinct);

        foreach ($values as $path => $value) {
            $rank = array_search($value, $distinct, true) + 1;
            $normalized[$path] = $rank / $count;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $ranked
     * @param  array<string, array<string, float>>  $raw
     * @param  array<string, array<string, float>>  $normalized
     * @param  array<string, float>  $scored
     */
    private function build(
        RepoContext $context,
        array $ranked,
        array $raw,
        array $normalized,
        array $scored,
        DeepReviewProfile $profile,
        int $candidatesConsidered,
    ): RiskFileSelection {
        $files = [];
        $estimated = 0;

        foreach ($ranked as $index => $path) {
            $content = (string) file_get_contents($context->path.'/'.$path, length: $profile->fileBytes);
            $tokens = $this->estimateTokens(strlen($content));
            $estimated += $tokens;

            $files[] = new SelectedFile(
                path: $path,
                rank: $index + 1,
                score: $scored[$path],
                signals: [
                    'churn' => ['raw' => $raw[$path]['churn'], 'normalized' => $normalized['churn'][$path]],
                    'findings' => ['raw' => $raw[$path]['findings'], 'normalized' => $normalized['findings'][$path]],
                    'sensitive' => ['raw' => $raw[$path]['sensitive'], 'normalized' => $normalized['sensitive'][$path]],
                ],
                content: $content,
                estimatedTokens: $tokens,
            );
        }

        return new RiskFileSelection(
            files: $files,
            candidatesConsidered: $candidatesConsidered,
            selectedBeforeBudget: count($files),
            truncated: false,
            belowFloor: count($files) < $profile->minFiles,
            estimatedInputTokens: $estimated + (int) config('audit.deep_review.overhead_tokens'),
            fileBytesUsed: $profile->fileBytes,
            selectionVersion: (int) config('audit.deep_review.selection_version'),
        );
    }

    public function estimateTokens(int $bytes): int
    {
        return (int) ceil(
            $bytes / (float) config('audit.deep_review.chars_per_token')
                * (float) config('audit.deep_review.safety_margin')
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=RiskFileSelectorTest
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AuditReport/DeepReview/ tests/Feature/Services/DeepReview/RiskFileSelectorTest.php
git commit -m "feat(audit): rank risk files by churn, finding density and sensitivity"
```

---

### Task 7: Token budget and truncation

**Files:**
- Modify: `app/Services/AuditReport/DeepReview/RiskFileSelector.php`
- Test: `tests/Feature/Services/DeepReview/RiskFileBudgetTest.php`

**Interfaces:**
- Consumes: everything from Task 6.
- Produces: no signature change. `RiskFileSelection::$truncated`, `$belowFloor`, `$fileBytesUsed` and `$estimatedInputTokens` become meaningful.

The policy, fully defined so no case is discovered at runtime:

1. Files accumulate in rank order until the next would exceed `inputTokenBudget` (less `overhead_tokens`).
2. If that leaves fewer than `minFiles`, shrink the per-file cap uniformly toward `minFileBytes` and retry — breadth beats depth, because cross-module reasoning needs to see many modules.
3. Only if `minFiles` at `minFileBytes` still overflows does the list go below the floor, and `belowFloor` records it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReview/RiskFileBudgetTest.php`:

```php
<?php

namespace Tests\Feature\Services\DeepReview;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class RiskFileBudgetTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.deep_review.overhead_tokens', 0);
        config()->set('audit.deep_review.chars_per_token', 1.0);
        config()->set('audit.deep_review.safety_margin', 1.0);

        $this->repo = storage_path('framework/testing/budget-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app');

        // 30 files of 1000 bytes each. With the config above, 1 byte = 1 token.
        for ($i = 0; $i < 30; $i++) {
            File::put($this->repo.sprintf('/app/File%02d.php', $i), str_repeat('x', 1000));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        $files = [];

        for ($i = 0; $i < 30; $i++) {
            $files[] = ['path' => sprintf('app/File%02d.php', $i), 'loc' => 30 - $i, 'complexity' => 1];
        }

        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(files: $files, languages: [], totalLoc: 465, totalComplexity: 30),
        );

        // Descending churn so ranking follows the file numbering.
        $context->withChurn(array_combine(
            array_column($files, 'path'),
            range(30, 1),
        ));

        return $context;
    }

    private function select(DeepReviewProfile $profile)
    {
        return app(RiskFileSelector::class)->select($this->context(), [], $profile);
    }

    public function test_the_budget_truncates_from_the_bottom_of_the_ranking(): void
    {
        // Budget fits 10 files of 1000 tokens; 25 would otherwise be selected.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 5, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        $this->assertCount(10, $selection->files);
        $this->assertTrue($selection->truncated);
        $this->assertSame(25, $selection->selectedBeforeBudget);
        $this->assertSame('app/File00.php', $selection->files[0]->path);
        $this->assertSame('app/File09.php', $selection->files[9]->path);
        $this->assertLessThanOrEqual(10000, $selection->estimatedInputTokens);
    }

    public function test_no_truncation_when_everything_fits(): void
    {
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 5, maxFiles: 20, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 100000, maxTokens: 16000,
        ));

        $this->assertCount(20, $selection->files);
        $this->assertFalse($selection->truncated);
        $this->assertFalse($selection->belowFloor);
    }

    public function test_per_file_bytes_shrink_rather_than_dropping_below_the_floor(): void
    {
        // 20 files must fit in 10000 tokens: impossible at 1000 bytes each,
        // achievable at 500.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 20, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        $this->assertCount(20, $selection->files);
        $this->assertFalse($selection->belowFloor);
        $this->assertSame(500, $selection->fileBytesUsed);
        $this->assertSame(500, strlen($selection->files[0]->content));
    }

    public function test_below_floor_is_recorded_when_even_the_minimum_cannot_fit(): void
    {
        // 20 files at 500 bytes needs 10000 tokens; the budget allows 3000.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 20, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 3000, maxTokens: 16000,
        ));

        $this->assertTrue($selection->belowFloor);
        $this->assertLessThan(20, count($selection->files));
        $this->assertGreaterThan(0, count($selection->files));
    }

    public function test_overhead_is_reserved_out_of_the_budget(): void
    {
        config()->set('audit.deep_review.overhead_tokens', 5000);

        $selection = $this->select(new DeepReviewProfile(
            minFiles: 1, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        // 10000 budget - 5000 overhead = 5000 for files = 5 files.
        $this->assertCount(5, $selection->files);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=RiskFileBudgetTest
```

Expected: FAIL — `truncated` is always false and no shrinking happens.

- [ ] **Step 3: Replace `build()` with the budgeted version**

In `RiskFileSelector`, replace the `build()` method with:

```php
    /**
     * @param  list<string>  $ranked
     * @param  array<string, array<string, float>>  $raw
     * @param  array<string, array<string, float>>  $normalized
     * @param  array<string, float>  $scored
     */
    private function build(
        RepoContext $context,
        array $ranked,
        array $raw,
        array $normalized,
        array $scored,
        DeepReviewProfile $profile,
        int $candidatesConsidered,
    ): RiskFileSelection {
        $available = max(0, $profile->inputTokenBudget - (int) config('audit.deep_review.overhead_tokens'));

        [$kept, $bytesUsed, $estimated] = $this->fit($context, $ranked, $profile, $available);

        $files = [];

        foreach ($kept as $index => $path) {
            $content = $this->read($context, $path, $bytesUsed);

            $files[] = new SelectedFile(
                path: $path,
                rank: $index + 1,
                score: $scored[$path],
                signals: [
                    'churn' => ['raw' => $raw[$path]['churn'], 'normalized' => $normalized['churn'][$path]],
                    'findings' => ['raw' => $raw[$path]['findings'], 'normalized' => $normalized['findings'][$path]],
                    'sensitive' => ['raw' => $raw[$path]['sensitive'], 'normalized' => $normalized['sensitive'][$path]],
                ],
                content: $content,
                estimatedTokens: $this->estimateTokens(strlen($content)),
            );
        }

        return new RiskFileSelection(
            files: $files,
            candidatesConsidered: $candidatesConsidered,
            selectedBeforeBudget: count($ranked),
            truncated: count($files) < count($ranked),
            belowFloor: count($files) < $profile->minFiles,
            estimatedInputTokens: $estimated + (int) config('audit.deep_review.overhead_tokens'),
            fileBytesUsed: $bytesUsed,
            selectionVersion: (int) config('audit.deep_review.selection_version'),
        );
    }

    /**
     * Fit the ranked list into the budget.
     *
     * Breadth beats depth: when the floor cannot be met at full per-file
     * depth, the cap shrinks toward min_file_bytes before any file is dropped,
     * because cross-module reasoning is the tier's differentiator and needs to
     * see many modules. Only when the floor is unreachable even at the minimum
     * cap does the list go short.
     *
     * @param  list<string>  $ranked
     * @return array{0: list<string>, 1: int, 2: int}
     */
    private function fit(RepoContext $context, array $ranked, DeepReviewProfile $profile, int $available): array
    {
        foreach ([$profile->fileBytes, $profile->minFileBytes] as $cap) {
            $kept = [];
            $estimated = 0;

            foreach ($ranked as $path) {
                $tokens = $this->estimateTokens(strlen($this->read($context, $path, $cap)));

                if ($estimated + $tokens > $available && $kept !== []) {
                    break;
                }

                $kept[] = $path;
                $estimated += $tokens;
            }

            if (count($kept) >= min($profile->minFiles, count($ranked)) || $cap === $profile->minFileBytes) {
                return [$kept, $cap, $estimated];
            }
        }

        return [[], $profile->minFileBytes, 0];
    }

    private function read(RepoContext $context, string $path, int $bytes): string
    {
        return (string) file_get_contents($context->path.'/'.$path, length: $bytes);
    }
```

- [ ] **Step 4: Run both selector suites**

```bash
docker compose exec laravel.test php artisan test --filter=RiskFile
```

Expected: PASS, 12 tests (7 from Task 6 plus 5 here).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/DeepReview/RiskFileSelector.php \
  tests/Feature/Services/DeepReview/RiskFileBudgetTest.php
git commit -m "feat(audit): bound risk-file selection by a per-run token budget"
```

---

### Task 8: Payload contract v3

**Files:**
- Modify: `app/Services/AuditReport/ReportPayload.php`
- Test: `tests/Feature/Services/ReportPayloadTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ReportPayload::VERSION === 3`. v3 accepts optional `file_findings` and `deep_review` keys. Tasks 10, 12 and 13 depend on these key names and shapes.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Services/ReportPayloadTest.php` (match the existing helper style in that file for building a valid base payload; if it has none, add the private `v3Payload()` helper below):

```php
    private function v3Payload(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'A summary.',
            'scores' => ['overall' => 50],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [],
        ], $overrides);
    }

    private function fileFinding(array $overrides = []): array
    {
        return array_merge([
            'path' => 'app/Auth/Guard.php',
            'line' => 42,
            'title' => 'Authorization check can be bypassed',
            'severity' => 'critical',
            'category' => 'authorization',
            'evidence' => 'The guard returns true when the role is null.',
            'recommendation' => 'Deny by default.',
            'effort' => 'M',
            'related_paths' => ['app/Services/Billing.php'],
        ], $overrides);
    }

    public function test_version_is_three(): void
    {
        $this->assertSame(3, ReportPayload::VERSION);
    }

    public function test_v3_accepts_a_payload_with_no_deep_section(): void
    {
        // Degradation must produce a VALID payload, not a rejected one.
        $payload = $this->v3Payload();

        $this->assertSame($payload, ReportPayload::validate($payload, 3));
    }

    public function test_v3_accepts_file_findings_and_deep_review_metadata(): void
    {
        $payload = $this->v3Payload([
            'file_findings' => [$this->fileFinding()],
            'deep_review' => [
                'files_selected' => 40,
                'files_reviewed' => 28,
                'truncated' => true,
                'selection_version' => 1,
                'degraded' => false,
            ],
        ]);

        $this->assertSame($payload, ReportPayload::validate($payload, 3));
    }

    public function test_v3_rejects_a_malformed_file_finding(): void
    {
        foreach ([
            ['path' => null],
            ['title' => null],
            ['severity' => 'catastrophic'],
            ['category' => 'vibes'],
            ['evidence' => null],
            ['recommendation' => null],
            ['effort' => 'XL'],
            ['related_paths' => 'app/Foo.php'],
        ] as $override) {
            try {
                ReportPayload::validate(
                    $this->v3Payload(['file_findings' => [$this->fileFinding($override)]]),
                    3,
                );
                $this->fail('Expected rejection for '.json_encode($override));
            } catch (AiAnalysisException $e) {
                $this->assertStringContainsString('file finding', $e->getMessage());
            }
        }
    }

    public function test_v3_rejects_malformed_deep_review_metadata(): void
    {
        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate(
            $this->v3Payload(['deep_review' => ['files_reviewed' => 'twenty']]),
            3,
        );
    }

    public function test_v1_and_v2_payloads_still_validate(): void
    {
        // Stored reports depend on this; AuditReport rows carry their own
        // payload_schema_version and are validated against it on view.
        $v1 = $this->v3Payload(['scores' => [
            'structure' => 50, 'duplication' => 50, 'testing' => 50,
            'dependencies' => 50, 'security_hygiene' => 50, 'overall' => 50,
        ]]);

        $this->assertSame($v1, ReportPayload::validate($v1, 1));
        $this->assertSame($this->v3Payload(), ReportPayload::validate($this->v3Payload(), 2));
    }
```

Ensure `use App\Exceptions\AiAnalysisException;` and `use App\Services\AuditReport\ReportPayload;` are imported.

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=ReportPayloadTest
```

Expected: FAIL — `Unknown payload schema version: 3`.

- [ ] **Step 3: Add the v3 arm**

In `app/Services/AuditReport/ReportPayload.php`, change the version constant and add the constants, match arm, and validators:

```php
    /** Bump when the payload contract changes. */
    public const VERSION = 3;
```

Add next to `V1_SCORES`:

```php
    private const FINDING_CATEGORIES = ['business_logic', 'authorization', 'architecture', 'security'];

    private const EFFORTS = ['S', 'M', 'L'];
```

Add to the `match`:

```php
            3 => self::validateV3($payload),
```

And the new methods:

```php
    private static function validateV3(array $payload): array
    {
        $payload = self::validateV2($payload);

        // Both deep keys are OPTIONAL by design. The validator is context-free
        // and must not learn about tiers — and degradation has to yield a
        // VALID payload with file_findings absent (spec D1).
        foreach ($payload['file_findings'] ?? [] as $finding) {
            self::validateFileFinding($finding);
        }

        if (array_key_exists('deep_review', $payload)) {
            self::validateDeepReviewMeta($payload['deep_review']);
        }

        return $payload;
    }

    private static function validateFileFinding(mixed $finding): void
    {
        if (! is_array($finding)
            || ! is_string($finding['path'] ?? null)
            || ! is_string($finding['title'] ?? null)
            || ! is_string($finding['evidence'] ?? null)
            || ! is_string($finding['recommendation'] ?? null)
            || ! in_array($finding['severity'] ?? null, self::SEVERITIES, true)
            || ! in_array($finding['category'] ?? null, self::FINDING_CATEGORIES, true)
            || ! in_array($finding['effort'] ?? null, self::EFFORTS, true)) {
            throw new AiAnalysisException('Malformed file finding entry');
        }

        if (array_key_exists('line', $finding) && $finding['line'] !== null && ! is_int($finding['line'])) {
            throw new AiAnalysisException('Malformed file finding entry: line');
        }

        if (! is_array($finding['related_paths'] ?? [])) {
            throw new AiAnalysisException('Malformed file finding entry: related_paths');
        }

        foreach ($finding['related_paths'] ?? [] as $related) {
            if (! is_string($related)) {
                throw new AiAnalysisException('Malformed file finding entry: related_paths');
            }
        }
    }

    private static function validateDeepReviewMeta(mixed $meta): void
    {
        if (! is_array($meta)) {
            throw new AiAnalysisException('Malformed deep_review metadata');
        }

        foreach (['files_selected', 'files_reviewed', 'selection_version'] as $key) {
            if (array_key_exists($key, $meta) && ! is_int($meta[$key])) {
                throw new AiAnalysisException("Malformed deep_review metadata: {$key}");
            }
        }

        foreach (['truncated', 'degraded'] as $key) {
            if (array_key_exists($key, $meta) && ! is_bool($meta[$key])) {
                throw new AiAnalysisException("Malformed deep_review metadata: {$key}");
            }
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=ReportPayloadTest
```

Expected: PASS.

- [ ] **Step 5: Verify stored-report validation still works**

```bash
docker compose exec laravel.test php artisan test --filter=AuditPipelineTest
```

Expected: PASS. `test_records_the_scoring_and_payload_versions_on_the_report` asserts the stored version — update its expected value from 2 to 3.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AuditReport/ReportPayload.php tests/Feature/Services/ReportPayloadTest.php \
  tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(audit): extend the payload contract to v3 for file-bound findings"
```

---

### Task 9: The deep reviewer

**Files:**
- Create: `app/Services/AuditReport/DeepReview/DeepReviewer.php`
- Create: `app/Services/AuditReport/DeepReview/DeepReviewResult.php`
- Create: `app/Services/AuditReport/DeepReview/ClaudeDeepReviewer.php`
- Create: `tests/Support/FakeDeepReviewer.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Services/DeepReview/DeepReviewResultTest.php`

**Interfaces:**
- Consumes: `RiskFileSelection` (Tasks 6–7), `DeepReviewProfile` (Task 1), `FindingGroup` (existing).
- Produces:
  - `DeepReviewResult` — readonly `list<array<string,mixed>> $findings`, `int $inputTokens`, `int $outputTokens`.
  - `DeepReviewer::review(array $metrics, array $groups, RiskFileSelection $selection, DeepReviewProfile $profile): DeepReviewResult`.
  - `FakeDeepReviewer` — constructor `(array $findings = [], ?\Throwable $throws = null)`, records `$receivedSelection`. Tasks 10 and 12 use it.

Only `DeepReviewResult` and `FakeDeepReviewer` are unit-tested here. `ClaudeDeepReviewer` performs the network call and is exercised only through the fake, exactly as `ClaudeAnalyzer` is today.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReview/DeepReviewResultTest.php`:

```php
<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewResult;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeDeepReviewer;

class DeepReviewResultTest extends FeatureTest
{
    public function test_the_container_resolves_a_deep_reviewer(): void
    {
        $this->assertInstanceOf(DeepReviewer::class, app(DeepReviewer::class));
    }

    public function test_the_result_carries_findings_and_token_counts(): void
    {
        $result = new DeepReviewResult(
            findings: [['path' => 'app/Auth/Guard.php', 'title' => 'Bypass']],
            inputTokens: 1200,
            outputTokens: 340,
        );

        $this->assertCount(1, $result->findings);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(340, $result->outputTokens);
    }

    public function test_the_fake_can_be_configured_to_throw(): void
    {
        $this->expectException(\RuntimeException::class);

        (new FakeDeepReviewer(throws: new \RuntimeException('boom')))
            ->review([], [], $this->emptySelection(), $this->profile());
    }

    private function emptySelection()
    {
        return new \App\Services\AuditReport\DeepReview\RiskFileSelection(
            files: [], candidatesConsidered: 0, selectedBeforeBudget: 0,
            truncated: false, belowFloor: true, estimatedInputTokens: 0,
            fileBytesUsed: 12000, selectionVersion: 1,
        );
    }

    private function profile()
    {
        return app(\App\Services\AuditReport\Tiers\TierProfileResolver::class)
            ->for(\App\Constants\AuditTier::DEEP_AI)->deepReview;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewResultTest
```

Expected: FAIL — `DeepReviewer` does not exist.

- [ ] **Step 3: Write the contract and result**

`app/Services/AuditReport/DeepReview/DeepReviewer.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\FindingGroup;

/**
 * An interface with a separate implementation for the same reason AiAnalyzer
 * has one: risk T1 (a provider change must not stop the pipeline), and so the
 * stage is fakeable without a network call.
 */
interface DeepReviewer
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups  ranked, already capped to the tier budget
     */
    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult;
}
```

`app/Services/AuditReport/DeepReview/DeepReviewResult.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * File-bound findings plus this stage's own cost drivers.
 *
 * Token counts are kept separate from AnalysisResult's so the MARGINAL cost of
 * tier 2 stays measurable — summing them would make F5.12.6's question ("what
 * does a $199 report cost us?") unanswerable.
 */
final readonly class DeepReviewResult
{
    /** @param list<array<string, mixed>> $findings */
    public function __construct(
        public array $findings,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
```

- [ ] **Step 4: Write the Anthropic implementation**

`app/Services/AuditReport/DeepReview/ClaudeDeepReviewer.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

use Anthropic\Client;
use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\Findings\FindingGroup;

class ClaudeDeepReviewer implements DeepReviewer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor performing a deep review of the riskiest files in a client's
repository. You are given the file contents, the deterministic metrics for the repository, and
the ranked problem groups its static analyzers produced.

Review the SOURCE. Report findings bound to specific files, covering business logic,
authorization, architectural risk, and security. Every finding must cite concrete evidence from
the code you were shown — never speculate about code you have not seen, and never report a
finding against a file that was not provided to you.

Prefer findings that span modules: a controller trusting a value another file never validates is
worth more than a single-file style issue, and the scanners have already covered what a linter
can find. Where the problem groups flag something in a file you can see, confirm or refute it
against the actual source rather than restating it.

Size effort honestly: S is under an hour, M is up to a day, L is more. Rank by severity.
PROMPT;

    public function __construct(private DeepReviewPromptComposer $composer) {}

    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        if ($selection->files === []) {
            return new DeepReviewResult(findings: [], inputTokens: 0, outputTokens: 0);
        }

        try {
            return $this->call($metrics, $groups, $selection, $profile);
        } catch (AiAnalysisException $e) {
            // Schema and contract failures fail identically on retry; retrying
            // only doubles the token spend.
            throw $e;
        } catch (\Throwable $e) {
            // One bounded retry for transport-level failures only.
            usleep(2_000_000);

            return $this->call($metrics, $groups, $selection, $profile);
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     */
    private function call(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: $profile->maxTokens,
            thinking: ['type' => 'adaptive'],
            system: self::SYSTEM_PROMPT,
            messages: [[
                'role' => 'user',
                'content' => $this->composer->compose($metrics, $groups, $selection),
            ]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new AiAnalysisException('Deep review stopped early: '.$message->stopReason);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);

                if (! is_array($decoded) || ! is_array($decoded['file_findings'] ?? null)) {
                    throw new AiAnalysisException('Deep review returned no file_findings');
                }

                return new DeepReviewResult(
                    findings: array_values($decoded['file_findings']),
                    inputTokens: (int) ($message->usage->inputTokens ?? 0),
                    outputTokens: (int) ($message->usage->outputTokens ?? 0),
                );
            }
        }

        throw new AiAnalysisException('Deep review returned no text content');
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_findings' => [
                    'type' => 'array',
                    // Bounds the output so the response cannot hit max_tokens
                    // and arrive as truncated, unparseable JSON.
                    'maxItems' => (int) config('audit.deep_review.max_findings'),
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'line' => ['type' => ['integer', 'null']],
                            'title' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low', 'info']],
                            'category' => ['type' => 'string', 'enum' => ['business_logic', 'authorization', 'architecture', 'security']],
                            'evidence' => ['type' => 'string'],
                            'recommendation' => ['type' => 'string'],
                            'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                            'related_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['path', 'title', 'severity', 'category', 'evidence', 'recommendation', 'effort', 'related_paths'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['file_findings'],
            'additionalProperties' => false,
        ];
    }
}
```

- [ ] **Step 5: Write the prompt composer**

Create `app/Services/AuditReport/DeepReview/DeepReviewPromptComposer.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\FindingGroup;

/**
 * Deterministic context only (spec D6): metrics, ranked groups, the selection
 * rationale, and the file contents.
 *
 * The tier-1 NARRATIVE is deliberately absent. It is another model's opinion,
 * and models anchor hard on prior framing — feeding it in would buy
 * elaboration of the $49 report and destroy the ability to read agreement
 * between the two sections as corroboration rather than echo.
 */
class DeepReviewPromptComposer
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     */
    public function compose(array $metrics, array $groups, RiskFileSelection $selection): string
    {
        return implode("\n", [
            'Repository metrics (JSON):',
            json_encode($metrics, JSON_PRETTY_PRINT),
            '',
            'Problem groups the static analyzers produced, ranked by severity and count:',
            $this->renderGroups($groups),
            '',
            sprintf(
                'The %d riskiest files, selected deterministically by churn x size, scanner-finding density, and sensitive-domain path heuristics. Rank 1 is riskiest.',
                count($selection->files),
            ),
            $this->renderFiles($selection),
            '',
            'Review these files and report findings bound to them.',
        ]);
    }

    /** @param list<FindingGroup> $groups */
    private function renderGroups(array $groups): string
    {
        if ($groups === []) {
            return "\n[no problem groups were produced for this run]\n";
        }

        $rendered = '';

        foreach ($groups as $group) {
            $rendered .= sprintf(
                "\n- %s in %s — %d finding(s), severity %s\n",
                $group->ruleFamily,
                $group->directory,
                $group->count,
                $group->severity->value,
            );
        }

        return $rendered;
    }

    private function renderFiles(RiskFileSelection $selection): string
    {
        $rendered = '';

        foreach ($selection->files as $file) {
            $signals = [];

            foreach ($file->signals as $name => $signal) {
                if ($signal['normalized'] > 0.0) {
                    $signals[] = $name;
                }
            }

            $rendered .= sprintf(
                "\n===== rank %d: %s (selected for: %s) =====\n%s\n",
                $file->rank,
                $file->path,
                $signals !== [] ? implode(', ', $signals) : 'inventory order',
                $file->content,
            );
        }

        return $rendered;
    }
}
```

- [ ] **Step 6: Write the fake and bind the implementation**

`tests/Support/FakeDeepReviewer.php`:

```php
<?php

namespace Tests\Support;

use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
use App\Services\AuditReport\DeepReview\DeepReviewResult;
use App\Services\AuditReport\DeepReview\RiskFileSelection;
use Throwable;

class FakeDeepReviewer implements DeepReviewer
{
    public ?RiskFileSelection $receivedSelection = null;

    public ?array $receivedMetrics = null;

    public ?array $receivedGroups = null;

    /** @param list<array<string, mixed>> $findings */
    public function __construct(
        public array $findings = [],
        public ?Throwable $throws = null,
    ) {}

    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;
        $this->receivedGroups = $groups;
        $this->receivedSelection = $selection;

        return new DeepReviewResult(
            findings: $this->findings,
            inputTokens: 2000,
            outputTokens: 400,
        );
    }
}
```

In `app/Providers/AppServiceProvider.php`, add the imports and bind next to the `AiAnalyzer` binding:

```php
use App\Services\AuditReport\DeepReview\ClaudeDeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewer;
```

```php
        $this->app->bind(DeepReviewer::class, ClaudeDeepReviewer::class);
```

- [ ] **Step 7: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewResultTest
```

Expected: PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
git add app/Services/AuditReport/DeepReview/ app/Providers/AppServiceProvider.php \
  tests/Support/FakeDeepReviewer.php tests/Feature/Services/DeepReview/DeepReviewResultTest.php
git commit -m "feat(audit): add the deep reviewer contract and Anthropic implementation"
```

---

### Task 10: Hallucination guard

**Files:**
- Create: `app/Services/AuditReport/DeepReview/DeepFindingSanitizer.php`
- Test: `tests/Feature/Services/DeepReview/DeepFindingSanitizerTest.php`

**Interfaces:**
- Consumes: `RiskFileSelection::paths()` (Task 6), `ReportPayload` v3 shape (Task 8).
- Produces: `DeepFindingSanitizer::sanitize(array $findings, array $reviewedPaths, array $inventoryPaths): array` returning `array{findings: list<array<string,mixed>>, dropped: int, strippedRelated: int}`. Task 12 consumes all three keys.

A finding bound to a file the model never saw is fabricated by definition. This lives here rather than in `ReportPayload::validate()` because it needs run context the validator deliberately does not have.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReview/DeepFindingSanitizerTest.php`:

```php
<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\DeepFindingSanitizer;
use Tests\Feature\FeatureTest;

class DeepFindingSanitizerTest extends FeatureTest
{
    private function finding(string $path, array $related = []): array
    {
        return [
            'path' => $path,
            'line' => 10,
            'title' => 'A finding',
            'severity' => 'high',
            'category' => 'authorization',
            'evidence' => 'evidence',
            'recommendation' => 'fix it',
            'effort' => 'M',
            'related_paths' => $related,
        ];
    }

    public function test_findings_on_reviewed_files_survive(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php'],
        );

        $this->assertCount(1, $result['findings']);
        $this->assertSame(0, $result['dropped']);
    }

    public function test_a_finding_on_a_file_that_was_never_sent_is_dropped(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Never/Sent.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Never/Sent.php'],
        );

        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['dropped']);
    }

    public function test_related_paths_outside_the_inventory_are_stripped(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php', ['app/Services/Billing.php', 'app/Imaginary.php'])],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Services/Billing.php'],
        );

        $this->assertSame(['app/Services/Billing.php'], $result['findings'][0]['related_paths']);
        $this->assertSame(1, $result['strippedRelated']);
    }

    public function test_a_related_path_need_not_have_been_reviewed(): void
    {
        // Cross-module findings reference files the model saw referenced but
        // was not sent; those are legitimate as long as the file exists.
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php', ['app/Services/Billing.php'])],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Services/Billing.php'],
        );

        $this->assertSame(['app/Services/Billing.php'], $result['findings'][0]['related_paths']);
        $this->assertSame(0, $result['strippedRelated']);
    }

    public function test_the_findings_list_is_reindexed(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Never/Sent.php'), $this->finding('app/Auth/Guard.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php'],
        );

        $this->assertArrayHasKey(0, $result['findings']);
        $this->assertCount(1, $result['findings']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=DeepFindingSanitizerTest
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the implementation**

`app/Services/AuditReport/DeepReview/DeepFindingSanitizer.php`:

```php
<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The hallucination guard.
 *
 * A finding bound to a file the model was never sent is fabricated by
 * definition. Related paths are held to a weaker standard — the model may
 * legitimately reference a file it saw REFERENCED but was not given — so those
 * only have to exist in the repository inventory.
 *
 * This is not part of ReportPayload::validate() because it needs run context
 * the validator deliberately does not have.
 */
class DeepFindingSanitizer
{
    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $reviewedPaths
     * @param  list<string>  $inventoryPaths
     * @return array{findings: list<array<string, mixed>>, dropped: int, strippedRelated: int}
     */
    public function sanitize(array $findings, array $reviewedPaths, array $inventoryPaths): array
    {
        $reviewed = array_flip($reviewedPaths);
        $inventory = array_flip($inventoryPaths);

        $kept = [];
        $dropped = 0;
        $stripped = 0;

        foreach ($findings as $finding) {
            $path = str_replace('\\', '/', (string) ($finding['path'] ?? ''));

            if (! isset($reviewed[$path])) {
                $dropped++;

                continue;
            }

            $related = [];

            foreach ($finding['related_paths'] ?? [] as $candidate) {
                $candidate = str_replace('\\', '/', (string) $candidate);

                if (isset($inventory[$candidate])) {
                    $related[] = $candidate;

                    continue;
                }

                $stripped++;
            }

            $finding['path'] = $path;
            $finding['related_paths'] = $related;
            $kept[] = $finding;
        }

        return ['findings' => $kept, 'dropped' => $dropped, 'strippedRelated' => $stripped];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=DeepFindingSanitizerTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/DeepReview/DeepFindingSanitizer.php \
  tests/Feature/Services/DeepReview/DeepFindingSanitizerTest.php
git commit -m "feat(audit): drop deep findings bound to files the model never saw"
```

---

### Task 11: Persistence for selection and telemetry

**Files:**
- Create: `database/migrations/2026_08_04_000001_add_deep_review_to_audit_requests_table.php`
- Modify: `app/Models/AuditRequest.php`
- Test: `tests/Feature/Services/DeepReviewTelemetryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `audit_requests.risk_files` (JSON, cast to `array`), `deep_review_input_tokens`, `deep_review_output_tokens`, `deep_review_ms` (unsigned int, nullable, cast to `integer`). Task 12 writes all four.

Keeping deep-review tokens separate from `ai_input_tokens` is the point: summed, the marginal cost of tier 2 is unmeasurable, and F5.12.6 exists to answer exactly that.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/DeepReviewTelemetryTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class DeepReviewTelemetryTest extends FeatureTest
{
    public function test_deep_review_columns_round_trip(): void
    {
        $request = AuditRequest::factory()->create();

        $request->update([
            'risk_files' => ['selection_version' => 1, 'files' => [['path' => 'app/A.php', 'rank' => 1]]],
            'deep_review_input_tokens' => 91_000,
            'deep_review_output_tokens' => 4_200,
            'deep_review_ms' => 38_000,
        ]);

        $fresh = $request->fresh();

        $this->assertSame(1, $fresh->risk_files['selection_version']);
        $this->assertSame('app/A.php', $fresh->risk_files['files'][0]['path']);
        $this->assertSame(91_000, $fresh->deep_review_input_tokens);
        $this->assertSame(4_200, $fresh->deep_review_output_tokens);
        $this->assertSame(38_000, $fresh->deep_review_ms);
    }

    public function test_tier_one_and_tier_two_token_counts_are_independent(): void
    {
        $request = AuditRequest::factory()->create([
            'ai_input_tokens' => 12_000,
            'deep_review_input_tokens' => 91_000,
        ]);

        $this->assertSame(12_000, $request->fresh()->ai_input_tokens);
        $this->assertSame(91_000, $request->fresh()->deep_review_input_tokens);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewTelemetryTest
```

Expected: FAIL — unknown column `risk_files`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_04_000001_add_deep_review_to_audit_requests_table.php`:

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
            // The full selection log: per-file rank, raw and normalized signal
            // values, and the selection_version that produced them. Persisting
            // the contributions rather than just the paths is what makes the
            // weights tunable from real runs later.
            $table->json('risk_files')->nullable()->after('scanner_runs');
            // Deliberately separate from ai_* so the MARGINAL cost of tier 2
            // stays measurable (F5.12.6).
            $table->unsignedInteger('deep_review_input_tokens')->nullable()->after('ai_output_tokens');
            $table->unsignedInteger('deep_review_output_tokens')->nullable()->after('deep_review_input_tokens');
            $table->unsignedInteger('deep_review_ms')->nullable()->after('deep_review_output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'risk_files',
                'deep_review_input_tokens',
                'deep_review_output_tokens',
                'deep_review_ms',
            ]);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/AuditRequest.php`, extend `$fillable`:

```php
        'ai_input_tokens', 'ai_output_tokens', 'scanner_ms', 'repo_size_kb',
        'risk_files', 'deep_review_input_tokens', 'deep_review_output_tokens', 'deep_review_ms',
```

And `$casts`:

```php
        'risk_files' => 'array',
        'deep_review_input_tokens' => 'integer',
        'deep_review_output_tokens' => 'integer',
        'deep_review_ms' => 'integer',
```

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewTelemetryTest
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_04_000001_add_deep_review_to_audit_requests_table.php \
  app/Models/AuditRequest.php tests/Feature/Services/DeepReviewTelemetryTest.php
git commit -m "feat(audit): persist the risk-file selection and deep-review telemetry"
```

---

### Task 12: Pipeline integration and degradation

**Files:**
- Modify: `app/Services/AuditReport/AuditPipeline.php`
- Modify: `tests/Support/RunsAuditPipelineWithFakes.php`
- Test: `tests/Feature/Services/DeepReviewPipelineTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–11.
- Produces: `$payload['file_findings']` and `$payload['deep_review']` on deep-tier runs; the four `audit_requests` columns populated; an `OperationsAlert` on degradation.

- [ ] **Step 1: Extend the pipeline test trait**

In `tests/Support/RunsAuditPipelineWithFakes.php`, add the import and an optional reviewer parameter so deep-tier runs can be driven:

```php
use App\Services\AuditReport\DeepReview\DeepReviewer;
```

Change the `runPipelineWithFakes` signature to accept the reviewer, and bind it before the request is created:

```php
    private function runPipelineWithFakes(
        array $groups = [],
        array $failingScanners = [],
        AuditTier $tier = AuditTier::AUTOMATED,
        int $inputTokens = 10,
        int $outputTokens = 5,
        ?DeepReviewer $deepReviewer = null,
    ): AuditRequest {
```

Immediately before `$request = AuditRequest::factory()->create([`:

```php
        if ($deepReviewer !== null) {
            $this->app->instance(DeepReviewer::class, $deepReviewer);
        }
```

Also extend the fixture repository in `setUpAuditPipelineFixture()` so a deep run has something to select — add before the `git init`:

```php
            File::ensureDirectoryExists($this->fixtureRepo.'/app/Auth');
            File::put($this->fixtureRepo.'/app/Auth/Guard.php', "<?php\nclass Guard {}\n");
```

And extend the fake scc inventory inside `runPipelineWithFakes` to list it:

```php
                    $ctx->withInventory(new SccInventory(
                        files: [
                            ['path' => 'app/Auth/Guard.php', 'loc' => 2, 'complexity' => 1],
                            ['path' => 'index.php', 'loc' => 1, 'complexity' => 0],
                        ],
                        languages: ['PHP' => ['files' => 2, 'loc' => 3]],
                        totalLoc: 3,
                        totalComplexity: 1,
                    ));
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Services/DeepReviewPipelineTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Exceptions\AiAnalysisException;
use App\Notifications\OperationsAlert;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeDeepReviewer;
use Tests\Support\RunsAuditPipelineWithFakes;

class DeepReviewPipelineTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAuditPipelineFixture();
        Notification::fake();
    }

    private function finding(string $path = 'app/Auth/Guard.php'): array
    {
        return [
            'path' => $path,
            'line' => 2,
            'title' => 'Guard allows null roles',
            'severity' => 'critical',
            'category' => 'authorization',
            'evidence' => 'class Guard has no role check',
            'recommendation' => 'Deny by default.',
            'effort' => 'M',
            'related_paths' => [],
        ];
    }

    public function test_a_deep_run_produces_file_findings_and_telemetry(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [$this->finding()]),
        );

        $payload = $request->report->payload;

        $this->assertCount(1, $payload['file_findings']);
        $this->assertSame('app/Auth/Guard.php', $payload['file_findings'][0]['path']);
        $this->assertFalse($payload['deep_review']['degraded']);
        $this->assertSame(2000, $request->deep_review_input_tokens);
        $this->assertSame(400, $request->deep_review_output_tokens);
        $this->assertNotNull($request->risk_files);
        $this->assertSame(1, $request->risk_files['selection_version']);
    }

    public function test_an_automated_run_never_calls_the_deep_reviewer(): void
    {
        // A silent regression here would bill tier-2 costs against tier-1
        // revenue, so the gate itself is asserted.
        $reviewer = new FakeDeepReviewer(findings: [$this->finding()]);

        $request = $this->runPipelineWithFakes(
            tier: AuditTier::AUTOMATED,
            deepReviewer: $reviewer,
        );

        $this->assertNull($reviewer->receivedSelection);
        $this->assertArrayNotHasKey('file_findings', $request->report->payload);
        $this->assertNull($request->deep_review_input_tokens);
    }

    public function test_the_expert_tier_also_runs_deep_review(): void
    {
        $reviewer = new FakeDeepReviewer(findings: [$this->finding()]);

        $this->runPipelineWithFakes(tier: AuditTier::EXPERT, deepReviewer: $reviewer);

        $this->assertNotNull($reviewer->receivedSelection);
    }

    public function test_a_failed_deep_review_still_delivers_a_complete_report(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(throws: new AiAnalysisException('deep boom')),
        );

        $payload = $request->report->payload;

        $this->assertNotNull($request->report);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertTrue($payload['deep_review']['degraded']);
        $this->assertArrayNotHasKey('file_findings', $payload);

        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    public function test_zero_findings_is_not_degradation(): void
    {
        // P6: a healthy verdict is a designed outcome, not an empty state.
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: []),
        );

        $payload = $request->report->payload;

        $this->assertFalse($payload['deep_review']['degraded']);
        $this->assertSame([], $payload['file_findings']);
        Notification::assertNothingSent();
    }

    public function test_fabricated_findings_are_dropped(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [$this->finding('app/Never/Sent.php')]),
        );

        $payload = $request->report->payload;

        // Every finding was fabricated, so the review is treated as degraded.
        $this->assertTrue($payload['deep_review']['degraded']);
        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    public function test_the_deep_reviewer_receives_deterministic_context_only(): void
    {
        $reviewer = new FakeDeepReviewer(findings: []);

        $this->runPipelineWithFakes(tier: AuditTier::DEEP_AI, deepReviewer: $reviewer);

        $this->assertIsArray($reviewer->receivedMetrics);
        $this->assertIsArray($reviewer->receivedGroups);
        $this->assertNotNull($reviewer->receivedSelection);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewPipelineTest
```

Expected: FAIL — no `file_findings` key in the payload.

- [ ] **Step 4: Wire the stage into the pipeline**

In `app/Services/AuditReport/AuditPipeline.php`, add the imports:

```php
use App\Notifications\OperationsAlert;
use App\Services\AuditReport\DeepReview\DeepFindingSanitizer;
use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Tiers\TierProfile;
use Illuminate\Support\Facades\Notification;
```

Add four constructor dependencies:

```php
        private RiskFileSelector $riskFileSelector,
        private DeepReviewer $deepReviewer,
        private DeepFindingSanitizer $sanitizer,
```

Replace the block between the `$result = $this->analyzer->analyze(...)` assignment's closing `);` and `$report = $this->reportService->create(...)`:

```php
            $payload = $result->payload;
            $payload['scores'] = $scoreSet->toPayloadScores();
            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            // The tier-1 payload is complete and valid at this point, which is
            // what lets a deep-review failure lose a SECTION rather than a
            // report (spec D1).
            if ($profile->deepReview !== null) {
                $payload = $this->runDeepReview(
                    $auditRequest,
                    $profile,
                    $context,
                    $this->deduplicator->dedupe($suite->findings),
                    $metrics,
                    array_slice($groups, 0, $profile->narratedGroups),
                    $payload,
                );
            }
```

Then add the two methods at the bottom of the class:

```php
    /**
     * @param  list<\App\Services\AuditReport\Findings\DedupedFinding>  $dedupedFindings
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runDeepReview(
        AuditRequest $auditRequest,
        TierProfile $profile,
        RepoContext $context,
        array $dedupedFindings,
        array $metrics,
        array $groups,
        array $payload,
    ): array {
        $startedAt = microtime(true);
        $selection = null;

        try {
            $selection = $this->riskFileSelector->select($context, $dedupedFindings, $profile->deepReview);
            $auditRequest->update(['risk_files' => $selection->toLogArray()]);

            $auditRequest->appendPipelineLog('risk_files', sprintf(
                'Selected %d of %d candidates for deep review%s',
                count($selection->files),
                $selection->candidatesConsidered,
                $selection->truncated ? ' (truncated by the token budget)' : '',
            ));

            if ($selection->belowFloor) {
                // Someone paid for "your 20-40 riskiest files" and this repo
                // cannot supply them. Whether that warrants a refund is a human
                // judgement, so it surfaces rather than being absorbed.
                $this->alert($auditRequest, sprintf(
                    'Deep review ran on only %d files, below the configured floor.',
                    count($selection->files),
                ));
            }

            $review = $this->deepReviewer->review($metrics, $groups, $selection, $profile->deepReview);

            $sanitized = $this->sanitizer->sanitize(
                $review->findings,
                $selection->paths(),
                array_column($context->inventory?->files ?? [], 'path'),
            );

            if ($sanitized['dropped'] > 0 || $sanitized['strippedRelated'] > 0) {
                $auditRequest->appendPipelineLog('deep_review_sanitized', sprintf(
                    'Dropped %d finding(s) on files that were never sent; stripped %d unknown related path(s)',
                    $sanitized['dropped'],
                    $sanitized['strippedRelated'],
                ));
            }

            // A review whose every finding was fabricated is not a review.
            if ($sanitized['findings'] === [] && $sanitized['dropped'] > 0) {
                throw new AiAnalysisException('Every deep finding referenced a file that was never sent');
            }

            $auditRequest->update([
                'deep_review_input_tokens' => $review->inputTokens,
                'deep_review_output_tokens' => $review->outputTokens,
                'deep_review_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $payload['file_findings'] = $sanitized['findings'];
            $payload['deep_review'] = [
                'files_selected' => $selection->selectedBeforeBudget,
                'files_reviewed' => count($selection->files),
                'truncated' => $selection->truncated,
                'selection_version' => $selection->selectionVersion,
                'degraded' => false,
            ];

            $auditRequest->appendPipelineLog('deep_review', sprintf(
                'Deep review returned %d finding(s)',
                count($sanitized['findings']),
            ));

            return $payload;
        } catch (\Throwable $e) {
            \Sentry\captureException($e);

            $auditRequest->appendPipelineLog('deep_review_degraded', 'Deep review did not complete: '.$e->getMessage());
            $this->alert($auditRequest, 'Deep review failed: '.$e->getMessage());

            $payload['deep_review'] = [
                'files_selected' => $selection?->selectedBeforeBudget ?? 0,
                'files_reviewed' => 0,
                'truncated' => $selection?->truncated ?? false,
                'selection_version' => (int) config('audit.deep_review.selection_version'),
                'degraded' => true,
            ];

            return $payload;
        }
    }

    /**
     * A degraded PAID run is an individual actionable event — a health check
     * could report an elevated failure rate but never say which customer's run
     * to re-run.
     */
    private function alert(AuditRequest $auditRequest, string $message): void
    {
        Notification::route('mail', (string) config('audit.admin_email'))->notify(
            new OperationsAlert(
                checkName: 'deep_review',
                band: 'high',
                status: 'failed',
                message: $message.' Audit request: '.$auditRequest->uuid,
            ),
        );
    }
```

Add `use App\Exceptions\AiAnalysisException;` if it is not already imported.

Note: `AuditNotAnalyzableException` must still propagate to the outer handler, and it does — the deep stage's `catch (\Throwable)` is inside the outer `try`, but it never rethrows, so a deep failure can never reach `markNeedsFollowup()`. That is the intended asymmetry.

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewPipelineTest
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Verify the existing pipeline suite still passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditPipelineTest
```

Expected: PASS. `AuditPipeline`'s constructor gained three parameters — any test constructing it directly rather than through the container needs updating.

- [ ] **Step 7: Commit**

```bash
git add app/Services/AuditReport/AuditPipeline.php tests/Support/RunsAuditPipelineWithFakes.php \
  tests/Feature/Services/DeepReviewPipelineTest.php
git commit -m "feat(audit): run the deep review stage, degrading and alerting on failure"
```

---

### Task 13: Report rendering

**Files:**
- Create: `resources/views/reports/partials/deep-findings.blade.php`
- Modify: `resources/views/reports/audit-web.blade.php`
- Modify: `resources/views/reports/audit.blade.php`
- Test: `tests/Feature/Http/DeepReviewRenderingTest.php`

**Interfaces:**
- Consumes: `$payload['file_findings']` and `$payload['deep_review']` (Tasks 8, 12).
- Produces: the partial expects `$payload` (array) and `$unlocked` (bool) in scope.

One partial included by both templates. The templates otherwise duplicate every section, and this design does not propose fixing that generally — but a drifting PDF that omits findings the web report shows would be a customer-visible defect on a $199 product, so the newest and most complex section is shared.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/DeepReviewRenderingTest.php`:

```php
<?php

namespace Tests\Feature\Http;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class DeepReviewRenderingTest extends FeatureTest
{
    private function reportWith(array $deepKeys): AuditReport
    {
        $request = AuditRequest::factory()->create();

        return AuditReport::create(array_merge([
            'audit_request_id' => $request->id,
            'payload' => array_merge([
                'summary' => 'A summary.',
                'scores' => ['overall' => 50],
                'risks' => [],
                'fix_first_plan' => [],
                'groups' => [],
            ], $deepKeys),
            'unlocked_at' => now(),
            'scoring_version' => 1,
            'payload_schema_version' => 3,
        ]));
    }

    private function finding(array $overrides = []): array
    {
        return array_merge([
            'path' => 'app/Auth/Guard.php',
            'line' => 42,
            'title' => 'Authorization can be bypassed',
            'severity' => 'critical',
            'category' => 'authorization',
            'evidence' => 'The guard returns true when the role is null.',
            'recommendation' => 'Deny by default.',
            'effort' => 'M',
            'related_paths' => ['app/Services/Billing.php'],
        ], $overrides);
    }

    private function view(AuditReport $report): string
    {
        return $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->getContent();
    }

    public function test_file_findings_render_grouped_by_file(): void
    {
        $html = $this->view($this->reportWith([
            'file_findings' => [$this->finding()],
            'deep_review' => ['files_selected' => 40, 'files_reviewed' => 40, 'truncated' => false, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('app/Auth/Guard.php', $html);
        $this->assertStringContainsString('Authorization can be bypassed', $html);
        $this->assertStringContainsString('Deny by default.', $html);
        $this->assertStringContainsString('app/Services/Billing.php', $html);
    }

    public function test_truncation_is_disclosed(): void
    {
        $html = $this->view($this->reportWith([
            'file_findings' => [$this->finding()],
            'deep_review' => ['files_selected' => 40, 'files_reviewed' => 28, 'truncated' => true, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('28', $html);
        $this->assertStringContainsString('40', $html);
    }

    public function test_degradation_is_disclosed(): void
    {
        $html = $this->view($this->reportWith([
            'deep_review' => ['files_selected' => 0, 'files_reviewed' => 0, 'truncated' => false, 'selection_version' => 1, 'degraded' => true],
        ]));

        $this->assertStringContainsString('could not be completed', $html);
    }

    public function test_zero_findings_renders_a_confident_healthy_verdict(): void
    {
        $html = $this->view($this->reportWith([
            'file_findings' => [],
            'deep_review' => ['files_selected' => 30, 'files_reviewed' => 30, 'truncated' => false, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('No file-level issues', $html);
        $this->assertStringNotContainsString('could not be completed', $html);
    }

    public function test_a_report_with_no_deep_section_renders_unchanged(): void
    {
        $html = $this->view($this->reportWith([]));

        $this->assertStringNotContainsString('Deep file review', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewRenderingTest
```

Expected: FAIL — no deep section in the markup.

- [ ] **Step 3: Write the partial**

`resources/views/reports/partials/deep-findings.blade.php`:

Class names deliberately reuse the ones both templates already define for risks and groups — `risk`, `risk-head`, `risk-title`, `risk-detail`, `badge badge-{severity}`, `muted`. Only two new classes are introduced (`deep-file` and `deep-notice`), which keeps the CSS additions in Step 5 small.

```blade
@php
    $deep = $payload['deep_review'] ?? null;

    $severityRank = ['critical' => 5, 'high' => 4, 'medium' => 3, 'low' => 2, 'info' => 1];

    // Grouped by file; files ordered by their worst finding, findings within a
    // file by severity, then line. A customer opens one file and sees
    // everything wrong with it instead of jumping around a flat severity list.
    $byFile = collect($payload['file_findings'] ?? [])
        // Arrays compare element-wise in PHP, so negating the rank sorts
        // severity descending and line ascending in one pass.
        ->sortBy(fn (array $f) => [-($severityRank[$f['severity']] ?? 0), $f['line'] ?? 0])
        ->groupBy('path')
        ->sortByDesc(fn ($findings) => $findings->max(fn (array $f) => $severityRank[$f['severity']] ?? 0));
@endphp

@if ($deep !== null)
    <h2>{{ __('Deep file review') }}</h2>

    @if ($deep['degraded'] ?? false)
        <p class="deep-notice">
            {{ __('The deep review could not be completed for this run. The automated analysis in this report is complete, and we have been notified.') }}
        </p>
    @else
        @if ($deep['truncated'] ?? false)
            <p class="muted">
                {{ __('Reviewed :reviewed of :selected selected files, in risk order.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                    'selected' => $deep['files_selected'] ?? 0,
                ]) }}
            </p>
        @else
            <p class="muted">
                {{ __('Reviewed :reviewed files, selected as the riskiest in this repository.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @endif

        @if ($byFile->isEmpty())
            {{-- P6: a healthy verdict is a designed outcome, not an empty state. --}}
            <p>
                {{ __('No file-level issues were found across the :count files reviewed. The riskiest parts of this codebase hold up to close reading.', [
                    'count' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @else
            @foreach ($byFile as $path => $findings)
                <div class="deep-file">
                    <div class="risk-title">{{ $path }}</div>

                    @foreach ($findings as $finding)
                        <div class="risk">
                            <div class="risk-head">
                                <span class="badge badge-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span>
                                <span class="risk-title">{{ $finding['title'] }}</span>
                                @if (($finding['line'] ?? null) !== null)
                                    <span class="muted">{{ __('line :line', ['line' => $finding['line']]) }}</span>
                                @endif
                            </div>

                            @if ($unlocked)
                                <div class="risk-detail">
                                    <div>{{ $finding['evidence'] }}</div>
                                    <div><strong>{{ __('Fix') }}:</strong> {{ $finding['recommendation'] }}</div>
                                    <div class="muted">{{ __('Effort: :effort', ['effort' => $finding['effort']]) }}</div>

                                    @if (($finding['related_paths'] ?? []) !== [])
                                        <div class="muted">
                                            {{ __('Also involves') }}: {{ implode(', ', $finding['related_paths']) }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    @endif
@endif
```

- [ ] **Step 4: Include the partial in both templates**

Both templates already set `@php($payload = $report->payload)` near the top, so `$payload` is in scope in each.

In `resources/views/reports/audit-web.blade.php`, the risks section is wrapped in a `<div class="card">`. Add the include as its own card, after that closing `</div>` and before the `@if ($unlocked)` block that renders "What to fix first":

```blade
    @if (($payload['deep_review'] ?? null) !== null)
        <div class="card">
            @include('reports.partials.deep-findings', ['payload' => $payload, 'unlocked' => $unlocked])
        </div>
    @endif
```

In `resources/views/reports/audit.blade.php`, after the risks `</table>` and before `<h2>{{ __('What to fix first') }}</h2>`:

```blade
    @include('reports.partials.deep-findings', ['payload' => $payload, 'unlocked' => true])
```

`unlocked: true` is correct for the PDF: `AuditReportService::generatePdf()` is called from exactly two places — inside `create()` guarded by `if ($unlocked)`, and inside `unlock()` — so a PDF only ever exists for an unlocked report.

- [ ] **Step 5: Add the styling both templates need**

The partial reuses existing classes, but two gaps must be closed.

In `resources/views/reports/audit-web.blade.php`, inside the existing `<style>` block, next to the other `.badge-*` rules. Note that `badge-critical` and `badge-info` are **missing today** — the groups section at line ~101 already emits all five severities, so this fixes a pre-existing gap as well:

```css
        .badge-critical { background: #fecaca; color: #7f1d1d; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .deep-file { border-top: 1px solid #e7e5e4; padding-top: 14px; margin-top: 14px; }
        .deep-file > .risk-title { font-family: monospace; font-size: 13px; word-break: break-all; }
        .deep-file .risk:first-of-type { border-top: none; }
        .deep-notice { background: #fef3c7; border: 1px solid #fcd34d; color: #78350f; padding: 12px 14px; border-radius: 6px; }
```

In `resources/views/reports/audit.blade.php`, inside its `<style>` block. This template has no `.badge` or `.risk` rules at all — it styles risks with `.impact-*` on table cells — so the partial's classes need minimal definitions here:

```css
        .badge { font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 8px; background: #e7e5e4; color: #44403c; }
        .badge-critical, .badge-high { background: #fee2e2; color: #b91c1c; }
        .badge-medium { background: #fef3c7; color: #b45309; }
        .badge-low, .badge-info { background: #ecfccb; color: #4d7c0f; }
        .risk { padding: 8px 0; border-bottom: 1px solid #e7e5e4; }
        .risk-title { font-weight: bold; }
        .risk-detail { margin-top: 4px; color: #44403c; }
        .deep-file { margin-top: 12px; }
        .deep-file > .risk-title { font-family: DejaVu Sans Mono, monospace; font-size: 11px; }
        .deep-notice { background: #fef3c7; border: 1px solid #fcd34d; color: #78350f; padding: 10px; }
```

The notice is deliberately the most visually prominent element in the section — it is the disclosure that makes degradation honest rather than silent.

- [ ] **Step 6: Run test to verify it passes**

```bash
docker compose exec laravel.test php artisan test --filter=DeepReviewRenderingTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add resources/views/reports/ tests/Feature/Http/DeepReviewRenderingTest.php
git commit -m "feat(audit): render the deep file review section in both report templates"
```

---

### Task 14: Final verification and documentation

**Files:**
- Modify: `docs/2026-08-01-remaining-phases.md`

**Interfaces:**
- Consumes: all prior tasks.
- Produces: nothing consumed downstream.

- [ ] **Step 1: Format**

```bash
docker compose exec laravel.test vendor/bin/pint
docker compose exec laravel.test vendor/bin/pint --test
```

Expected: `PASS`. Never use `--dirty` — the bind-mount excludes `.git`, so it reports success without checking anything.

- [ ] **Step 2: Static analysis**

```bash
docker compose exec laravel.test vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`. The baseline suppresses pre-existing errors only; anything new in this phase must be fixed rather than baselined.

- [ ] **Step 3: Full test suite**

```bash
docker compose exec laravel.test php artisan test
```

Expected: green, exit code 0, no risky tests. Takes about 150 seconds. Do not run anything else concurrently.

- [ ] **Step 4: Run it a second time**

```bash
docker compose exec laravel.test php artisan test
```

Expected: identical result. A second run catches order-dependent state and the Faker collision class of flake that has bitten this suite before.

- [ ] **Step 5: Tick the roadmap**

In `docs/2026-08-01-remaining-phases.md`, under "## Phase 12 — Deep AI review (D2)", tick each checkbox and append a one-line evidence note to each, in the style used by the completed Phase 11 entries. The two bullets that were already satisfied before this phase — the $199 product and plan-metadata Deep AI credits — should say so explicitly rather than being silently ticked.

Also update the "Where the system actually is" table to reflect that Phase 12 has shipped.

- [ ] **Step 6: Commit**

```bash
git add docs/2026-08-01-remaining-phases.md
git commit -m "docs: record Phase 12 deep AI review as shipped"
```

- [ ] **Step 7: Confirm the branch state**

```bash
git log --oneline -15
git status --short
```

Expected: fourteen new commits on `growth-retention`, and no unexpected modified files.

---

## Verification Checklist

Milestone M8 is satisfied when all of the following hold:

- [ ] A `deep_ai` purchase produces a run whose report contains a deep section with file-bound findings
- [ ] Risk-file selection is deterministic, logged to `risk_files`, and reproducible from the stored `selection_version` and per-signal contributions
- [ ] The run's estimated input tokens stay within the configured budget, and estimate-versus-actual is recorded for calibration
- [ ] Secret-bearing files are excluded from model input on every tier, including diagnostic
- [ ] A forced deep-review failure delivers a complete tier-1 report, discloses the gap in the report, and raises an operations alert
- [ ] `php artisan test`, `vendor/bin/phpstan analyse`, and `vendor/bin/pint --test` all pass
