# Audit Precision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the audit pipeline from reporting false positives and false "clean" results: wrong repository facts, missed lockfiles, untriaged secret hits, generated code counted as source, and failed scans scored as perfect.

**Architecture:** A per-run `PathClassifier` labels every repo path (source, test, docs, example, generated, vendored, lockfile). scc builds it and puts it on `RepoContext`. Every later scanner and collector reads it from there. Silent scanner failures throw a classified `ScannerSkipped`, which turns the dimension into "not measured" with a reason. Scores bump to version 3. A new `AuditMeasurer` holds the scanners → collectors → scores sequence. The pipeline and a new `app:audit-dry-run` command both use it, so the regression fixture exercises the real code path.

**Tech Stack:** PHP 8.4, Laravel 13, PHPUnit 11, gitleaks 8.28, scc 3.5, jscpd 4.0.5 (all in the `laravel.test` container at `/opt/flexpick/bin`), symfony/yaml.

**Spec:** `backend/docs/superpowers/specs/2026-10-05-audit-precision-design.md`

## Global Constraints

- All commands run inside the container from the repo root: `docker compose exec -T laravel.test <cmd>`. Host PHP can't reach MySQL.
- Tests: `docker compose exec -T laravel.test php artisan test --compact --filter=<Name>`. The suite is PHPUnit (classic `TestCase`), not Pest. Feature tests extend `Tests\Feature\FeatureTest`.
- Before each commit: `docker compose exec -T laravel.test vendor/bin/pint <changed files>`. Before the final commit: `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` must be clean. Never use `pint --dirty` in the container.
- Concurrent sessions share the test DB. On a `QueryException` storm, re-run before debugging. `git add` explicit paths only, never `-A`.
- Repo-supplied config never steers an analyzer (spec §5.4). The only gitleaks config ever passed is `resources/scanners/gitleaks.toml`, and the only jscpd config is ours (a per-run temp copy is fine). `.gitattributes` `linguist-*` may affect classification for metrics, never secret severity.
- `Finding` must never carry a matched secret value (F5.2.6). Scanner failure reasons are fixed codes, never tool output (§5.4).
- Every gitleaks hit, at any severity, still withholds that file's content from the model (`AuditPipeline::secretPaths()` unchanged).
- `ScoreCalculator::VERSION = 3`.
- Commit trailer on every commit:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01W1YKqek357KSr8XVQ7kVHB
  ```
- Work on a new branch `audit-precision` off `growth-retention`.

**Deliberate refinements of the spec** (decided while planning; the reasons are given in the tasks):
- Secrets use **three** rule families (`secrets.credential` = critical, `secrets.possible-credential` = high, `secrets.likely-fixture` = low), not two, so every group has one uniform severity. The spec's "critical + high" split maps onto the first two.
- The lockfile deduction is **20 per ecosystem** (npm, composer) with any uncovered manifest that declares dependencies. That's simpler than "per workspace root" and meets the same intent: a monorepo missing a lockfile loses 20, not 20 × packages.

## Review Focus

1. **Repo with no `.git` directory, or a shallow clone.** `HotspotCollector` and the dry-run must still produce facts. `platform-main` has no `.git`, and the manual acceptance in Task 13 exercises this.
2. **Workspace globs that escape the clone** (`"workspaces": ["../*"]`, or a symlinked `package.json` pointing outside). They must be ignored. Pinned in Task 6 (`test_workspace_globs_cannot_escape_the_repository`).
3. **A lockfile that exists but is corrupt or truncated** (invalid JSON in `bun.lock`, broken YAML in `pnpm-lock.yaml`). The parser must yield zero packages for that file without throwing, and the dimension becomes `lockfile_unreadable`, not a crash. Pinned in Task 8.
4. **Old reports rendered after deploy** (metrics without `tooling.ci_systems`, `excluded_summary` or `not_measured_reasons`, and with the legacy top-level keys). The facts panel must still render. Pinned in Task 11 (`test_legacy_metrics_still_render_facts`).
5. **scc's billing pass fails while the inventory pass succeeds.** The generated set is then unknown. The classifier falls back to path rules only, and nothing throws. Pinned in Task 3 (`test_a_failed_billing_pass_still_builds_a_classified_inventory`).

---

### Task 1: `PathClassifier`

**Files:**
- Create: `backend/app/Services/AuditReport/Paths/PathClass.php`
- Create: `backend/app/Services/AuditReport/Paths/PathClassifier.php`
- Test: `backend/tests/Unit/Services/AuditReport/PathClassifierTest.php`

**Interfaces:**
- Produces:
  - `enum PathClass: string { Source, Test, Docs, Example, Generated, Vendored, Lockfile }` with `isAnalyzed(): bool` (true for Source, Test, Example).
  - `PathClassifier::__construct(array $sccGenerated = [], array $attributes = [])`, where `$sccGenerated` is a `list<string>` of repo-relative paths and `$attributes` is a `list<array{pattern: string, attribute: string, set: bool}>`.
  - `PathClassifier::forRepository(string $repoPath, array $sccGenerated = []): self` reads the root `.gitattributes`.
  - `PathClassifier::classify(string $path, bool $ignoreRepoAttributes = false): PathClass`.
  - `PathClassifier::buildOutputDirectory(string $path): ?string` returns the path prefix up to and including the first build-output segment (`dist`, `build`, `out`, `.next`, `coverage`, `storybook-static`), or null.
  - `PathClassifier::BUILD_OUTPUT_SEGMENTS`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\AuditReport;

use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathClassifierTest extends TestCase
{
    /** @return array<string, array{string, PathClass}> */
    public static function paths(): array
    {
        return [
            'root lockfile' => ['bun.lock', PathClass::Lockfile],
            'binary bun lock' => ['frontend/bun.lockb', PathClass::Lockfile],
            'custom generator lock' => ['postxl-lock.json', PathClass::Lockfile],
            'dotted lock yaml' => ['skaile.lock.yaml', PathClass::Lockfile],
            'composer lock' => ['composer.lock', PathClass::Lockfile],
            'go sum' => ['svc/go.sum', PathClass::Lockfile],
            'vendor php' => ['vendor/laravel/framework/src/Foo.php', PathClass::Vendored],
            'nested node_modules' => ['packages/x/node_modules/y/index.js', PathClass::Vendored],
            'third party' => ['lib/third_party/z.c', PathClass::Vendored],
            'storybook static' => ['_concept/prototype/storybook/storybook-static/sb-manager/runtime.js', PathClass::Generated],
            'minified' => ['public/js/app.min.js', PathClass::Generated],
            'source map' => ['public/js/app.js.map', PathClass::Generated],
            'generated dir' => ['src/__generated__/schema.ts', PathClass::Generated],
            'protobuf go' => ['api/v1/user.pb.go', PathClass::Generated],
            'env example' => ['.env.example', PathClass::Example],
            'nested env example' => ['backend/apps/api/.env.example', PathClass::Example],
            'dist config' => ['phpunit.xml.dist', PathClass::Example],
            'dotted example' => ['config/app.example.json', PathClass::Example],
            'tests dir' => ['tests/Feature/UserTest.php', PathClass::Test],
            'ts test suffix' => ['backend/libs/session/src/session-lifecycle.service.test.ts', PathClass::Test],
            'spec suffix' => ['src/app.spec.ts', PathClass::Test],
            'go test' => ['pkg/user_test.go', PathClass::Test],
            'python test' => ['app/test_views.py', PathClass::Test],
            'fixtures dir' => ['src/fixtures/user.json', PathClass::Test],
            'mocks dir' => ['src/__mocks__/fs.ts', PathClass::Test],
            'php Test class' => ['app/UserTest.php', PathClass::Test],
            'not a test: Latest' => ['app/Latest.php', PathClass::Source],
            'markdown' => ['README.md', PathClass::Docs],
            'docs dir' => ['docs/multi-user-onboarding/guide.ts', PathClass::Docs],
            'devlog' => ['_devlog/reports/2026-09.html', PathClass::Docs],
            'source ts' => ['backend/libs/capabilities/src/gif-search.service.ts', PathClass::Source],
            'jenkinsfile' => ['Jenkinsfile', PathClass::Source],
            'compose' => ['docker-compose.prod-local.yml', PathClass::Source],
            'plain txt is not docs' => ['CMakeLists.txt', PathClass::Source],
        ];
    }

    #[DataProvider('paths')]
    public function test_classifies_by_path_convention(string $path, PathClass $expected): void
    {
        $this->assertSame($expected, (new PathClassifier)->classify($path));
    }

    public function test_lockfile_wins_over_vendored(): void
    {
        $this->assertSame(PathClass::Lockfile, (new PathClassifier)->classify('vendor/x/composer.lock'));
    }

    public function test_scc_detected_generated_files_are_generated(): void
    {
        $classifier = new PathClassifier(['backend/libs/database/src/prisma/models/User.ts']);

        $this->assertSame(PathClass::Generated, $classifier->classify('backend/libs/database/src/prisma/models/User.ts'));
        $this->assertSame(PathClass::Source, $classifier->classify('backend/libs/database/src/index.ts'));
    }

    public function test_gitattributes_mark_generated_and_vendored(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'backend/libs/database/src/prisma/**', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => '*.pb.ts', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => 'lib/forked/**', 'attribute' => 'linguist-vendored', 'set' => true],
        ]);

        $this->assertSame(PathClass::Generated, $classifier->classify('backend/libs/database/src/prisma/client.ts'));
        $this->assertSame(PathClass::Generated, $classifier->classify('api/user.pb.ts'));
        $this->assertSame(PathClass::Vendored, $classifier->classify('lib/forked/x.js'));
    }

    public function test_a_later_unset_attribute_overrides_an_earlier_set(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'src/**', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => 'src/keep.ts', 'attribute' => 'linguist-generated', 'set' => false],
        ]);

        $this->assertSame(PathClass::Source, $classifier->classify('src/keep.ts'));
        $this->assertSame(PathClass::Generated, $classifier->classify('src/other.ts'));
    }

    public function test_repo_attributes_can_be_ignored_for_secret_severity(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'src/**', 'attribute' => 'linguist-generated', 'set' => true],
        ]);

        $this->assertSame(PathClass::Source, $classifier->classify('src/keys.ts', ignoreRepoAttributes: true));
    }

    public function test_reads_gitattributes_from_the_repository_root(): void
    {
        $repo = sys_get_temp_dir().'/classifier-'.bin2hex(random_bytes(4));
        mkdir($repo);
        file_put_contents($repo.'/.gitattributes', "# comment\n_devlog/DEVLOG.md merge=union\ngen/** linguist-generated\nlib/** linguist-vendored=true\nsrc/x.ts -linguist-generated\n");

        try {
            $classifier = PathClassifier::forRepository($repo);

            $this->assertSame(PathClass::Generated, $classifier->classify('gen/a.ts'));
            $this->assertSame(PathClass::Vendored, $classifier->classify('lib/a.js'));
            $this->assertSame(PathClass::Source, $classifier->classify('src/x.ts'));
        } finally {
            exec('rm -rf '.escapeshellarg($repo));
        }
    }

    public function test_build_output_directory_is_the_prefix_through_the_build_segment(): void
    {
        $classifier = new PathClassifier;

        $this->assertSame(
            '_concept/prototype/storybook/storybook-static',
            $classifier->buildOutputDirectory('_concept/prototype/storybook/storybook-static/sb-manager/runtime.js'),
        );
        $this->assertNull($classifier->buildOutputDirectory('src/app.ts'));
    }

    public function test_is_analyzed(): void
    {
        $this->assertTrue(PathClass::Source->isAnalyzed());
        $this->assertTrue(PathClass::Test->isAnalyzed());
        $this->assertTrue(PathClass::Example->isAnalyzed());
        $this->assertFalse(PathClass::Generated->isAnalyzed());
        $this->assertFalse(PathClass::Docs->isAnalyzed());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=PathClassifierTest`
Expected: FAIL. `Class "App\Services\AuditReport\Paths\PathClassifier" not found`.

- [ ] **Step 3: Write the implementation**

`backend/app/Services/AuditReport/Paths/PathClass.php`:

```php
<?php

namespace App\Services\AuditReport\Paths;

enum PathClass: string
{
    case Source = 'source';
    case Test = 'test';
    case Docs = 'docs';
    case Example = 'example';
    case Generated = 'generated';
    case Vendored = 'vendored';
    case Lockfile = 'lockfile';

    /** Hand-written code the report measures: structure, duplication, hotspots, review. */
    public function isAnalyzed(): bool
    {
        return in_array($this, [self::Source, self::Test, self::Example], true);
    }
}
```

`backend/app/Services/AuditReport/Paths/PathClassifier.php`:

```php
<?php

namespace App\Services\AuditReport\Paths;

/**
 * What kind of file a repository path is, so generated bundles, lockfile
 * checksums and test fixtures stop being scored as hand-written source.
 *
 * Built once per run by SccScanner (it knows which files scc detected as
 * generated or minified) and read from RepoContext by everything after.
 * First matching rule wins: lockfile, vendored, generated, example, test,
 * docs, source.
 *
 * `.gitattributes` linguist-generated / linguist-vendored is the one piece of
 * repository config honoured, and only for metrics: secret severity calls
 * classify() with $ignoreRepoAttributes so a repository cannot talk its own
 * leaked key down a severity (spec §5.4).
 */
final class PathClassifier
{
    private const LOCKFILES = [
        'composer.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml',
        'bun.lock', 'bun.lockb', 'Cargo.lock', 'Gemfile.lock', 'poetry.lock', 'go.sum',
    ];

    private const LOCKFILE_PATTERNS = ['*-lock.json', '*.lock.json', '*-lock.yaml', '*.lock.yaml', '*-lock.yml', '*.lock.yml'];

    private const VENDORED_SEGMENTS = ['vendor', 'node_modules', 'bower_components', 'third_party'];

    public const BUILD_OUTPUT_SEGMENTS = ['dist', 'build', 'out', '.next', 'coverage', 'storybook-static'];

    private const GENERATED_SEGMENTS = ['__generated__', 'generated'];

    private const GENERATED_BASENAMES = ['*.min.js', '*.min.css', '*.bundle.js', '*.map', '*.pb.go', '*_pb2.py', '*.g.dart'];

    private const EXAMPLE_BASENAMES = ['*.example', '*.sample', '*.dist', '.env.template', '*.example.*'];

    private const TEST_SEGMENTS = ['test', 'tests', 'spec', '__tests__', '__mocks__', 'fixtures', 'testdata', 'e2e'];

    /** Case-sensitive on purpose: `*Test.*` must not match `Latest.php`. */
    private const TEST_BASENAMES = ['*Test.*', '*.test.*', '*.spec.*', '*_test.*', 'test_*.py'];

    private const DOCS_EXTENSIONS = ['md', 'mdx', 'rst', 'adoc'];

    private const DOCS_TOP_SEGMENTS = ['docs', 'doc', '_devlog', '_concept', 'examples'];

    /** @var array<string, true> */
    private array $sccGenerated;

    /**
     * @param  list<string>  $sccGenerated  repo-relative paths scc flagged generated/minified
     * @param  list<array{pattern: string, attribute: string, set: bool}>  $attributes  in file order
     */
    public function __construct(array $sccGenerated = [], private array $attributes = [])
    {
        $this->sccGenerated = array_fill_keys($sccGenerated, true);
    }

    /** @param list<string> $sccGenerated */
    public static function forRepository(string $repoPath, array $sccGenerated = []): self
    {
        return new self($sccGenerated, self::readAttributes($repoPath.'/.gitattributes'));
    }

    public function classify(string $path, bool $ignoreRepoAttributes = false): PathClass
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $basename = basename($path);
        $segments = explode('/', $path);
        $directories = array_slice($segments, 0, -1);
        $attribute = $ignoreRepoAttributes ? null : $this->attributeFor($path);

        if (in_array($basename, self::LOCKFILES, true) || $this->matchesAny($basename, self::LOCKFILE_PATTERNS)) {
            return PathClass::Lockfile;
        }

        if (array_intersect($directories, self::VENDORED_SEGMENTS) !== [] || $attribute === 'linguist-vendored') {
            return PathClass::Vendored;
        }

        if (array_intersect($directories, [...self::BUILD_OUTPUT_SEGMENTS, ...self::GENERATED_SEGMENTS]) !== []
            || $this->matchesAny($basename, self::GENERATED_BASENAMES)
            || isset($this->sccGenerated[$path])
            || $attribute === 'linguist-generated') {
            return PathClass::Generated;
        }

        if ($this->matchesAny($basename, self::EXAMPLE_BASENAMES) || preg_match('/^\.env\.(example|sample)$/', $basename) === 1) {
            return PathClass::Example;
        }

        $lowerDirectories = array_map('strtolower', $directories);

        if (array_intersect($lowerDirectories, self::TEST_SEGMENTS) !== [] || $this->matchesAny($basename, self::TEST_BASENAMES, caseSensitive: true)) {
            return PathClass::Test;
        }

        if (in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), self::DOCS_EXTENSIONS, true)
            || (count($segments) > 1 && in_array($segments[0], self::DOCS_TOP_SEGMENTS, true))) {
            return PathClass::Docs;
        }

        return PathClass::Source;
    }

    public function buildOutputDirectory(string $path): ?string
    {
        $segments = explode('/', ltrim(str_replace('\\', '/', $path), '/'));

        foreach (array_slice($segments, 0, -1) as $i => $segment) {
            if (in_array($segment, self::BUILD_OUTPUT_SEGMENTS, true)) {
                return implode('/', array_slice($segments, 0, $i + 1));
            }
        }

        return null;
    }

    /** Last matching line wins, as in git. */
    private function attributeFor(string $path): ?string
    {
        $state = [];

        foreach ($this->attributes as $line) {
            if ($this->attributeMatches($line['pattern'], $path)) {
                $state[$line['attribute']] = $line['set'];
            }
        }

        foreach (['linguist-vendored', 'linguist-generated'] as $attribute) {
            if (($state[$attribute] ?? false) === true) {
                return $attribute;
            }
        }

        return null;
    }

    private function attributeMatches(string $pattern, string $path): bool
    {
        $pattern = ltrim($pattern, '/');

        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($path, substr($pattern, 0, -2));
        }

        if (str_contains($pattern, '/')) {
            return fnmatch($pattern, $path, FNM_PATHNAME);
        }

        return fnmatch($pattern, basename($path));
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $basename, array $patterns, bool $caseSensitive = false): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $basename, $caseSensitive ? 0 : FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{pattern: string, attribute: string, set: bool}> */
    private static function readAttributes(string $file): array
    {
        if (! is_file($file) || is_link($file)) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file, length: 65536)) ?: [] as $raw) {
            $parts = preg_split('/\s+/', trim($raw)) ?: [];

            if ($parts === [] || $parts[0] === '' || str_starts_with($parts[0], '#')) {
                continue;
            }

            $pattern = array_shift($parts);

            foreach ($parts as $token) {
                if (preg_match('/^(-?)(linguist-(?:generated|vendored))(?:=(true|false))?$/', $token, $m) === 1) {
                    $lines[] = [
                        'pattern' => $pattern,
                        'attribute' => $m[2],
                        'set' => $m[1] !== '-' && ($m[3] ?? 'true') !== 'false',
                    ];
                }
            }
        }

        return $lines;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=PathClassifierTest`
Expected: PASS. If a data-provider row fails, fix the rule and not the row, unless the row contradicts the spec's precedence list.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Paths tests/Unit/Services/AuditReport/PathClassifierTest.php
git add backend/app/Services/AuditReport/Paths backend/tests/Unit/Services/AuditReport/PathClassifierTest.php
git commit -m "feat(audit): classify repository paths as source, test, docs, example, generated, vendored or lockfile"
```

---

### Task 2: Classified scanner skips and "not measured" reasons

**Files:**
- Create: `backend/app/Services/AuditReport/Scanners/ScannerSkipped.php`
- Modify: `backend/app/Services/AuditReport/Scanners/ScannerRunner.php` (catch block)
- Modify: `backend/app/Services/AuditReport/Scanners/ScannerSuiteResult.php` (add `runFor()`)
- Modify: `backend/app/Services/AuditReport/ScoreSet.php` (add `notMeasuredReasons`)
- Modify: `backend/app/Services/AuditReport/ScoreCalculator.php` (`calculate()`)
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php:136-137` (persist reasons)
- Test: `backend/tests/Feature/Services/Scanners/ScannerRunnerTest.php` (create if absent; otherwise add the test), `backend/tests/Feature/Services/ScoreCalculatorTest.php`

**Interfaces:**
- Produces:
  - `final class ScannerSkipped extends \RuntimeException { public function __construct(public readonly string $reason) }`. Reason codes: `no_report`, `empty_output`, `osv_unreachable`, `no_lockfile`, `lockfile_unreadable`.
  - `ScannerSuiteResult::runFor(string $name): ?ScannerRun`.
  - `ScoreSet::$notMeasuredReasons` is an `array<string, string>` mapping dimension to reason code. Codes: a `ScannerSkipped` reason, `timeout`, `unavailable`, `nonzero_exit`, `parse_failure`, or `not_run`.
  - Pipeline metrics key `not_measured_reasons`.

- [ ] **Step 1: Write the failing tests**

In `ScannerRunnerTest` (create the class in `tests/Feature/Services/Scanners/` extending `Tests\Feature\FeatureTest` if it doesn't exist):

```php
public function test_a_skipped_scanner_records_its_classified_reason(): void
{
    $scanner = new class implements \App\Services\AuditReport\Scanners\Scanner
    {
        public function name(): string { return 'fake'; }
        public function isAvailable(): bool { return true; }
        public function version(): string { return '1'; }
        public function scan(\App\Services\AuditReport\Scanners\RepoContext $context): array
        {
            throw new \App\Services\AuditReport\Scanners\ScannerSkipped('no_report');
        }
    };
    app()->instance('audit.scanner.fake', $scanner);

    $context = new \App\Services\AuditReport\Scanners\RepoContext(
        path: sys_get_temp_dir(),
        tier: app(\App\Services\AuditReport\Tiers\TierProfileResolver::class)->for(\App\Constants\AuditTier::DIAGNOSTIC),
    );

    $run = app(\App\Services\AuditReport\Scanners\ScannerRunner::class)->run(['fake'], $context)->runFor('fake');

    $this->assertSame(\App\Services\AuditReport\Scanners\ScannerOutcome::FAILED, $run->outcome);
    $this->assertSame('no_report', $run->reason);
}
```

Check the `Scanner` interface for its exact method list (`app/Services/AuditReport/Scanners/Scanner.php`) and match the anonymous class to it.

In `ScoreCalculatorTest`:

```php
public function test_not_measured_dimensions_carry_the_failing_scanners_reason(): void
{
    $runs = new ScannerSuiteResult([], [
        new ScannerRun('scc', '1.0', 10, 0, ScannerOutcome::OK),
        new ScannerRun('gitleaks', '1.0', 10, 0, ScannerOutcome::OK),
        new ScannerRun('semgrep', '1.0', 10, 0, ScannerOutcome::OK),
        new ScannerRun('jscpd', '1.0', 10, 0, ScannerOutcome::FAILED, 'no_report'),
        new ScannerRun('osv', '1.0', 10, 0, ScannerOutcome::TIMEOUT, 'timeout'),
    ]);

    $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $runs);

    $this->assertSame(['dependencies' => 'timeout', 'duplication' => 'no_report'], $set->notMeasuredReasons);
}

public function test_a_scanner_absent_from_the_run_is_reported_as_not_run(): void
{
    $runs = new ScannerSuiteResult([], [new ScannerRun('scc', '1.0', 10, 0, ScannerOutcome::OK)]);

    $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $runs);

    $this->assertSame('not_run', $set->notMeasuredReasons['duplication']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='ScannerRunnerTest|ScoreCalculatorTest'`
Expected: FAIL. Class `ScannerSkipped` not found, and undefined property `notMeasuredReasons`.

- [ ] **Step 3: Implement**

`ScannerSkipped.php`:

```php
<?php

namespace App\Services\AuditReport\Scanners;

use RuntimeException;

/**
 * A scanner ran but measured nothing it could stand behind — no report file,
 * an unreachable advisory database, no readable lockfile. Thrown instead of
 * returning [] so the dimension is reported "not measured" rather than scored
 * as a clean repository.
 *
 * `reason` is a fixed code and reaches the pipeline log and the report; it
 * must never carry tool output (spec §5.4).
 */
final class ScannerSkipped extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
```

`ScannerRunner::run()`: add this catch **before** `catch (Throwable $e)`:

```php
} catch (ScannerSkipped $e) {
    $runs[] = new ScannerRun(
        name: $name,
        version: $scanner->version(),
        wallMs: $this->elapsedMs($startedAt),
        findingCount: 0,
        outcome: ScannerOutcome::FAILED,
        reason: $e->reason,
    );
}
```

`ScannerSuiteResult`:

```php
public function runFor(string $name): ?ScannerRun
{
    foreach ($this->runs as $run) {
        if ($run->name === $name) {
            return $run;
        }
    }

    return null;
}
```

`ScoreSet`: add a fourth constructor parameter with a docblock line `@param array<string, string> $notMeasuredReasons dimension → reason code`:

```php
public array $notMeasuredReasons = [],
```

`ScoreCalculator::calculate()`: build reasons alongside `$notMeasured`:

```php
$reasons = [];
// inside the loop, in the not-measured branch:
$notMeasured[] = $dimension;
$reasons[$dimension] = $this->reasonFor($required, $runs);
// after the loop:
ksort($reasons);

return new ScoreSet($scores, $notMeasured, self::VERSION, $reasons);
```

```php
/** @param list<string> $required */
private function reasonFor(array $required, ScannerSuiteResult $runs): string
{
    foreach ($required as $scanner) {
        $run = $runs->runFor($scanner);

        if ($run === null) {
            return 'not_run';
        }

        if (! $runs->ranSuccessfully($scanner)) {
            return $run->reason ?? $run->outcome->value;
        }
    }

    return 'not_run';
}
```

`AuditPipeline` after `$metrics['not_measured'] = $scoreSet->notMeasured;`:

```php
$metrics['not_measured_reasons'] = $scoreSet->notMeasuredReasons;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='ScannerRunnerTest|ScoreCalculatorTest|AuditPipelineTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Scanners app/Services/AuditReport/ScoreSet.php app/Services/AuditReport/ScoreCalculator.php app/Services/AuditReport/AuditPipeline.php tests/Feature/Services/Scanners/ScannerRunnerTest.php tests/Feature/Services/ScoreCalculatorTest.php
git add backend/app/Services/AuditReport/Scanners/ScannerSkipped.php backend/app/Services/AuditReport/Scanners/ScannerRunner.php backend/app/Services/AuditReport/Scanners/ScannerSuiteResult.php backend/app/Services/AuditReport/ScoreSet.php backend/app/Services/AuditReport/ScoreCalculator.php backend/app/Services/AuditReport/AuditPipeline.php backend/tests/Feature/Services/Scanners/ScannerRunnerTest.php backend/tests/Feature/Services/ScoreCalculatorTest.php
git commit -m "feat(audit): scanners can skip with a classified reason, recorded per not-measured dimension"
```

---

### Task 3: Classified scc inventory: generated, vendored, docs and non-code files leave the metrics

**Files:**
- Modify: `backend/app/Services/AuditReport/Scanners/SccScanner.php`
- Modify: `backend/app/Services/AuditReport/Scanners/SccInventory.php`
- Modify: `backend/app/Services/AuditReport/Scanners/RepoContext.php`
- Modify: `backend/app/Services/AuditReport/MetricsCollector.php` (`excluded_summary`)
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php:98-104` (fallback classifier), `:366-370` (sanitizer paths)
- Modify: `backend/app/Services/AuditReport/Collectors/ToolingCollector.php` (test counting only)
- Test: `backend/tests/Feature/Services/Scanners/SccScannerTest.php`, `backend/tests/Feature/Services/Collectors/CollectorsTest.php`

**Interfaces:**
- Consumes: `PathClassifier`, `PathClass` (Task 1); `ScannerSkipped` (Task 2).
- Produces:
  - `RepoContext::$classifier` (`PathClassifier`, defaults to `new PathClassifier`) and `RepoContext::withClassifier(PathClassifier $classifier): void`.
  - `SccInventory::$files`, now a `list<array{path, loc, complexity, class: string}>` holding only code-language files whose class `isAnalyzed()`.
  - `SccInventory::$excluded`, a `list<array{path: string, loc: int, reason: string}>`. `reason` is a `PathClass` value or `non_code`.
  - `SccInventory::allPaths(): list<string>`, which covers files and excluded together.
  - `SccScanner::NON_CODE_LANGUAGES`.
  - `SccScanner::toInventory(array $raw, string $repoPath, ?int $billableCode = null, ?PathClassifier $classifier = null)`.
  - `SccScanner::fallbackInventory(string $repoPath, ?PathClassifier $classifier = null)`.
  - Metrics key `excluded_summary`, an `array<string, int>` mapping reason to file count.

- [ ] **Step 1: Write the failing tests**

Add to `SccScannerTest`:

```php
private function file(string $location, int $lines = 10): array
{
    return ['Location' => self::ROOT.'/'.$location, 'Lines' => $lines, 'Code' => $lines, 'Complexity' => 1];
}

public function test_generated_vendored_docs_and_non_code_files_are_excluded_from_the_inventory(): void
{
    $raw = [
        ['Name' => 'TypeScript', 'Files' => [
            $this->file('src/app.ts', 100),
            $this->file('src/app.test.ts', 50),
            $this->file('backend/libs/database/src/prisma/models/User.ts', 23972),
            $this->file('_concept/prototype/storybook/storybook-static/sb-manager/runtime.js', 26078),
        ]],
        ['Name' => 'Markdown', 'Files' => [$this->file('CHANGELOG.md', 13331)]],
        ['Name' => 'JSON', 'Files' => [$this->file('postxl-lock.json', 9000), $this->file('src/data.json', 400)]],
    ];
    $classifier = new \App\Services\AuditReport\Paths\PathClassifier(['backend/libs/database/src/prisma/models/User.ts']);

    $inventory = app(SccScanner::class)->toInventory($raw, self::ROOT, null, $classifier);

    $this->assertSame(['src/app.ts', 'src/app.test.ts'], array_column($inventory->files, 'path'));
    $this->assertSame(150, $inventory->totalLoc);
    $this->assertSame(['TypeScript'], array_keys($inventory->languages));
    $this->assertEqualsCanonicalizing(
        ['generated' => 2, 'docs' => 1, 'lockfile' => 1, 'non_code' => 1],
        array_count_values(array_column($inventory->excluded, 'reason')),
    );
    $this->assertContains('CHANGELOG.md', $inventory->allPaths());
}

public function test_scan_builds_the_classifier_from_files_the_filtered_pass_dropped(): void
{
    $full = [['Name' => 'TypeScript', 'Code' => 30, 'Files' => [
        $this->file('src/real.ts', 10),
        $this->file('src/gen.ts', 20),
    ]]];
    $filtered = [['Name' => 'TypeScript', 'Code' => 10, 'Files' => [$this->file('src/real.ts', 10)]]];

    $context = $this->scanWithFakedScc(fn (array $command) => Process::result(
        json_encode(in_array('--no-gen', $command, true) ? $filtered : $full),
    ));

    $this->assertSame(\App\Services\AuditReport\Paths\PathClass::Generated, $context->classifier->classify('src/gen.ts'));
    $this->assertSame(['src/real.ts'], array_column($context->inventory->files, 'path'));
    $this->assertSame(10, $context->inventory->billableCode);
}

public function test_a_failed_billing_pass_still_builds_a_classified_inventory(): void
{
    $full = [['Name' => 'TypeScript', 'Code' => 10, 'Files' => [$this->file('src/real.ts', 10), $this->file('dist/app.js', 5)]]];

    $context = $this->scanWithFakedScc(fn (array $command) => in_array('--no-gen', $command, true)
        ? Process::result('', 'boom', 1)
        : Process::result(json_encode($full)));

    $this->assertNull($context->inventory->billableCode);
    $this->assertSame(['src/real.ts'], array_column($context->inventory->files, 'path'));
}

public function test_empty_scc_output_is_a_skip_not_an_empty_repository(): void
{
    $this->expectException(\App\Services\AuditReport\Scanners\ScannerSkipped::class);

    $this->scanWithFakedScc(fn () => Process::result('null'));
}
```

Add to `CollectorsTest` a test that `ToolingCollector` counts test files from the inventory's `class` field:

```php
public function test_tooling_counts_test_files_by_classification(): void
{
    $context = new RepoContext(
        path: $this->repo,
        tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
        inventory: new SccInventory(
            files: [
                ['path' => 'src/a.ts', 'loc' => 10, 'complexity' => 1, 'class' => 'source'],
                ['path' => 'src/a.test.ts', 'loc' => 10, 'complexity' => 1, 'class' => 'test'],
            ],
            languages: [],
            totalLoc: 20,
            totalComplexity: 2,
        ),
    );

    $tooling = app(ToolingCollector::class)->collect($context);

    $this->assertSame(1, $tooling['test_files']);
    $this->assertSame(50.0, $tooling['test_ratio_pct']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='SccScannerTest|CollectorsTest'`
Expected: FAIL. `toInventory()` has no fourth parameter, and the `classifier` property is undefined.

- [ ] **Step 3: Implement**

`RepoContext`: add `use App\Services\AuditReport\Paths\PathClassifier;`, a constructor parameter `public PathClassifier $classifier = new PathClassifier,` after `$inventory`, and:

```php
public function withClassifier(PathClassifier $classifier): void
{
    $this->classifier = $classifier;
}
```

`SccInventory`: update the `$files` docblock to `list<array{path: string, loc: int, complexity: int, class: string}>` and add a last parameter:

```php
/** @param list<array{path: string, loc: int, reason: string}> $excluded */
public array $excluded = [],
```

and:

```php
/** Every path scc saw, kept or excluded — for validating paths the model cites. @return list<string> */
public function allPaths(): array
{
    return [...array_column($this->files, 'path'), ...array_column($this->excluded, 'path')];
}
```

`SccScanner`:

```php
public const NON_CODE_LANGUAGES = [
    'Markdown', 'JSON', 'JSONL', 'YAML', 'Plain Text', 'License', 'ReStructuredText', 'AsciiDoc', 'CSV', 'SVG',
];

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

    $decoded = json_decode(Utf8::scrub($result->output()), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new ScannerSkipped('empty_output');
    }

    [$billableCode, $keptPaths] = $this->filteredPass($context->path);

    $generated = $keptPaths === null ? [] : array_values(array_diff($this->paths($decoded, $context->path), $keptPaths));
    $context->withClassifier(PathClassifier::forRepository($context->path, $generated));

    $context->withInventory($this->toInventory($decoded, $context->path, $billableCode, $context->classifier));

    return $this->normalize($decoded);
}

/**
 * The --no-gen --no-min-gen pass: what the audit is billed on, and — diffed
 * against the full pass — which files scc detected as generated or minified.
 * A separate run, because those flags would silently drop files the report
 * should list as excluded. [null, null] when it fails: billing then charges
 * nothing extra and classification falls back to path rules.
 *
 * @return array{0: ?int, 1: ?list<string>}
 */
private function filteredPass(string $path): array
{
    try {
        $result = Process::timeout((int) config('audit.scanners.scc.timeout'))
            ->run([
                (string) config('audit.scanners.scc.bin'),
                '--format', 'json',
                '--by-file',
                '--no-gen',
                '--no-min-gen',
                '--exclude-dir', implode(',', self::EXCLUDED_DIRS),
                $path,
            ]);

        if (! $result->successful()) {
            return [null, null];
        }

        $decoded = json_decode(Utf8::scrub($result->output()), true, flags: JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return [null, null];
    }

    return is_array($decoded) ? [$this->sumCode($decoded), $this->paths($decoded, $path)] : [null, null];
}

/** @param array<int, array<string, mixed>> $raw @return list<string> */
private function paths(array $raw, string $repoPath): array
{
    $paths = [];

    foreach ($raw as $language) {
        foreach ($language['Files'] ?? [] as $file) {
            $path = RepoRelativePath::from($repoPath, (string) ($file['Location'] ?? ''));

            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }

    return $paths;
}
```

Delete the old `billableCode()` method. `sumCode()` is unchanged: `--by-file` keeps the per-language `Code` totals.

Replace `toInventory()`:

```php
public function toInventory(array $raw, string $repoPath, ?int $billableCode = null, ?PathClassifier $classifier = null): SccInventory
{
    $classifier ??= new PathClassifier;
    $files = [];
    $excluded = [];
    $languages = [];
    $totalComplexity = 0;

    foreach ($raw as $language) {
        $name = (string) ($language['Name'] ?? 'Unknown');

        foreach ($language['Files'] ?? [] as $file) {
            $path = RepoRelativePath::from($repoPath, (string) ($file['Location'] ?? ''));

            if ($path === '') {
                continue;
            }

            $loc = (int) ($file['Lines'] ?? 0);
            $class = $classifier->classify($path);

            if (! $class->isAnalyzed()) {
                $excluded[] = ['path' => $path, 'loc' => $loc, 'reason' => $class->value];

                continue;
            }

            if (in_array($name, self::NON_CODE_LANGUAGES, true)) {
                $excluded[] = ['path' => $path, 'loc' => $loc, 'reason' => 'non_code'];

                continue;
            }

            $complexity = (int) ($file['Complexity'] ?? 0);
            $files[] = ['path' => $path, 'loc' => $loc, 'complexity' => $complexity, 'class' => $class->value];
            $languages[$name]['files'] = ($languages[$name]['files'] ?? 0) + 1;
            $languages[$name]['loc'] = ($languages[$name]['loc'] ?? 0) + $loc;
            $totalComplexity += $complexity;
        }
    }

    // Total order — descending loc, then path — so repeat runs select the
    // same excerpts and cite the same files (spec §6.3).
    usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
    usort($excluded, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
    ksort($languages);

    return new SccInventory(
        files: $files,
        languages: $languages,
        totalLoc: array_sum(array_column($files, 'loc')),
        totalComplexity: $totalComplexity,
        billableCode: $billableCode,
        excluded: $excluded,
    );
}
```

`fallbackInventory(string $repoPath, ?PathClassifier $classifier = null)` applies the same split. Inside the Finder loop:

```php
$class = ($classifier ?? new PathClassifier)->classify($file->getRelativePathname());
if (! $class->isAnalyzed() || in_array($extension, ['md', 'json', 'yaml', 'yml', 'txt', 'csv', 'svg'], true)) {
    $excluded[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'reason' => $class->isAnalyzed() ? 'non_code' : $class->value];
    continue;
}
$files[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'complexity' => 0, 'class' => $class->value];
```

Then pass `excluded: $excluded` to the `SccInventory` constructor.

`AuditPipeline` fallback branch, before `fallbackInventory`:

```php
$context->withClassifier(PathClassifier::forRepository($path));
$inventory = $this->sccScanner->fallbackInventory($path, $context->classifier);
```

`AuditPipeline` sanitizer call: replace `array_column($context->inventory?->files ?? [], 'path')` with `$context->inventory?->allPaths() ?? []`. That way a deep finding that cites `docs/session-lifecycle.md` keeps its related path.

`MetricsCollector`, after `$metrics['largest_files'] = …`:

```php
$metrics['excluded_summary'] = array_count_values(array_column($inventory?->excluded ?? [], 'reason'));
ksort($metrics['excluded_summary']);
```

`ToolingCollector`: replace the two inline regexes with:

```php
$testFiles = count(array_filter($files, fn (array $f): bool => ($f['class'] ?? null) === PathClass::Test->value));
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='SccScannerTest|CollectorsTest|ExcerptCollectorTest|RiskFileSelector|AuditPipelineTest'`
Expected: PASS. If an existing `SccScannerTest` fixture assertion changes because `tests/Feature/Services/Fixtures/Scanners/scc.json` contains a now-excluded file (Markdown, JSON), update that expectation and note it in the commit message.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport tests/Feature/Services/Scanners/SccScannerTest.php tests/Feature/Services/Collectors/CollectorsTest.php
git add backend/app/Services/AuditReport/Scanners/SccScanner.php backend/app/Services/AuditReport/Scanners/SccInventory.php backend/app/Services/AuditReport/Scanners/RepoContext.php backend/app/Services/AuditReport/MetricsCollector.php backend/app/Services/AuditReport/AuditPipeline.php backend/app/Services/AuditReport/Collectors/ToolingCollector.php backend/tests/Feature/Services/Scanners/SccScannerTest.php backend/tests/Feature/Services/Collectors/CollectorsTest.php
git commit -m "fix(audit): generated, vendored, docs and non-code files no longer count as source in size, structure and hotspots"
```

---

### Task 4: One finding per committed build-output directory

**Files:**
- Modify: `backend/app/Services/AuditReport/Scanners/SccScanner.php` (`scan()` return value, new `buildOutputFindings()`)
- Test: `backend/tests/Feature/Services/Scanners/SccScannerTest.php`

**Interfaces:**
- Consumes: `PathClassifier::buildOutputDirectory()` (Task 1); `SccInventory::$excluded` (Task 3).
- Produces: findings with `ruleId 'structure.committed-build-output'`, `ruleFamily 'structure.committed-build-output'`, `Severity::LOW`, `dimension 'structure'`, `tool 'scc'`, `line null`, and `path` set to the first excluded file (sorted) under that directory.

- [ ] **Step 1: Write the failing test**

```php
public function test_reports_each_committed_build_output_directory_once(): void
{
    $full = [['Name' => 'JavaScript', 'Code' => 30, 'Files' => [
        $this->file('_concept/prototype/storybook/storybook-static/sb-manager/runtime.js', 26078),
        $this->file('_concept/prototype/storybook/storybook-static/sb-manager/globals-runtime.js', 76311),
        $this->file('src/app.js', 10),
    ]]];

    Process::fake(fn () => Process::result(json_encode($full)));
    $context = new RepoContext(path: self::ROOT, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));

    $findings = app(SccScanner::class)->scan($context);

    $this->assertCount(1, $findings);
    $this->assertSame('structure.committed-build-output', $findings[0]->ruleFamily);
    $this->assertSame(\App\Services\AuditReport\Findings\Severity::LOW, $findings[0]->severity);
    $this->assertSame('structure', $findings[0]->dimension);
    $this->assertSame('_concept/prototype/storybook/storybook-static/sb-manager/globals-runtime.js', $findings[0]->path);
    $this->assertStringContainsString('2 files', $findings[0]->message);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=test_reports_each_committed_build_output_directory_once`
Expected: FAIL (0 findings).

- [ ] **Step 3: Implement**

In `scan()`, replace `return $this->normalize($decoded);` with `return $this->buildOutputFindings($context);`. Keep `normalize()` as is: it's still what scc's raw output normalizes to, and existing tests call it. Update the class docblock line "It produces no findings" to "Its only findings are committed build-output directories".

```php
/**
 * Build output (a Storybook export, a dist/ bundle) committed to version
 * control is worth one line in the report — not one finding per minified
 * file, and never a structure penalty per file.
 *
 * @return list<Finding>
 */
private function buildOutputFindings(RepoContext $context): array
{
    $byDirectory = [];

    foreach ($context->inventory?->excluded ?? [] as $file) {
        $directory = $context->classifier->buildOutputDirectory($file['path']);

        if ($directory !== null) {
            $byDirectory[$directory][] = $file['path'];
        }
    }

    ksort($byDirectory);
    $findings = [];

    foreach ($byDirectory as $directory => $paths) {
        sort($paths);
        $count = count($paths);

        $findings[] = new Finding(
            tool: $this->name(),
            ruleId: 'structure.committed-build-output',
            ruleFamily: 'structure.committed-build-output',
            severity: Severity::LOW,
            path: $paths[0],
            line: null,
            message: "Build output is committed to version control: {$directory}/ ({$count} files). Generate it in the build instead.",
            dimension: 'structure',
        );
    }

    return $findings;
}
```

Add `use App\Services\AuditReport\Findings\Finding;` and `use App\Services\AuditReport\Findings\Severity;`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='SccScannerTest|AuditPipelineTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Scanners/SccScanner.php tests/Feature/Services/Scanners/SccScannerTest.php
git add backend/app/Services/AuditReport/Scanners/SccScanner.php backend/tests/Feature/Services/Scanners/SccScannerTest.php
git commit -m "feat(audit): report committed build output once per directory"
```

---

### Task 5: jscpd ignores generated code and fails loudly

**Files:**
- Modify: `backend/app/Services/AuditReport/Scanners/JscpdScanner.php`
- Modify: `backend/resources/scanners/jscpd.json`
- Test: `backend/tests/Feature/Services/Scanners/JscpdScannerTest.php`

**Interfaces:**
- Consumes: `RepoContext::$classifier`, `SccInventory::$excluded`, `PathClassifier` (Tasks 1 and 3); `ScannerSkipped` (Task 2).
- Produces:
  - `JscpdScanner::normalize(array $raw, string $repoPath, ?PathClassifier $classifier = null): array`.
  - `JscpdScanner::ignoreGlobs(SccInventory $inventory, PathClassifier $classifier): list<string>` (public for testing).

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_missing_report_is_a_skip_not_zero_duplication(): void
{
    config(['audit.scanners.jscpd.bin' => '/bin/true']);
    $context = new RepoContext(path: sys_get_temp_dir(), tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));

    $this->expectException(\App\Services\AuditReport\Scanners\ScannerSkipped::class);

    app(JscpdScanner::class)->scan($context);
}

public function test_occurrences_in_generated_files_are_dropped(): void
{
    $raw = ['duplicates' => [[
        'lines' => 40,
        'firstFile' => ['name' => 'src/a.ts', 'start' => 1],
        'secondFile' => ['name' => 'src/__generated__/b.ts', 'start' => 1],
    ]]];

    $findings = app(JscpdScanner::class)->normalize($raw, self::ROOT, new \App\Services\AuditReport\Paths\PathClassifier);

    $this->assertSame(['src/a.ts'], array_map(fn ($f) => $f->path, $findings));
}

public function test_ignore_globs_collapse_excluded_directories(): void
{
    $inventory = new \App\Services\AuditReport\Scanners\SccInventory(
        files: [],
        languages: [],
        totalLoc: 0,
        totalComplexity: 0,
        excluded: [
            ['path' => 'proto/storybook-static/a.js', 'loc' => 1, 'reason' => 'generated'],
            ['path' => 'proto/storybook-static/b.js', 'loc' => 1, 'reason' => 'generated'],
            ['path' => 'libs/db/prisma/User.ts', 'loc' => 1, 'reason' => 'generated'],
            ['path' => 'README.md', 'loc' => 1, 'reason' => 'docs'],
        ],
    );

    $globs = app(JscpdScanner::class)->ignoreGlobs($inventory, new \App\Services\AuditReport\Paths\PathClassifier);

    $this->assertSame(['libs/db/prisma/User.ts', 'proto/storybook-static/**'], $globs);
}
```

Use the imports already in the file. Add `RepoContext`, `TierProfileResolver` and `AuditTier` imports if they're missing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=JscpdScannerTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`resources/scanners/jscpd.json` `ignore` array:

```json
"ignore": [
  "**/vendor/**", "**/node_modules/**", "**/dist/**", "**/build/**", "**/out/**", "**/.next/**",
  "**/.git/**", "**/storage/**", "**/coverage/**", "**/storybook-static/**", "**/__generated__/**",
  "**/*.min.js", "**/*.min.css", "**/*.bundle.js", "**/*.map"
]
```

`JscpdScanner::scan()`: write a per-run config that merges our base config with the run's ignore globs, and pass it as `--config`. Replace `'--config', (string) config('audit.scanners.jscpd.config')` with `'--config', $runConfig`. Create it at the top of the `try`:

```php
$runConfig = $outputDir.'/jscpd.json';
$base = json_decode((string) file_get_contents((string) config('audit.scanners.jscpd.config')), true, flags: JSON_THROW_ON_ERROR);
$base['ignore'] = array_values(array_unique([
    ...($base['ignore'] ?? []),
    ...($context->inventory !== null ? $this->ignoreGlobs($context->inventory, $context->classifier) : []),
]));
file_put_contents($runConfig, json_encode($base, JSON_THROW_ON_ERROR));
```

Replace `if (! file_exists($report)) { return []; }` with:

```php
// No report is a crashed or killed jscpd, not a duplication-free repository.
if (! file_exists($report)) {
    throw new ScannerSkipped('no_report');
}
```

Pass the classifier through: `return $this->normalize($decoded, $context->path, $context->classifier);`.

`normalize()`: add the parameter `?PathClassifier $classifier = null`. After the `$path === ''` check:

```php
if ($classifier !== null && ! $classifier->classify($path)->isAnalyzed()) {
    continue;
}
```

```php
private const MAX_IGNORE_GLOBS = 500;

/**
 * jscpd globs for everything scc's inventory excluded as generated,
 * vendored or a lockfile. Build-output directories collapse to `dir/**` so a
 * 94-file Storybook export is one glob, not 94.
 *
 * @return list<string>
 */
public function ignoreGlobs(SccInventory $inventory, PathClassifier $classifier): array
{
    $globs = [];

    foreach ($inventory->excluded as $file) {
        if (! in_array($file['reason'], ['generated', 'vendored', 'lockfile'], true)) {
            continue;
        }

        $directory = $classifier->buildOutputDirectory($file['path']);
        $globs[$directory !== null ? $directory.'/**' : $file['path']] = true;
    }

    $globs = array_keys($globs);
    sort($globs);

    return array_slice($globs, 0, self::MAX_IGNORE_GLOBS);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=JscpdScannerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Scanners/JscpdScanner.php tests/Feature/Services/Scanners/JscpdScannerTest.php
git add backend/app/Services/AuditReport/Scanners/JscpdScanner.php backend/resources/scanners/jscpd.json backend/tests/Feature/Services/Scanners/JscpdScannerTest.php
git commit -m "fix(audit): jscpd skips generated code, and a missing report marks duplication not measured instead of perfect"
```

---

### Task 6: `WorkspaceDiscovery`

**Files:**
- Create: `backend/app/Services/AuditReport/Collectors/WorkspaceDiscovery.php`
- Modify: `backend/composer.json` (declare `symfony/yaml`, already installed transitively)
- Test: `backend/tests/Feature/Services/Collectors/WorkspaceDiscoveryTest.php`

**Interfaces:**
- Consumes: `PathClassifier` (Task 1).
- Produces:
  - `WorkspaceDiscovery::roots(string $repoPath, PathClassifier $classifier): list<string>` returns repo-relative directories holding a `package.json` or `composer.json`. `''` is the root and comes first. The list is sorted and capped at 50.
  - `WorkspaceDiscovery::lockfileFor(string $repoPath, string $dir, string $ecosystem): ?string` returns the repo-relative lockfile path. `$ecosystem` is `'npm'` or `'composer'`.
  - `WorkspaceDiscovery::NPM_LOCKFILES`, `COMPOSER_LOCKFILES`.

- [ ] **Step 1: Declare the dependency**

Run: `docker compose exec -T laravel.test composer require symfony/yaml --no-interaction`
Expected: `composer.json` gains `symfony/yaml`. `composer.lock` changes only in `content-hash`, because the package is already installed.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\Collectors;

use App\Services\AuditReport\Collectors\WorkspaceDiscovery;
use App\Services\AuditReport\Paths\PathClassifier;
use Tests\Feature\FeatureTest;

class WorkspaceDiscoveryTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/workspaces-'.bin2hex(random_bytes(6));
        mkdir($this->repo);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
        parent::tearDown();
    }

    private function put(string $path, string $content = '{}'): void
    {
        @mkdir(dirname($this->repo.'/'.$path), 0755, true);
        file_put_contents($this->repo.'/'.$path, $content);
    }

    private function roots(): array
    {
        return app(WorkspaceDiscovery::class)->roots($this->repo, new PathClassifier);
    }

    public function test_bun_workspaces_with_a_root_lockfile(): void
    {
        $this->put('package.json', json_encode(['workspaces' => ['packages/*']]));
        $this->put('bun.lock', '{}');
        $this->put('packages/api/package.json');
        $this->put('packages/web/package.json');

        $this->assertSame(['', 'packages/api', 'packages/web'], $this->roots());
        $this->assertSame('bun.lock', app(WorkspaceDiscovery::class)->lockfileFor($this->repo, 'packages/api', 'npm'));
    }

    public function test_pnpm_workspace_yaml_deeper_than_the_scan_depth(): void
    {
        $this->put('package.json');
        $this->put('pnpm-workspace.yaml', "packages:\n  - 'apps/group/sub/*'\n  - '!apps/ignored'\n");
        $this->put('apps/group/sub/deep/package.json');

        $this->assertContains('apps/group/sub/deep', $this->roots());
    }

    public function test_object_form_workspaces(): void
    {
        $this->put('package.json', json_encode(['workspaces' => ['packages' => ['libs/*']]]));
        $this->put('libs/a/package.json');

        $this->assertContains('libs/a', $this->roots());
    }

    public function test_nested_manifests_without_workspace_declarations_are_found(): void
    {
        $this->put('backend/composer.json');
        $this->put('frontend/package.json');
        $this->put('frontend/node_modules/x/package.json');
        $this->put('vendor/y/composer.json');

        $this->assertSame(['backend', 'frontend'], $this->roots());
    }

    public function test_nearest_lockfile_wins_and_composer_is_separate(): void
    {
        $this->put('package.json');
        $this->put('yarn.lock', '');
        $this->put('frontend/package.json');
        $this->put('frontend/pnpm-lock.yaml', '');
        $this->put('backend/composer.json');

        $discovery = app(WorkspaceDiscovery::class);

        $this->assertSame('frontend/pnpm-lock.yaml', $discovery->lockfileFor($this->repo, 'frontend', 'npm'));
        $this->assertSame('yarn.lock', $discovery->lockfileFor($this->repo, '', 'npm'));
        $this->assertNull($discovery->lockfileFor($this->repo, 'backend', 'composer'));
    }

    public function test_workspace_globs_cannot_escape_the_repository(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(4));
        mkdir($outside.'/pkg', 0755, true);
        file_put_contents($outside.'/pkg/package.json', '{}');

        try {
            $this->put('package.json', json_encode(['workspaces' => ['../*', '../'.basename($outside).'/*']]));
            symlink($outside.'/pkg', $this->repo.'/linked');

            $this->assertSame([''], $this->roots());
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    }

    public function test_roots_are_capped(): void
    {
        $this->put('package.json');
        foreach (range(1, 60) as $i) {
            $this->put("p/{$i}/package.json");
        }

        $this->assertCount(50, $this->roots());
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=WorkspaceDiscoveryTest`
Expected: FAIL. Class not found.

- [ ] **Step 4: Implement**

```php
<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Where a repository's package roots are. A monorepo keeps its real
 * dependencies (and its lockfile) below the root, so reading only the root
 * manifest reported "no lockfile" and "no error monitoring" for repositories
 * that had both.
 *
 * Declared workspaces (npm/yarn/bun `workspaces`, pnpm-workspace.yaml,
 * composer path repositories) are unioned with a shallow scan, because a
 * repository can hold independent projects nobody declared. Nothing resolved
 * here may leave the clone: globs with `..` are dropped and every root is
 * realpath-checked, since workspace globs are repository-supplied input.
 */
class WorkspaceDiscovery
{
    public const NPM_LOCKFILES = ['bun.lock', 'bun.lockb', 'pnpm-lock.yaml', 'yarn.lock', 'npm-shrinkwrap.json', 'package-lock.json'];

    public const COMPOSER_LOCKFILES = ['composer.lock'];

    private const MANIFESTS = ['package.json', 'composer.json'];

    private const MAX_ROOTS = 50;

    private const SCAN_DEPTH = 3;

    /** @return list<string> */
    public function roots(string $repoPath, PathClassifier $classifier): array
    {
        $realRepo = realpath($repoPath);

        if ($realRepo === false) {
            return [];
        }

        $roots = [];
        $add = function (string $dir) use (&$roots, $realRepo, $classifier): void {
            $dir = trim($dir, '/');
            $absolute = $dir === '' ? $realRepo : $realRepo.'/'.$dir;
            $real = realpath($absolute);

            if ($real === false || ($real !== $realRepo && ! str_starts_with($real, $realRepo.'/')) || $real !== $absolute) {
                return;
            }

            if ($dir !== '' && in_array($classifier->classify($dir.'/package.json'), [PathClass::Vendored, PathClass::Generated], true)) {
                return;
            }

            foreach (self::MANIFESTS as $manifest) {
                if (is_file($absolute.'/'.$manifest) && ! is_link($absolute.'/'.$manifest)) {
                    $roots[$dir] = true;

                    return;
                }
            }
        };

        $add('');

        foreach ($this->declaredGlobs($realRepo) as $glob) {
            foreach ($this->expand($realRepo, $glob) as $dir) {
                $add($dir);
            }
        }

        $finder = (new Finder)->files()->in($realRepo)->name(self::MANIFESTS)
            ->depth('<= '.self::SCAN_DEPTH)->exclude(['node_modules', 'vendor', '.git'])->ignoreDotFiles(false);

        foreach ($finder as $file) {
            $dir = dirname(str_replace('\\', '/', $file->getRelativePathname()));
            $add($dir === '.' ? '' : $dir);
        }

        $roots = array_keys($roots);
        sort($roots, SORT_STRING);

        return array_slice($roots, 0, self::MAX_ROOTS);
    }

    public function lockfileFor(string $repoPath, string $dir, string $ecosystem): ?string
    {
        $names = $ecosystem === 'composer' ? self::COMPOSER_LOCKFILES : self::NPM_LOCKFILES;
        $dir = trim($dir, '/');

        while (true) {
            foreach ($names as $name) {
                $relative = ltrim($dir.'/'.$name, '/');

                if (is_file($repoPath.'/'.$relative) && ! is_link($repoPath.'/'.$relative)) {
                    return $relative;
                }
            }

            if ($dir === '') {
                return null;
            }

            $parent = dirname($dir);
            $dir = $parent === '.' ? '' : $parent;
        }
    }

    /** @return list<string> */
    private function declaredGlobs(string $repoPath): array
    {
        $globs = [];
        $package = $this->json($repoPath.'/package.json');
        $workspaces = $package['workspaces'] ?? [];
        $workspaces = is_array($workspaces) && array_is_list($workspaces) ? $workspaces : ($workspaces['packages'] ?? []);

        foreach ((array) $workspaces as $glob) {
            $globs[] = (string) $glob;
        }

        if (is_file($repoPath.'/pnpm-workspace.yaml')) {
            try {
                $pnpm = Yaml::parse(Utf8::scrub((string) file_get_contents($repoPath.'/pnpm-workspace.yaml')));

                foreach ((array) ($pnpm['packages'] ?? []) as $glob) {
                    $globs[] = (string) $glob;
                }
            } catch (Throwable) {
                // An unparsable workspace file falls back to the shallow scan.
            }
        }

        foreach ((array) ($this->json($repoPath.'/composer.json')['repositories'] ?? []) as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && isset($repository['url'])) {
                $globs[] = (string) $repository['url'];
            }
        }

        return array_values(array_filter(
            $globs,
            fn (string $glob): bool => $glob !== '' && ! str_starts_with($glob, '!') && ! str_contains($glob, '..') && ! str_starts_with($glob, '/'),
        ));
    }

    /** @return list<string> */
    private function expand(string $repoPath, string $glob): array
    {
        $glob = rtrim($glob, '/');

        if (str_ends_with($glob, '/**')) {
            $base = substr($glob, 0, -3);

            if (! is_dir($repoPath.'/'.$base)) {
                return [];
            }

            $dirs = [$base];

            foreach ((new Finder)->directories()->in($repoPath.'/'.$base)->depth('< 3')->exclude(['node_modules', 'vendor']) as $dir) {
                $dirs[] = $base.'/'.str_replace('\\', '/', $dir->getRelativePathname());
            }

            return $dirs;
        }

        return array_map(
            fn (string $absolute): string => substr($absolute, strlen($repoPath) + 1),
            glob($repoPath.'/'.$glob, GLOB_ONLYDIR) ?: [],
        );
    }

    /** @return array<string, mixed> */
    private function json(string $file): array
    {
        if (! is_file($file) || is_link($file)) {
            return [];
        }

        $data = json_decode(Utf8::scrub((string) file_get_contents($file)), true);

        return is_array($data) ? $data : [];
    }
}
```

The `$real !== $absolute` check rejects any root reached through a symlink. That's why `linked` in the escape test is dropped.

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=WorkspaceDiscoveryTest`
Expected: PASS. On WSL `sys_get_temp_dir()` is `/tmp` with no symlinks; if the container's temp dir is itself a symlink, `realpath($repoPath)` already normalises it.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Collectors/WorkspaceDiscovery.php tests/Feature/Services/Collectors/WorkspaceDiscoveryTest.php
git add backend/composer.json backend/composer.lock backend/app/Services/AuditReport/Collectors/WorkspaceDiscovery.php backend/tests/Feature/Services/Collectors/WorkspaceDiscoveryTest.php
git commit -m "feat(audit): discover monorepo package roots and their nearest lockfile"
```

---

### Task 7: Manifests, lockfiles, CI and tooling across the monorepo

**Files:**
- Modify: `backend/app/Services/AuditReport/Collectors/ManifestCollector.php`
- Modify: `backend/app/Services/AuditReport/Collectors/ToolingCollector.php`
- Test: `backend/tests/Feature/Services/Collectors/CollectorsTest.php`

**Interfaces:**
- Consumes: `WorkspaceDiscovery` (Task 6); `RepoContext::$classifier` (Task 3).
- Produces:
  - `metrics.manifests` is keyed by the repo-relative manifest path (the root stays `package.json` / `composer.json`). Each entry is `{dependencies, dev_dependencies, lockfile: bool, lockfile_kind: ?string, ecosystem: 'npm'|'composer', parse_error}`.
  - `metrics.tooling` gains `ci_systems: list<string>` (sorted; values `github_actions`, `gitlab_ci`, `bitbucket_pipelines`, `jenkins`, `circleci`, `azure_pipelines`, `buildkite`, `drone`, `travis`).

- [ ] **Step 1: Write the failing tests**

Add to `CollectorsTest` (the class `tearDown` already removes `$this->repo`; if it doesn't, add one):

```php
private function monorepo(): RepoContext
{
    $root = $this->repo;
    file_put_contents($root.'/package.json', json_encode(['workspaces' => ['packages/*'], 'devDependencies' => ['typescript' => '^5']]));
    file_put_contents($root.'/bun.lock', '{}');
    @mkdir($root.'/packages/api', 0755, true);
    file_put_contents($root.'/packages/api/package.json', json_encode(['dependencies' => ['@sentry/bun' => '^8', '@biomejs/biome' => '^1']]));
    file_put_contents($root.'/Jenkinsfile.e2e', 'pipeline {}');
    @mkdir($root.'/.github/workflows', 0755, true);
    file_put_contents($root.'/.github/workflows/ci.yml', 'on: push');
    file_put_contents($root.'/Dockerfile.agent-thin', 'FROM alpine');

    return new RepoContext(path: $root, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));
}

public function test_workspace_manifests_are_covered_by_the_root_bun_lock(): void
{
    $manifests = app(ManifestCollector::class)->collect($this->monorepo());

    $this->assertTrue($manifests['package.json']['lockfile']);
    $this->assertSame('bun.lock', $manifests['package.json']['lockfile_kind']);
    $this->assertTrue($manifests['packages/api/package.json']['lockfile']);
    $this->assertSame('npm', $manifests['packages/api/package.json']['ecosystem']);
}

public function test_tooling_reads_every_workspace_and_every_ci_system(): void
{
    $tooling = app(ToolingCollector::class)->collect($this->monorepo());

    $this->assertTrue($tooling['error_monitoring']);
    $this->assertTrue($tooling['linter']);
    $this->assertTrue($tooling['dockerized']);
    $this->assertTrue($tooling['has_ci']);
    $this->assertSame(['github_actions', 'jenkins'], $tooling['ci_systems']);
}
```

The existing `CollectorsTest::setUp` writes `composer.json` and `composer.lock` at the root. Adjust the existing manifest assertion to expect the new keys (`lockfile_kind`, `ecosystem`) alongside the old ones.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CollectorsTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ManifestCollector`:

```php
public function __construct(private WorkspaceDiscovery $workspaces) {}

public function collect(RepoContext $context): array
{
    $repoPath = $context->path;
    $manifests = [];

    foreach ($this->workspaces->roots($repoPath, $context->classifier) as $dir) {
        foreach (['composer.json' => 'composer', 'package.json' => 'npm'] as $manifest => $ecosystem) {
            $relative = ltrim($dir.'/'.$manifest, '/');

            if (! is_file($repoPath.'/'.$relative)) {
                continue;
            }

            $raw = Utf8::scrub((string) file_get_contents($repoPath.'/'.$relative));
            $data = json_decode($raw, true);
            $parseError = ! is_array($data);

            if ($parseError) {
                Log::warning("ManifestCollector: failed to parse {$relative}", ['json_error' => json_last_error_msg()]);
                $data = [];
            }

            $lock = $this->workspaces->lockfileFor($repoPath, $dir, $ecosystem);

            $manifests[$relative] = [
                'dependencies' => count($data['require'] ?? $data['dependencies'] ?? []),
                'dev_dependencies' => count($data['require-dev'] ?? $data['devDependencies'] ?? []),
                'lockfile' => $lock !== null,
                'lockfile_kind' => $lock !== null ? basename($lock) : null,
                'ecosystem' => $ecosystem,
                'parse_error' => $parseError,
            ];
        }
    }

    return $manifests;
}
```

`ToolingCollector`: inject `WorkspaceDiscovery` and merge dependency names from every root:

```php
public function __construct(private WorkspaceDiscovery $workspaces) {}

private const CI_MARKERS = [
    'github_actions' => '.github/workflows',
    'gitlab_ci' => '.gitlab-ci.yml',
    'bitbucket_pipelines' => 'bitbucket-pipelines.yml',
    'circleci' => '.circleci/config.yml',
    'azure_pipelines' => 'azure-pipelines.yml',
    'buildkite' => '.buildkite',
    'drone' => '.drone.yml',
    'travis' => '.travis.yml',
];

private const ERROR_MONITORING = [
    'sentry/sentry', 'sentry/sentry-laravel', '@sentry/browser', '@sentry/node', '@sentry/react', '@sentry/nextjs',
    '@sentry/vue', '@sentry/bun', '@sentry/nestjs', '@sentry/sveltekit', '@sentry/astro', 'bugsnag/bugsnag',
    'bugsnag/bugsnag-laravel', '@bugsnag/js', 'rollbar/rollbar', 'rollbar', 'honeybadger-io/honeybadger-php',
    '@honeybadger-io/js', 'dd-trace', 'newrelic', '@opentelemetry/sdk-node', 'posthog-node',
];

/** @return list<string> */
private function ciSystems(string $repoPath): array
{
    $systems = [];

    foreach (self::CI_MARKERS as $system => $marker) {
        if (file_exists($repoPath.'/'.$marker)) {
            $systems[] = $system;
        }
    }

    if ((glob($repoPath.'/Jenkinsfile*') ?: []) !== []) {
        $systems[] = 'jenkins';
    }

    sort($systems);

    return $systems;
}
```

In `collect()`, loop `foreach ($this->workspaces->roots($repoPath, $context->classifier) as $dir)` over both manifest names with `ltrim($dir.'/'.$manifest, '/')`, merging the same four key arrays as today. Then:

```php
$ciSystems = $this->ciSystems($repoPath);
// ...
'error_monitoring' => $has(self::ERROR_MONITORING),
'env_example' => file_exists($repoPath.'/.env.example') || file_exists($repoPath.'/.env.sample') || file_exists($repoPath.'/.env.template'),
'dockerized' => (glob($repoPath.'/{Dockerfile,Dockerfile.*,docker-compose.yml,docker-compose.*.yml,compose.yaml,compose.*.yaml}', GLOB_BRACE) ?: []) !== [],
'has_ci' => $ciSystems !== [],
'ci_systems' => $ciSystems,
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='CollectorsTest|AuditPipelineTest|ScoreCalculatorTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Collectors tests/Feature/Services/Collectors/CollectorsTest.php
git add backend/app/Services/AuditReport/Collectors/ManifestCollector.php backend/app/Services/AuditReport/Collectors/ToolingCollector.php backend/tests/Feature/Services/Collectors/CollectorsTest.php
git commit -m "fix(audit): lockfiles, CI and tooling are detected across monorepo workspaces and every major CI system"
```

---

### Task 8: OSV reads yarn, pnpm and bun lockfiles, and an unchecked dependency set is not measured

**Files:**
- Modify: `backend/app/Services/AuditReport/DependencyAuditor.php`
- Modify: `backend/app/Services/AuditReport/Scanners/OsvScanner.php`
- Create fixtures: `backend/tests/Feature/Services/Fixtures/Lockfiles/{yarn-v1.lock,yarn-berry.lock,pnpm-v6.yaml,pnpm-v9.yaml,bun.lock}`
- Test: `backend/tests/Feature/Services/DependencyAuditorTest.php`, `backend/tests/Feature/Services/Scanners/OsvScannerTest.php`

**Interfaces:**
- Consumes: `WorkspaceDiscovery` (Task 6); `PathClassifier`; `ScannerSkipped` (Task 2).
- Produces:
  - `DependencyAuditor::audit(string $repoPath, ?PathClassifier $classifier = null): array`. Keys: `packages_scanned`, `vulnerable_count`, `vulnerabilities` (each entry gains `lockfile`), `lockfiles_scanned: list<string>`, `unscannable_lockfiles: list<string>`, `has_declared_dependencies: bool`, plus `error` when OSV is unreachable.
  - `DependencyAuditor::packagesFromLockfile(string $absolutePath): list<array{name, version, ecosystem}>`, public for testing.
  - OsvScanner records the measurement `vulnerable_count`.

- [ ] **Step 1: Create the lockfile fixtures**

`yarn-v1.lock`:
```
# THIS IS AN AUTOGENERATED FILE. DO NOT EDIT THIS FILE DIRECTLY.
# yarn lockfile v1


"@babel/core@^7.0.0", "@babel/core@^7.1.0":
  version "7.24.0"
  resolved "https://registry.yarnpkg.com/@babel/core/-/core-7.24.0.tgz"

lodash@^4.17.0:
  version "4.17.21"
```

`yarn-berry.lock`:
```
__metadata:
  version: 8
  cacheKey: 10

"@babel/core@npm:^7.0.0":
  version: 7.24.0
  resolution: "@babel/core@npm:7.24.0"

"my-app@workspace:.":
  version: 0.0.0-use.local
  resolution: "my-app@workspace:."

"lodash@npm:^4.17.0":
  version: 4.17.21
  resolution: "lodash@npm:4.17.21"
```

`pnpm-v6.yaml`:
```yaml
lockfileVersion: '6.0'
packages:
  /@babel/core@7.24.0:
    resolution: {integrity: sha512-x}
  /react-dom@18.2.0(react@18.2.0):
    resolution: {integrity: sha512-y}
  /local@link:../local:
    resolution: {directory: ../local}
```

`pnpm-v9.yaml`:
```yaml
lockfileVersion: '9.0'
packages:
  '@babel/core@7.24.0':
    resolution: {integrity: sha512-x}
  lodash@4.17.21:
    resolution: {integrity: sha512-z}
snapshots:
  lodash@4.17.21: {}
```

`bun.lock`:
```
{
  "lockfileVersion": 1,
  "workspaces": {
    "": { "name": "root", "devDependencies": { "typescript": "^5" }, },
  },
  "packages": {
    "typescript": ["typescript@5.4.5", "", {}, "sha512-x"],
    "@types/node": ["@types/node@20.11.0", "", {}, "sha512-y"],
    "api": ["api@workspace:packages/api"],
  },
}
```

- [ ] **Step 2: Write the failing tests**

In `DependencyAuditorTest`:

```php
/** @return array<string, array{string, list<string>}> */
public static function lockfiles(): array
{
    return [
        'yarn v1' => ['yarn-v1.lock', ['@babel/core@7.24.0', 'lodash@4.17.21']],
        'yarn berry' => ['yarn-berry.lock', ['@babel/core@7.24.0', 'lodash@4.17.21']],
        'pnpm v6' => ['pnpm-v6.yaml', ['@babel/core@7.24.0', 'react-dom@18.2.0']],
        'pnpm v9' => ['pnpm-v9.yaml', ['@babel/core@7.24.0', 'lodash@4.17.21']],
        'bun text' => ['bun.lock', ['@types/node@20.11.0', 'typescript@5.4.5']],
    ];
}

#[\PHPUnit\Framework\Attributes\DataProvider('lockfiles')]
public function test_parses_lockfile(string $fixture, array $expected): void
{
    $dir = sys_get_temp_dir().'/lock-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $name = match (true) {
        str_starts_with($fixture, 'yarn') => 'yarn.lock',
        str_starts_with($fixture, 'pnpm') => 'pnpm-lock.yaml',
        default => 'bun.lock',
    };
    copy(base_path('tests/Feature/Services/Fixtures/Lockfiles/'.$fixture), $dir.'/'.$name);

    try {
        $packages = app(\App\Services\AuditReport\DependencyAuditor::class)->packagesFromLockfile($dir.'/'.$name);
        $ids = array_map(fn (array $p): string => $p['name'].'@'.$p['version'], $packages);
        sort($ids);

        $this->assertSame($expected, $ids);
        $this->assertSame(['npm'], array_values(array_unique(array_column($packages, 'ecosystem'))));
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
}

public function test_a_corrupt_lockfile_yields_no_packages_without_throwing(): void
{
    $dir = sys_get_temp_dir().'/lock-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/bun.lock', '{"packages": {"x": [');
    file_put_contents($dir.'/pnpm-lock.yaml', "packages:\n  - : : [");

    try {
        $auditor = app(\App\Services\AuditReport\DependencyAuditor::class);

        $this->assertSame([], $auditor->packagesFromLockfile($dir.'/bun.lock'));
        $this->assertSame([], $auditor->packagesFromLockfile($dir.'/pnpm-lock.yaml'));
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
}

public function test_workspace_lockfile_is_scanned_once_and_binary_bun_is_unscannable(): void
{
    $dir = sys_get_temp_dir().'/lock-'.bin2hex(random_bytes(4));
    mkdir($dir.'/packages/api', 0755, true);
    file_put_contents($dir.'/package.json', json_encode(['workspaces' => ['packages/*'], 'dependencies' => ['typescript' => '^5']]));
    file_put_contents($dir.'/packages/api/package.json', json_encode(['dependencies' => ['typescript' => '^5']]));
    copy(base_path('tests/Feature/Services/Fixtures/Lockfiles/bun.lock'), $dir.'/bun.lock');
    mkdir($dir.'/legacy');
    file_put_contents($dir.'/legacy/package.json', json_encode(['dependencies' => ['x' => '1']]));
    file_put_contents($dir.'/legacy/bun.lockb', "\x00binary");
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['results' => [['vulns' => []], ['vulns' => []]]])]);

    try {
        $audit = app(\App\Services\AuditReport\DependencyAuditor::class)->audit($dir);

        $this->assertSame(2, $audit['packages_scanned']);
        $this->assertSame(['bun.lock'], $audit['lockfiles_scanned']);
        $this->assertSame(['legacy/bun.lockb'], $audit['unscannable_lockfiles']);
        $this->assertTrue($audit['has_declared_dependencies']);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
}
```

In `OsvScannerTest`, test the `scan()` skips by binding a stub auditor:

```php
private function scanWith(array $audit): array
{
    $auditor = \Mockery::mock(\App\Services\AuditReport\DependencyAuditor::class);
    $auditor->shouldReceive('audit')->andReturn($audit);
    $context = new \App\Services\AuditReport\Scanners\RepoContext(
        path: sys_get_temp_dir(),
        tier: app(\App\Services\AuditReport\Tiers\TierProfileResolver::class)->for(\App\Constants\AuditTier::DIAGNOSTIC),
    );

    return (new OsvScanner($auditor))->scan($context);
}

public function test_an_unreachable_osv_is_a_skip(): void
{
    $this->expectExceptionObject(new \App\Services\AuditReport\Scanners\ScannerSkipped('osv_unreachable'));
    $this->scanWith(['packages_scanned' => 10, 'error' => 'osv_unreachable']);
}

public function test_declared_dependencies_with_no_lockfile_is_a_skip(): void
{
    $this->expectExceptionObject(new \App\Services\AuditReport\Scanners\ScannerSkipped('no_lockfile'));
    $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => true, 'unscannable_lockfiles' => []]);
}

public function test_only_an_unreadable_lockfile_is_a_skip_naming_it(): void
{
    $this->expectExceptionObject(new \App\Services\AuditReport\Scanners\ScannerSkipped('lockfile_unreadable'));
    $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => true, 'unscannable_lockfiles' => ['bun.lockb']]);
}

public function test_a_repository_without_dependencies_is_measured_clean(): void
{
    $this->assertSame([], $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => false]));
}

public function test_finding_path_is_the_lockfile_that_pinned_the_package(): void
{
    $findings = app(OsvScanner::class)->normalize(['vulnerabilities' => [[
        'package' => 'lodash', 'version' => '4.17.0', 'ecosystem' => 'npm', 'vulns' => ['GHSA-1'], 'lockfile' => 'frontend/bun.lock',
    ]]]);

    $this->assertSame('frontend/bun.lock', $findings[0]->path);
}
```

`expectExceptionObject` compares the message, which `ScannerSkipped` sets to the reason.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='DependencyAuditorTest|OsvScannerTest'`
Expected: FAIL.

- [ ] **Step 4: Implement**

`DependencyAuditor`:

```php
public function __construct(private WorkspaceDiscovery $workspaces) {}

public function audit(string $repoPath, ?PathClassifier $classifier = null): array
{
    $classifier ??= PathClassifier::forRepository($repoPath);
    $lockfiles = [];
    $declared = false;

    foreach ($this->workspaces->roots($repoPath, $classifier) as $dir) {
        foreach (['composer.json' => 'composer', 'package.json' => 'npm'] as $manifest => $ecosystem) {
            $file = $repoPath.'/'.ltrim($dir.'/'.$manifest, '/');

            if (! is_file($file)) {
                continue;
            }

            $data = json_decode(Utf8::scrub((string) file_get_contents($file)), true);
            $declared = $declared || (is_array($data) && (($data['require'] ?? $data['dependencies'] ?? []) !== [] || ($data['require-dev'] ?? $data['devDependencies'] ?? []) !== []));

            $lock = $this->workspaces->lockfileFor($repoPath, $dir, $ecosystem);
            if ($lock !== null) {
                $lockfiles[$lock] = true;
            }
        }
    }

    $packages = [];
    $scanned = [];
    $unscannable = [];

    foreach (array_keys($lockfiles) as $lock) {
        if (str_ends_with($lock, '.lockb')) {
            $unscannable[] = $lock;

            continue;
        }

        $found = $this->packagesFromLockfile($repoPath.'/'.$lock);
        $found === [] ? $unscannable[] = $lock : $scanned[] = $lock;

        foreach ($found as $package) {
            $packages[$package['ecosystem'].'|'.$package['name'].'|'.$package['version']] ??= $package + ['lockfile' => $lock];
        }
    }

    sort($scanned);
    sort($unscannable);
    $packages = array_values($packages);
    $base = [
        'packages_scanned' => count($packages),
        'lockfiles_scanned' => $scanned,
        'unscannable_lockfiles' => $unscannable,
        'has_declared_dependencies' => $declared,
    ];

    if ($packages === []) {
        return $base + ['vulnerable_count' => 0, 'vulnerabilities' => []];
    }

    // existing try/catch OSV querybatch loop, unchanged except that each
    // $vulnerable[] entry adds 'lockfile' => $chunk[$i]['lockfile'], and both
    // return arrays are merged over $base (… + $base).
}

/** @return list<array{name: string, version: string, ecosystem: string}> */
public function packagesFromLockfile(string $path): array
{
    if (! is_file($path) || is_link($path)) {
        return [];
    }

    $content = Utf8::scrub((string) file_get_contents($path));

    try {
        return match (basename($path)) {
            'composer.lock' => $this->composerPackages($content),
            'package-lock.json', 'npm-shrinkwrap.json' => $this->npmPackages($content),
            'yarn.lock' => $this->yarnPackages($content),
            'pnpm-lock.yaml' => $this->pnpmPackages($content),
            'bun.lock' => $this->bunPackages($content),
            default => [],
        };
    } catch (Throwable) {
        return [];
    }
}
```

Refactor `composerPackages()` and `npmPackages()` to take `$content` (the decoded-from-string body) instead of `$repoPath`; their parsing logic stays the same. New parsers:

```php
private function yarnPackages(string $content): array
{
    $packages = [];
    $current = null;

    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if ($line[0] !== ' ') {
            $spec = trim(explode(',', rtrim($line, ':'))[0], " \"");
            $current = $this->yarnName($spec);

            continue;
        }

        if ($current !== null && preg_match('/^\s+version:?\s+"?([^"\s]+)"?\s*$/', $line, $m) === 1) {
            if (preg_match('/^\d/', $m[1]) === 1) {
                $packages[] = ['name' => $current, 'version' => $m[1], 'ecosystem' => 'npm'];
            }
            $current = null;
        }
    }

    return $packages;
}

private function yarnName(string $spec): ?string
{
    if ($spec === '__metadata' || preg_match('/@(workspace|patch|link|portal|file):/', $spec) === 1) {
        return null;
    }

    $at = strpos($spec, '@', 1);

    return $at === false ? null : substr($spec, 0, $at);
}

private function pnpmPackages(string $content): array
{
    $packages = [];

    foreach (array_keys((array) (Yaml::parse($content)['packages'] ?? [])) as $key) {
        $key = (string) preg_replace('/\(.*$/', '', ltrim((string) $key, '/'));
        $at = strrpos($key, '@');
        [$name, $version] = $at > 0
            ? [substr($key, 0, $at), substr($key, $at + 1)]
            : [substr($key, 0, (int) strrpos($key, '/')), substr($key, (int) strrpos($key, '/') + 1)];

        if ($name !== '' && preg_match('/^\d/', $version) === 1) {
            $packages[] = ['name' => $name, 'version' => $version, 'ecosystem' => 'npm'];
        }
    }

    return $packages;
}

/** bun.lock is JSON with trailing commas. */
private function bunPackages(string $content): array
{
    $data = json_decode((string) preg_replace('/,(\s*[}\]])/', '$1', $content), true, flags: JSON_THROW_ON_ERROR);
    $packages = [];

    foreach ((array) ($data['packages'] ?? []) as $entry) {
        $id = is_array($entry) ? ($entry[0] ?? null) : null;
        $at = is_string($id) ? strrpos($id, '@') : false;

        if ($at === false || $at === 0) {
            continue;
        }

        $version = substr($id, $at + 1);

        if (preg_match('/^\d/', $version) === 1) {
            $packages[] = ['name' => substr($id, 0, $at), 'version' => $version, 'ecosystem' => 'npm'];
        }
    }

    return $packages;
}
```

`OsvScanner::scan()`:

```php
public function scan(RepoContext $context): array
{
    $audit = $this->auditor->audit($context->path, $context->classifier);

    $context->record('packages_scanned', (int) ($audit['packages_scanned'] ?? 0));
    $context->record('vulnerable_count', (int) ($audit['vulnerable_count'] ?? 0));

    // Zero findings from a check that did not happen is not a clean bill.
    if (isset($audit['error'])) {
        throw new ScannerSkipped('osv_unreachable');
    }

    if (($audit['packages_scanned'] ?? 0) === 0 && ($audit['has_declared_dependencies'] ?? false)) {
        throw new ScannerSkipped(($audit['unscannable_lockfiles'] ?? []) !== [] ? 'lockfile_unreadable' : 'no_lockfile');
    }

    return $this->normalize($audit);
}
```

In `normalize()`, set `path: (string) ($vulnerable['lockfile'] ?? $this->manifestFor(...))`. Update the class docblock: the auditor no longer "degrades to zero" silently, because unreachable is now a skip.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='DependencyAuditorTest|OsvScannerTest|AuditPipelineTest'`
Expected: PASS. Adjust existing `DependencyAuditorTest` cases that called the old private helpers via `audit()`. Their expected arrays gain the new keys.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/DependencyAuditor.php app/Services/AuditReport/Scanners/OsvScanner.php tests/Feature/Services/DependencyAuditorTest.php tests/Feature/Services/Scanners/OsvScannerTest.php
git add backend/app/Services/AuditReport/DependencyAuditor.php backend/app/Services/AuditReport/Scanners/OsvScanner.php backend/tests/Feature/Services/Fixtures/Lockfiles backend/tests/Feature/Services/DependencyAuditorTest.php backend/tests/Feature/Services/Scanners/OsvScannerTest.php
git commit -m "fix(audit): OSV checks yarn, pnpm and bun lockfiles across workspaces, and an unchecked dependency set is not measured"
```

---

### Task 9: Gitleaks allowlists and severity by rule and path

**Files:**
- Modify: `backend/resources/scanners/gitleaks.toml`
- Modify: `backend/app/Services/AuditReport/Scanners/GitleaksScanner.php`
- Modify: `backend/app/Console/Commands/` the smoke command class for `app:smoke` (find it with `grep -rln "app:smoke" backend/app/Console`)
- Test: `backend/tests/Feature/Services/Scanners/GitleaksScannerTest.php`

**Interfaces:**
- Consumes: `PathClassifier::classify(…, ignoreRepoAttributes: true)` (Task 1); `RepoContext::$classifier` (Task 3).
- Produces:
  - `GitleaksScanner::normalize(array $sarif, string $repoPath, ?PathClassifier $classifier = null): list<Finding>`.
  - `GitleaksScanner::severityFor(string $ruleId, PathClass $class): Severity`.
  - Families `secrets.credential` (CRITICAL), `secrets.possible-credential` (HIGH) and `secrets.likely-fixture` (LOW), all with dimension `security_hygiene`.

- [ ] **Step 1: Write the failing tests**

Replace `test_every_gitleaks_finding_is_critical` with:

```php
public function test_a_provider_key_in_source_is_critical_and_a_generic_match_is_high(): void
{
    [$aws, $generic] = $this->normalize();

    $this->assertSame(Severity::CRITICAL, $aws->severity);
    $this->assertSame('secrets.credential', $aws->ruleFamily);
    $this->assertSame(Severity::HIGH, $generic->severity);
    $this->assertSame('secrets.possible-credential', $generic->ruleFamily);
}

/** @return array<string, array{string, PathClass, Severity}> */
public static function matrix(): array
{
    return [
        'provider in source' => ['github-pat', PathClass::Source, Severity::CRITICAL],
        'generic in source' => ['generic-api-key', PathClass::Source, Severity::HIGH],
        'provider in generated' => ['stripe-access-token', PathClass::Generated, Severity::CRITICAL],
        'generic in vendored' => ['generic-api-key', PathClass::Vendored, Severity::LOW],
        'provider in test' => ['aws-access-token', PathClass::Test, Severity::HIGH],
        'generic in test' => ['generic-api-key', PathClass::Test, Severity::LOW],
        'jwt in docs' => ['jwt', PathClass::Docs, Severity::LOW],
        'generic in example' => ['generic-api-key', PathClass::Example, Severity::LOW],
        'client id is not provider-secret' => ['discord-client-id', PathClass::Source, Severity::HIGH],
        'private key in source' => ['private-key', PathClass::Source, Severity::CRITICAL],
    ];
}

#[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
public function test_severity_matrix(string $rule, PathClass $class, Severity $expected): void
{
    $this->assertSame($expected, app(GitleaksScanner::class)->severityFor($rule, $class));
}

public function test_repo_attributes_cannot_lower_a_secrets_severity(): void
{
    $classifier = new PathClassifier([], [['pattern' => 'config/**', 'attribute' => 'linguist-generated', 'set' => true]]);

    $aws = app(GitleaksScanner::class)->normalize($this->sarif(), self::ROOT, $classifier)[0];

    $this->assertSame(Severity::CRITICAL, $aws->severity);
}

public function test_the_real_binary_honours_our_allowlists(): void
{
    if (! app(GitleaksScanner::class)->isAvailable()) {
        $this->markTestSkipped('gitleaks is not installed here.');
    }

    $root = sys_get_temp_dir().'/gitleaks-allow-'.bin2hex(random_bytes(4));
    mkdir($root.'/src', 0755, true);
    mkdir($root.'/proto/storybook-static', 0755, true);
    // Built at run time so no credential-shaped literal is ever committed.
    $value = substr(base64_encode(hash('sha256', 'flexpick-fixture', true)), 0, 32);
    $line = "api_key = \"{$value}\"\n";
    file_put_contents($root.'/src/real.ts', $line);
    file_put_contents($root.'/postxl-lock.json', $line);
    file_put_contents($root.'/proto/storybook-static/runtime.js', $line);
    file_put_contents($root.'/src/app.min.js', $line);
    file_put_contents($root.'/src/sample.ts', 'const token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c";'."\n");

    try {
        $context = new RepoContext(path: $root, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));
        $paths = array_map(fn ($f) => $f->path, app(GitleaksScanner::class)->scan($context));

        $this->assertSame(['src/real.ts'], $paths);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
}
```

Add imports for `PathClass`, `PathClassifier`, `RepoContext`, `TierProfileResolver` and `AuditTier`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=GitleaksScannerTest`
Expected: FAIL. `severityFor` is undefined, and the real-binary test reports 5 paths.

- [ ] **Step 3: Implement**

`resources/scanners/gitleaks.toml`:

```toml
title = "FlexPick Gitleaks config"

[extend]
useDefault = true

# Hits in these files are checksums, minified identifiers or vendored code,
# never a credential the customer committed. 376 of 418 hits on one
# customer repository were a code generator's lockfile checksums.
[[allowlists]]
description = "Lockfiles, minified files, build output and dependencies"
paths = [
  '''(^|/)(composer\.lock|package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|Cargo\.lock|Gemfile\.lock|poetry\.lock|go\.sum)$''',
  '''(^|/)[^/]*[-.]lock\.(json|ya?ml)$''',
  '''\.min\.(js|css)$''',
  '''\.map$''',
  '''(^|/)(storybook-static|node_modules|vendor|dist|coverage|__generated__)/''',
]

# Values published as examples everywhere. Allowlisting by value is limited
# to strings that are public by construction — never a customer's value.
[[allowlists]]
description = "Well-known public sample values"
regexTarget = "match"
regexes = [
  # The jwt.io sample token payload ({"sub":"1234567890","name":"John Doe","iat":1516239022}).
  '''eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ''',
  # Sequential hex placeholder keys.
  '''(?i)(0123456789abcdef){2,}''',
  '''(?i)(fedcba9876543210){2,}''',
]
stopwords = ["example", "placeholder", "changeme", "dummy", "your_", "xxxxxxxx", "redacted"]
```

`GitleaksScanner`:

```php
/**
 * Default-ruleset ids that identify one provider's credential format. A
 * match on one of these is a credential whatever file it sits in; everything
 * else (generic-api-key, jwt, *-client-id) is a shape, not proof.
 */
private const PROVIDER_RULE_PREFIXES = [
    'aws-', 'github-', 'gitlab-', 'stripe-', 'slack-', 'anthropic-', 'openai-', 'gcp-', 'private-key',
    'sendgrid-', 'twilio-', 'shopify-', 'npm-access-token', 'pypi-', 'digitalocean-', 'heroku-', 'hashicorp-',
    'doppler-', 'square-', 'mailgun-', 'azure-', 'databricks-', 'linear-', 'postman-', 'sentry-', 'huggingface-',
    'age-secret-key', 'flyio-', 'vault-',
];

private const FAMILIES = [
    'critical' => 'secrets.credential',
    'high' => 'secrets.possible-credential',
    'low' => 'secrets.likely-fixture',
];

/** @return list<Finding> */
public function normalize(array $sarif, string $repoPath, ?PathClassifier $classifier = null): array
{
    $classifier ??= new PathClassifier;

    $raw = $this->normalizer->normalize(
        $sarif,
        $this->name(),
        $repoPath,
        fn (): Severity => Severity::CRITICAL,
        fn (): string => self::FAMILIES['critical'],
        fn (): string => 'security_hygiene',
    );

    return array_map(function (Finding $finding) use ($classifier): Finding {
        // Repository attributes are ignored here: a repo must not be able to
        // mark its own leaked key "generated" and lower its severity.
        $severity = $this->severityFor($finding->ruleId, $classifier->classify($finding->path, ignoreRepoAttributes: true));

        return new Finding(
            tool: $finding->tool,
            ruleId: $finding->ruleId,
            ruleFamily: self::FAMILIES[$severity->value],
            severity: $severity,
            path: $finding->path,
            line: $finding->line,
            message: $finding->message,
            dimension: $finding->dimension,
        );
    }, $raw);
}

public function severityFor(string $ruleId, PathClass $class): Severity
{
    $provider = ! str_ends_with($ruleId, '-client-id') && array_any(
        self::PROVIDER_RULE_PREFIXES,
        fn (string $prefix): bool => str_starts_with($ruleId, $prefix),
    );

    return match ($class) {
        PathClass::Source => $provider ? Severity::CRITICAL : Severity::HIGH,
        PathClass::Generated, PathClass::Vendored, PathClass::Lockfile => $provider ? Severity::CRITICAL : Severity::LOW,
        PathClass::Test, PathClass::Docs, PathClass::Example => $provider ? Severity::HIGH : Severity::LOW,
    };
}
```

In `scan()`: `return $this->normalize($this->decode($report), $context->path, $context->classifier);`.

`app:smoke`: add one assertion that the configured gitleaks binary reports a version ≥ 8.25 (`[[allowlists]]` support). Follow the pattern of the command's existing assertions and gate it like the other binary checks. If the command has no binary checks, run `{bin} version`, parse it with `version_compare($v, '8.25.0', '>=')`, and fail with "gitleaks >= 8.25 required for the allowlist config".

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='GitleaksScannerTest|SmokeCommand|AuditPipelineTest'`
Expected: PASS. If `test_the_real_binary_honours_our_allowlists` still reports `src/sample.ts`, check gitleaks 8.28's `regexTarget` semantics (`match` vs `secret`) and use the target that matches the token. Don't widen the regex.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/Scanners/GitleaksScanner.php tests/Feature/Services/Scanners/GitleaksScannerTest.php app/Console/Commands
git add backend/resources/scanners/gitleaks.toml backend/app/Services/AuditReport/Scanners/GitleaksScanner.php backend/tests/Feature/Services/Scanners/GitleaksScannerTest.php <the smoke command file and its test>
git commit -m "fix(audit): secret findings are allowlisted for lockfiles and public samples, and graded by rule and location"
```

---

### Task 10: Scoring version 3

**Files:**
- Modify: `backend/app/Services/AuditReport/ScoreCalculator.php`
- Test: `backend/tests/Feature/Services/ScoreCalculatorTest.php`

**Interfaces:**
- Consumes: the secret families and severities (Task 9); manifest `ecosystem` and `lockfile` (Task 7).
- Produces: `ScoreCalculator::VERSION = 3`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_reports_the_current_scoring_version(): void   // replace the existing body's expected value
{
    $this->assertSame(3, ScoreCalculator::VERSION);
}

public function test_one_real_key_costs_35_and_fixture_noise_cannot_zero_the_score(): void
{
    $calculator = app(ScoreCalculator::class);
    $runs = $this->runs($this->allScanners());

    $oneKey = $calculator->calculate($this->metrics(), [$this->group('secrets.credential', Severity::CRITICAL, 1)], $runs);
    $noise = $calculator->calculate($this->metrics(), [$this->group('secrets.likely-fixture', Severity::LOW, 131)], $runs);
    $mixed = $calculator->calculate($this->metrics(), [
        $this->group('secrets.credential', Severity::CRITICAL, 1),
        $this->group('secrets.possible-credential', Severity::HIGH, 3),
        $this->group('secrets.likely-fixture', Severity::LOW, 25),
    ], $runs);

    $this->assertSame(65, $oneKey->scores['security_hygiene']);
    $this->assertSame(90, $noise->scores['security_hygiene']);
    $this->assertSame(25, $mixed->scores['security_hygiene']);   // 100 - 35 - 30 - 10
}

public function test_critical_secret_penalty_caps_at_80(): void
{
    $set = app(ScoreCalculator::class)->calculate(
        $this->metrics(),
        [$this->group('secrets.credential', Severity::CRITICAL, 50)],
        $this->runs($this->allScanners()),
    );

    $this->assertSame(20, $set->scores['security_hygiene']);
}

public function test_a_monorepo_missing_its_lockfile_loses_20_once_per_ecosystem(): void
{
    $metrics = $this->metrics();
    $metrics['manifests'] = [
        'package.json' => ['dependencies' => 2, 'dev_dependencies' => 0, 'lockfile' => false, 'ecosystem' => 'npm'],
        'packages/a/package.json' => ['dependencies' => 3, 'dev_dependencies' => 0, 'lockfile' => false, 'ecosystem' => 'npm'],
        'packages/b/package.json' => ['dependencies' => 0, 'dev_dependencies' => 0, 'lockfile' => false, 'ecosystem' => 'npm'],
        'composer.json' => ['dependencies' => 1, 'dev_dependencies' => 0, 'lockfile' => true, 'ecosystem' => 'composer'],
    ];

    $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

    $this->assertSame(80, $set->scores['dependencies']);
}

public function test_legacy_manifest_entries_without_ecosystem_still_deduct_per_manifest(): void
{
    $metrics = $this->metrics();
    $metrics['manifests'] = ['package.json' => ['dependencies' => 2, 'dev_dependencies' => 0, 'lockfile' => false]];

    $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

    $this->assertSame(80, $set->scores['dependencies']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ScoreCalculatorTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

Add to the `VERSION` docblock: `v3: secrets graded by class with diminishing penalties; lockfile deduction per ecosystem; generated code excluded from structure inputs.` Set `VERSION = 3`.

```php
private function dependencies(array $metrics, array $groups): int
{
    $score = 100;
    $uncovered = [];

    foreach (($metrics['manifests'] ?? []) as $key => $manifest) {
        $declares = (($manifest['dependencies'] ?? 0) + ($manifest['dev_dependencies'] ?? 0)) > 0;

        // Pre-v3 entries carry no ecosystem; they keep deducting per manifest.
        if (! ($manifest['lockfile'] ?? false) && ($declares || ! isset($manifest['ecosystem']))) {
            $uncovered[$manifest['ecosystem'] ?? 'manifest:'.$key] = true;
        }
    }

    $score -= 20 * count($uncovered);
    $score -= 8 * array_sum(array_map(fn (FindingGroup $g): int => $g->count, $groups));

    return $this->clamp($score);
}

private function securityHygiene(array $groups): int
{
    $score = 100;
    $secrets = ['critical' => 0, 'high' => 0, 'low' => 0];

    foreach ($groups as $group) {
        if (str_starts_with($group->ruleFamily, 'secrets.')) {
            $secrets[match ($group->severity) {
                Severity::CRITICAL => 'critical',
                Severity::HIGH => 'high',
                default => 'low',
            }] += $group->count;

            continue;
        }

        $score -= min(20, $group->count * 2);
    }

    // One committed live key is serious on its own; fixture-shaped noise in
    // tests and docs must not be able to read as a breach (v3).
    $score -= $secrets['critical'] > 0 ? min(80, 35 + 10 * ($secrets['critical'] - 1)) : 0;
    $score -= min(40, 10 * $secrets['high']);
    $score -= min(10, $secrets['low']);

    return $this->clamp($score);
}
```

Add `use App\Services\AuditReport\Findings\Severity;`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='ScoreCalculatorTest|AuditBenchmarkServiceTest|AuditDelta'`
Expected: PASS. Benchmarks and deltas already filter by version. If one asserts a literal `2`, change it to `ScoreCalculator::VERSION`.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport/ScoreCalculator.php tests/Feature/Services/ScoreCalculatorTest.php
git add backend/app/Services/AuditReport/ScoreCalculator.php backend/tests/Feature/Services/ScoreCalculatorTest.php
git commit -m "feat(audit): scoring v3 — graded secret penalties and one lockfile deduction per ecosystem"
```

---

### Task 11: Report facts, not-measured reasons, and the prompt's fixture note

**Files:**
- Create: `backend/app/Services/AuditReport/RepositoryFacts.php`
- Create: `backend/app/Services/AuditReport/NotMeasuredReason.php`
- Modify: `backend/resources/views/reports/audit-web.blade.php` (facts block ~line 176-206, not-measured tiles ~line 77-82)
- Modify: `backend/resources/views/reports/audit.blade.php` (facts table ~line 118-129, not-measured ~line 58-70)
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php` (persist `dependency_audit`)
- Modify: `backend/app/Services/AuditReport/PromptComposer.php::renderGroups`, `backend/app/Services/AuditReport/DeepReview/DeepReviewPromptComposer.php::renderGroups`
- Test: `backend/tests/Feature/Services/RepositoryFactsTest.php`, `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php`, `backend/tests/Feature/Services/PromptComposerTest.php` (or the existing prompt test; find it with `grep -rl "PromptComposer" backend/tests`)

**Interfaces:**
- Consumes: metrics keys from Tasks 2, 3, 7 and 8; group families from Task 9.
- Produces:
  - `RepositoryFacts::from(array $metrics, iterable $groups): array{files_total: int, loc_total: int, duplication_pct: ?float, test_ratio_pct: float, has_ci: bool, ci_systems: list<string>, secrets_likely: int, secrets_fixtures: int, vulnerable_dependencies: ?int, excluded_files: int}`. `$groups` holds `AuditFindingGroup` models or `FindingGroup` value objects.
  - `NotMeasuredReason::describe(string $reason): string`.

- [ ] **Step 1: Write the failing tests**

`RepositoryFactsTest`:

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\NotMeasuredReason;
use App\Services\AuditReport\RepositoryFacts;
use Tests\Feature\FeatureTest;

class RepositoryFactsTest extends FeatureTest
{
    private function group(string $family, Severity $severity, int $count): FindingGroup
    {
        return new FindingGroup($family, '.', $severity, $count, 0, [], ['gitleaks'], 'security_hygiene');
    }

    public function test_reads_tooling_facts_from_where_the_collector_stores_them(): void
    {
        $facts = RepositoryFacts::from([
            'tooling' => ['has_ci' => true, 'ci_systems' => ['github_actions', 'jenkins'], 'test_ratio_pct' => 21.0],
            'files_total' => 9157,
            'loc_total' => 1678749,
            'duplication_pct' => 3.2,
            'excluded_summary' => ['generated' => 120, 'docs' => 300],
            'dependency_audit' => ['packages_scanned' => 900, 'vulnerable_count' => 4],
        ], [
            $this->group('secrets.credential', Severity::CRITICAL, 1),
            $this->group('secrets.possible-credential', Severity::HIGH, 2),
            $this->group('secrets.likely-fixture', Severity::LOW, 25),
            $this->group('semgrep.sqli', Severity::HIGH, 9),
        ]);

        $this->assertTrue($facts['has_ci']);
        $this->assertSame(['github_actions', 'jenkins'], $facts['ci_systems']);
        $this->assertSame(21.0, $facts['test_ratio_pct']);
        $this->assertSame(3, $facts['secrets_likely']);
        $this->assertSame(25, $facts['secrets_fixtures']);
        $this->assertSame(4, $facts['vulnerable_dependencies']);
        $this->assertSame(420, $facts['excluded_files']);
    }

    public function test_unmeasured_duplication_is_null_not_zero(): void
    {
        $facts = RepositoryFacts::from(['duplication_pct' => 0.0, 'not_measured' => ['duplication']], []);

        $this->assertNull($facts['duplication_pct']);
    }

    public function test_legacy_metrics_still_render_facts(): void
    {
        $facts = RepositoryFacts::from(['has_ci' => true, 'test_ratio_pct' => 12.5, 'secret_findings' => [['count' => 2]]], []);

        $this->assertTrue($facts['has_ci']);
        $this->assertSame(12.5, $facts['test_ratio_pct']);
        $this->assertSame(2, $facts['secrets_likely']);
        $this->assertSame([], $facts['ci_systems']);
        $this->assertNull($facts['vulnerable_dependencies']);
    }

    public function test_every_reason_code_has_customer_copy(): void
    {
        foreach (['no_report', 'empty_output', 'osv_unreachable', 'no_lockfile', 'lockfile_unreadable', 'timeout', 'unavailable', 'nonzero_exit', 'parse_failure', 'not_run', 'something_new'] as $code) {
            $this->assertNotSame('', NotMeasuredReason::describe($code));
            $this->assertStringNotContainsString('_', NotMeasuredReason::describe($code));
        }
    }
}
```

In `AuditReportControllerTest`, add a test that sets real-shaped metrics on the report's request and asserts the facts render:

```php
public function test_web_report_facts_read_tooling_metrics(): void
{
    $report = AuditReport::factory()->create();
    $report->auditRequest->update(['metrics' => [
        'files_total' => 10,
        'loc_total' => 1000,
        'duplication_pct' => 1.5,
        'tooling' => ['has_ci' => true, 'ci_systems' => ['jenkins'], 'test_ratio_pct' => 21.0, 'error_monitoring' => false, 'linter' => true, 'static_analysis' => true, 'env_example' => true, 'dockerized' => true],
        'not_measured' => ['dependencies'],
        'not_measured_reasons' => ['dependencies' => 'lockfile_unreadable'],
    ]]);

    $response = $this->get(app(AuditReportService::class)->signedUrl($report));

    $response->assertStatus(200);
    $response->assertSeeInOrder(['21', '%', 'test file ratio']);
    $response->assertSeeInOrder(['yes', 'CI configured']);
    $response->assertSee(NotMeasuredReason::describe('lockfile_unreadable'));
}
```

In the prompt test:

```php
public function test_fixture_secret_groups_carry_a_do_not_call_these_leaks_note(): void
{
    $group = new FindingGroup('secrets.likely-fixture', 'tests', Severity::LOW, 25, 25, [], ['gitleaks'], 'security_hygiene');

    $prompt = app(PromptComposer::class)->compose([], [$group], []);

    $this->assertStringContainsString('likely test fixtures', $prompt);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryFactsTest|AuditReportControllerTest|PromptComposer'`
Expected: FAIL.

- [ ] **Step 3: Implement**

`RepositoryFacts.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Services\AuditReport\Findings\FindingGroup;

/**
 * The "Repository facts" panel, computed once for both report views.
 *
 * Exists because the views read top-level keys (`has_ci`, `test_ratio_pct`,
 * `secret_findings`) that the collectors store elsewhere or no longer write,
 * so a customer saw "CI: no / 0% tests / 0 secrets" next to a testing score
 * of 100 and 131 secret findings. Legacy keys are still read so reports
 * stored before the fix render correctly too.
 */
final class RepositoryFacts
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  iterable<AuditFindingGroup|FindingGroup>  $groups
     * @return array{files_total: int, loc_total: int, duplication_pct: ?float, test_ratio_pct: float, has_ci: bool, ci_systems: list<string>, secrets_likely: int, secrets_fixtures: int, vulnerable_dependencies: ?int, excluded_files: int}
     */
    public static function from(array $metrics, iterable $groups): array
    {
        $tooling = is_array($metrics['tooling'] ?? null) ? $metrics['tooling'] : [];
        $likely = 0;
        $fixtures = 0;
        $sawSecretGroups = false;

        foreach ($groups as $group) {
            [$family, $severity, $count] = $group instanceof FindingGroup
                ? [$group->ruleFamily, $group->severity->value, $group->count]
                : [(string) $group->rule_family, (string) $group->severity, (int) $group->count];

            if (! str_starts_with($family, 'secrets.')) {
                continue;
            }

            $sawSecretGroups = true;
            in_array($severity, ['critical', 'high'], true) ? $likely += $count : $fixtures += $count;
        }

        if (! $sawSecretGroups) {
            $likely = (int) array_sum(array_column((array) ($metrics['secret_findings'] ?? []), 'count'));
        }

        $audit = $metrics['dependency_audit'] ?? null;

        return [
            'files_total' => (int) ($metrics['files_total'] ?? 0),
            'loc_total' => (int) ($metrics['loc_total'] ?? 0),
            'duplication_pct' => in_array('duplication', (array) ($metrics['not_measured'] ?? []), true)
                ? null
                : (float) ($metrics['duplication_pct'] ?? 0),
            'test_ratio_pct' => (float) ($tooling['test_ratio_pct'] ?? $metrics['test_ratio_pct'] ?? 0),
            'has_ci' => (bool) ($tooling['has_ci'] ?? $metrics['has_ci'] ?? false),
            'ci_systems' => array_values((array) ($tooling['ci_systems'] ?? [])),
            'secrets_likely' => $likely,
            'secrets_fixtures' => $fixtures,
            'vulnerable_dependencies' => is_array($audit) && ! isset($audit['error']) ? (int) ($audit['vulnerable_count'] ?? 0) : null,
            'excluded_files' => (int) array_sum((array) ($metrics['excluded_summary'] ?? [])),
        ];
    }
}
```

`NotMeasuredReason.php`:

```php
<?php

namespace App\Services\AuditReport;

/** Customer-facing copy for a not-measured dimension. Codes come from ScoreSet::$notMeasuredReasons. */
final class NotMeasuredReason
{
    public static function describe(string $reason): string
    {
        return match ($reason) {
            'osv_unreachable' => __('The vulnerability database could not be reached during this run.'),
            'no_lockfile' => __('No lockfile was found, so installed dependency versions could not be checked.'),
            'lockfile_unreadable' => __('This repository\'s lockfile format (for example bun.lockb) can\'t be read, so dependency versions could not be checked.'),
            'timeout' => __('The scan was stopped because it ran too long on a repository this size.'),
            'unavailable', 'not_run' => __('The scanner this score depends on did not run for this report.'),
            default => __('The scanner did not complete, so this score is left out rather than guessed.'),
        };
    }
}
```

`AuditPipeline`, after `$metrics['duplication_pct'] = …`:

```php
if ($suite->ranSuccessfully('osv')) {
    $metrics['dependency_audit'] = [
        'packages_scanned' => (int) $context->measurement('packages_scanned', 0),
        'vulnerable_count' => (int) $context->measurement('vulnerable_count', 0),
    ];
}
```

`audit-web.blade.php` facts block: replace the six fact tiles with tiles driven by `@php($facts = \App\Services\AuditReport\RepositoryFacts::from($metrics, $report->auditRequest->findingGroups))`:
- source files: `number_format($facts['files_total'])`
- lines of code: `number_format($facts['loc_total'])`
- duplicated lines: `$facts['duplication_pct'] === null ? '—' : $facts['duplication_pct'].'%'`
- test file ratio: `$facts['test_ratio_pct'].'%'`
- CI configured: `$facts['has_ci'] ? __('yes') : __('no')`. If `ci_systems` is non-empty, add a second line: `implode(', ', array_map(fn ($s) => str_replace('_', ' ', $s), $facts['ci_systems']))`.
- potential secrets: `$facts['secrets_likely']`. When `secrets_fixtures > 0`, add `<div class="text-[11px] text-stone-500">+{{ $facts['secrets_fixtures'] }} {{ __('likely fixtures') }}</div>`.
- vulnerable dependencies: shown when `$facts['vulnerable_dependencies'] !== null`.

After the Languages paragraph: when `$facts['excluded_files'] > 0`, show `<p class="mt-2 text-xs text-stone-500">{{ __(':n generated, vendored, lockfile and documentation files are excluded from size and structure metrics.', ['n' => number_format($facts['excluded_files'])]) }}</p>`.

Not-measured tiles: replace the static `title` with the reason, and add a visible line:

```blade
@php($reason = \App\Services\AuditReport\NotMeasuredReason::describe($report->auditRequest->metrics['not_measured_reasons'][$dimension] ?? 'not_run'))
<div class="text-[13px] text-stone-500" title="{{ $reason }}">{{ __('Not measured') }}</div>
<div class="text-[11px] uppercase tracking-wider text-stone-500">{{ str_replace('_', ' ', $dimension) }}</div>
<div class="mt-1 text-[11px] text-stone-500">{{ $reason }}</div>
```

`audit.blade.php` (PDF): make the same replacement in the facts table using `$facts`. After the scores table, add one `<p class="muted">` per not-measured dimension: `{{ str_replace('_', ' ', $dimension) }}: {{ NotMeasuredReason::describe(...) }}`.

`PromptComposer::renderGroups` and `DeepReviewPromptComposer::renderGroups`: after the `sprintf` for each group, append:

```php
if ($group->ruleFamily === 'secrets.likely-fixture') {
    $rendered .= "  note: credential-shaped values in tests, docs or examples; likely test fixtures. Do not describe these as leaked credentials.\n";
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryFactsTest|AuditReportControllerTest|PromptComposer|DeepReview|AuditReportService'`
Expected: PASS. Also render one PDF via the existing `AuditReportService` test to confirm `audit.blade.php` compiles.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport tests/Feature/Services/RepositoryFactsTest.php tests/Feature/Http/Controllers/AuditReportControllerTest.php
git add backend/app/Services/AuditReport/RepositoryFacts.php backend/app/Services/AuditReport/NotMeasuredReason.php backend/app/Services/AuditReport/AuditPipeline.php backend/app/Services/AuditReport/PromptComposer.php backend/app/Services/AuditReport/DeepReview/DeepReviewPromptComposer.php backend/resources/views/reports/audit-web.blade.php backend/resources/views/reports/audit.blade.php backend/tests/Feature/Services/RepositoryFactsTest.php backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php <prompt test file>
git commit -m "fix(audit): repository facts read the stored metrics, and not-measured scores say why"
```

---

### Task 12: `AuditMeasurer`, `app:audit-dry-run`, and the monorepo regression fixture

**Files:**
- Create: `backend/app/Services/AuditReport/AuditMeasurer.php`
- Create: `backend/app/Services/AuditReport/Measurement.php`
- Create: `backend/app/Console/Commands/AuditDryRun.php`
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php` (lines ~95-140 delegate to the measurer)
- Test: `backend/tests/Feature/Services/AuditPrecisionRegressionTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces:
  - `AuditMeasurer::measure(RepoContext $context, list<string> $scanners, ?callable $afterInventory = null): Measurement`. The callback signature is `callable(SccInventory $inventory, bool $fellBack): void`. It runs after scc and before every other scanner, and may throw: the pipeline's sizing throws `AuditAwaitingCreditException`.
  - `final readonly class Measurement { ScannerSuiteResult $suite; list<FindingGroup> $groups; array $metrics; list<array{path,content}> $excerpts; ScoreSet $scores }`.
  - Command `php artisan app:audit-dry-run {path} {--tier=diagnostic} {--json}`.

- [ ] **Step 1: Write the failing regression test**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\AuditMeasurer;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Measurement;
use App\Services\AuditReport\RepositoryFacts;
use App\Services\AuditReport\Scanners\GitleaksScanner;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

/**
 * The 2026-09-25 skaile-ai/platform report, reduced to the patterns that
 * produced its false positives. Each assertion is one line of the customer's
 * rebuttal that this run must not repeat.
 */
class AuditPrecisionRegressionTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(SccScanner::class)->isAvailable() || ! app(GitleaksScanner::class)->isAvailable()) {
            $this->markTestSkipped('scc and gitleaks are required for the regression fixture.');
        }

        $this->repo = sys_get_temp_dir().'/precision-'.bin2hex(random_bytes(6));
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            exec('rm -rf '.escapeshellarg($this->repo));
        }

        parent::tearDown();
    }

    private function put(string $path, string $content): void
    {
        @mkdir(dirname($this->repo.'/'.$path), 0755, true);
        file_put_contents($this->repo.'/'.$path, $content);
    }

    private function buildFixture(): void
    {
        // Credential-shaped values are derived at run time: nothing secret-looking is committed.
        $generic = substr(base64_encode(hash('sha256', 'precision-generic', true)), 0, 32);
        $pat = 'ghp_'.substr(hash('sha256', 'precision-pat'), 0, 36);
        $code = fn (int $n, string $prefix = 'x'): string => implode("\n", array_map(fn (int $i): string => "export const {$prefix}{$i} = {$i};", range(1, $n)))."\n";

        $this->put('package.json', json_encode(['workspaces' => ['packages/*'], 'devDependencies' => ['typescript' => '^5']]));
        $this->put('bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"packages\": {\n    \"typescript\": [\"typescript@5.4.5\", \"\", {}, \"sha512-x\"],\n  },\n}\n");
        $this->put('packages/api/package.json', json_encode(['name' => 'api', 'dependencies' => ['@sentry/bun' => '^8']]));
        $this->put('packages/api/src/gif-search.service.ts', "const GIPHY_API_KEY = \"{$generic}\";\n".$code(40, 'g'));
        $this->put('packages/api/src/token.ts', "export const t = '{$pat}';\n");
        $this->put('packages/api/src/session.ts', $code(300, 's'));
        $this->put('packages/api/test/auth.test.ts', "const api_key = \"{$generic}\";\nconst jwt = \"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c\";\n");
        $this->put('postxl-lock.json', json_encode(['files' => ['a.ts' => ['apiKey' => $generic]]], JSON_PRETTY_PRINT));
        $this->put('packages/db/src/models/User.ts', "/* !!! This is code generated by Prisma. Do not edit directly. !!! */\n".$code(1200, 'u'));
        $this->put('proto/storybook-static/sb-manager/runtime.js', str_repeat('var a=1;', 4000)."\n");
        $this->put('Jenkinsfile', "pipeline { stages { stage('b') { steps { sh 'git clone https://\$GIT_TOKEN@example.com/r.git' } } } }\n");
        $this->put('.github/workflows/ci.yml', "on: push\njobs: {}\n");
        $this->put('CHANGELOG.md', str_repeat("- change\n", 2000));
    }

    private function measure(): Measurement
    {
        Http::fake([(string) config('audit.osv_endpoint') => Http::response(['results' => [['vulns' => []]]])]);

        $context = new RepoContext(path: $this->repo, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));

        return app(AuditMeasurer::class)->measure($context, ['scc', 'gitleaks', 'osv', 'jscpd']);
    }

    public function test_the_platform_false_positives_do_not_recur(): void
    {
        $m = $this->measure();
        $facts = RepositoryFacts::from($m->metrics, $m->groups);

        // "CI configured: no" / "test file ratio 0%"
        $this->assertTrue($facts['has_ci']);
        $this->assertSame(['github_actions', 'jenkins'], $facts['ci_systems']);
        $this->assertGreaterThan(0.0, $facts['test_ratio_pct']);

        // "No dependency lockfile"
        $this->assertTrue($m->metrics['manifests']['package.json']['lockfile']);
        $this->assertTrue($m->metrics['manifests']['packages/api/package.json']['lockfile']);
        $this->assertNotContains('dependencies', $m->scores->notMeasured);

        // "No runtime error monitoring"
        $this->assertTrue($m->metrics['tooling']['error_monitoring']);

        // 131 critical credentials → exactly one real key, one possible, the rest fixtures
        $secrets = [];
        foreach ($m->groups as $group) {
            if (str_starts_with($group->ruleFamily, 'secrets.')) {
                $secrets[$group->severity->value] = ($secrets[$group->severity->value] ?? 0) + $group->count;
                foreach ($group->examples as $example) {
                    $this->assertNotSame('postxl-lock.json', $example['path']);
                }
            }
        }
        $this->assertSame(1, $secrets[Severity::CRITICAL->value] ?? 0);
        $this->assertSame(1, $secrets[Severity::HIGH->value] ?? 0);
        $this->assertSame(1, $secrets[Severity::LOW->value] ?? 0);
        $this->assertGreaterThan(0, $m->scores->scores['security_hygiene']);

        // Structure 0 driven by generated Prisma clients and Storybook bundles
        $largest = array_column($m->metrics['largest_files'], 'path');
        $this->assertNotContains('packages/db/src/models/User.ts', $largest);
        $this->assertNotContains('proto/storybook-static/sb-manager/runtime.js', $largest);
        $this->assertNotContains('CHANGELOG.md', $largest);
        $this->assertContains('structure.committed-build-output', array_map(fn ($g) => $g->ruleFamily, $m->groups));
        $this->assertGreaterThan(0, $m->metrics['excluded_summary']['generated'] ?? 0);
    }

    public function test_the_dry_run_command_prints_facts_and_scores(): void
    {
        Http::fake([(string) config('audit.osv_endpoint') => Http::response(['results' => [['vulns' => []]]])]);

        $this->artisan('app:audit-dry-run', ['path' => $this->repo])
            ->expectsOutputToContain('CI configured')
            ->expectsOutputToContain('security_hygiene')
            ->assertSuccessful();
    }
}
```

The `$secrets[LOW] === 1` assertion is the one generic key in `auth.test.ts`. The jwt.io sample in the same file must be allowlisted. If gitleaks reports the jwt as a separate LOW hit, Task 9's allowlist is wrong: fix the allowlist, not this assertion.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=AuditPrecisionRegressionTest`
Expected: FAIL. Class `AuditMeasurer` not found.

- [ ] **Step 3: Implement `Measurement`, `AuditMeasurer`, and the pipeline delegation**

`Measurement.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;

final readonly class Measurement
{
    /**
     * @param  list<FindingGroup>  $groups
     * @param  array<string, mixed>  $metrics
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function __construct(
        public ScannerSuiteResult $suite,
        public array $groups,
        public array $metrics,
        public array $excerpts,
        public ScoreSet $scores,
    ) {}
}
```

`AuditMeasurer.php`: move the code verbatim from `AuditPipeline::run()`, from `$sccSuite = $this->scannerRunner->run(['scc'], $context);` through `$metrics['not_measured_reasons'] = …` plus the `dependency_audit` block from Task 11. The sizing/logging lines become the callback:

```php
<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Scanners\SccScanner;

/**
 * Scanners → findings → collectors → scores: everything deterministic about a
 * run. The pipeline wraps it with sizing, AI and delivery; app:audit-dry-run
 * and the regression fixture call it bare, so they exercise the same code a
 * customer's run does.
 */
class AuditMeasurer
{
    public function __construct(
        private ScannerRunner $scannerRunner,
        private SccScanner $sccScanner,
        private FindingDeduplicator $deduplicator,
        private FindingGrouper $grouper,
        private MetricsCollector $metricsCollector,
        private ScoreCalculator $scoreCalculator,
    ) {}

    /**
     * @param  list<string>  $scanners  the tier's scanners; scc always runs first
     * @param  (callable(SccInventory, bool): void)|null  $afterInventory  runs before any other scanner is paid for
     */
    public function measure(RepoContext $context, array $scanners, ?callable $afterInventory = null): Measurement
    {
        $sccSuite = $this->scannerRunner->run(['scc'], $context);

        // scc failing must not leave later stages without a basis (spec §10).
        $fellBack = $context->inventory === null;
        if ($fellBack) {
            $context->withClassifier(PathClassifier::forRepository($context->path));
            $context->withInventory($this->sccScanner->fallbackInventory($context->path, $context->classifier));
        }

        if ($afterInventory !== null) {
            $afterInventory($context->inventory, $fellBack);
        }

        $suite = $sccSuite->merge($this->scannerRunner->run(
            array_values(array_filter($scanners, fn (string $name): bool => $name !== 'scc')),
            $context,
        ));

        $groups = $this->grouper->group($this->deduplicator->dedupe($suite->findings));

        // Q17: excerpt collection and risk-file selection both read this.
        $context->withSecretPaths($this->secretPaths($suite));

        $collected = $this->metricsCollector->collect($context);
        $metrics = $collected['metrics'];
        $metrics['duplication_pct'] = (float) $context->measurement('duplication_pct', 0.0);

        if ($suite->ranSuccessfully('osv')) {
            $metrics['dependency_audit'] = [
                'packages_scanned' => (int) $context->measurement('packages_scanned', 0),
                'vulnerable_count' => (int) $context->measurement('vulnerable_count', 0),
            ];
        }

        $scores = $this->scoreCalculator->calculate($metrics, $groups, $suite);
        $metrics['computed_scores'] = $scores->toPayloadScores();
        $metrics['not_measured'] = $scores->notMeasured;
        $metrics['not_measured_reasons'] = $scores->notMeasuredReasons;

        return new Measurement($suite, $groups, $metrics, $collected['excerpts'], $scores);
    }

    /** @return list<string> */
    private function secretPaths(ScannerSuiteResult $suite): array
    {
        // moved verbatim from AuditPipeline::secretPaths()
    }
}
```

Move `secretPaths()`'s body over unchanged and delete it from `AuditPipeline`. Bring the comments that sat on the moved lines along with them.

`AuditPipeline::run()` then reads:

```php
$measurement = $this->measurer->measure($context, $profile->scanners, function (SccInventory $inventory, bool $fellBack) use ($auditRequest): void {
    if ($fellBack) {
        $auditRequest->appendPipelineLog('inventory', 'scc unavailable; used a walked file inventory');
    }

    // existing AuditSize / runSizer->settle / appendPipelineLog('sized', …) lines, unchanged
});
$suite = $measurement->suite;
$groups = $measurement->groups;
$metrics = $measurement->metrics;
$scoreSet = $measurement->scores;
$collected = ['excerpts' => $measurement->excerpts];
$this->logScannerOutcomes($auditRequest, $suite);
```

Everything from `$auditRequest->update(['metrics' => $metrics, …])` onward stays. Inject `AuditMeasurer $measurer` into the constructor. Leave the now-unused dependencies on the constructor only if other methods still use them (`deduplicator` is used by deep review). Remove the rest. Update `tests/Support/RunsAuditPipelineWithFakes.php` if it constructs the pipeline by hand.

- [ ] **Step 4: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Constants\AuditTier;
use App\Services\AuditReport\AuditMeasurer;
use App\Services\AuditReport\NotMeasuredReason;
use App\Services\AuditReport\RepositoryFacts;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Console\Command;

/**
 * Runs the deterministic half of an audit — scanners, collectors, scores —
 * on a local directory. No clone, no database writes, no AI call, no charge.
 * For checking a scanner change against a real repository before it ships.
 */
class AuditDryRun extends Command
{
    protected $signature = 'app:audit-dry-run {path : Local repository directory} {--tier=diagnostic} {--json : Print the full measurement as JSON}';

    protected $description = 'Measure a local repository with the audit scanners, without AI, billing or delivery';

    public function handle(AuditMeasurer $measurer, TierProfileResolver $tiers): int
    {
        $path = realpath((string) $this->argument('path'));

        if ($path === false || ! is_dir($path)) {
            $this->error('Not a directory: '.$this->argument('path'));

            return self::FAILURE;
        }

        $profile = $tiers->for(AuditTier::from((string) $this->option('tier')));
        $measurement = $measurer->measure(new RepoContext($path, $profile), $profile->scanners);
        $facts = RepositoryFacts::from($measurement->metrics, $measurement->groups);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'facts' => $facts,
                'scores' => $measurement->scores->scores,
                'not_measured' => $measurement->scores->notMeasuredReasons,
                'groups' => array_map(fn ($g) => [
                    'family' => $g->ruleFamily, 'directory' => $g->directory, 'severity' => $g->severity->value,
                    'count' => $g->count, 'examples' => $g->examples,
                ], $measurement->groups),
                'runs' => $measurement->suite->runsAsArray(),
                'metrics' => $measurement->metrics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Fact', 'Value'], [
            ['Source files', number_format($facts['files_total'])],
            ['Lines of code', number_format($facts['loc_total'])],
            ['Excluded files', number_format($facts['excluded_files'])],
            ['Duplicated lines', $facts['duplication_pct'] === null ? '—' : $facts['duplication_pct'].'%'],
            ['Test file ratio', $facts['test_ratio_pct'].'%'],
            ['CI configured', ($facts['has_ci'] ? 'yes' : 'no').($facts['ci_systems'] !== [] ? ' ('.implode(', ', $facts['ci_systems']).')' : '')],
            ['Potential secrets', $facts['secrets_likely'].' (+'.$facts['secrets_fixtures'].' likely fixtures)'],
            ['Vulnerable dependencies', $facts['vulnerable_dependencies'] ?? 'not checked'],
        ]);

        $this->table(['Dimension', 'Score'], array_map(
            fn ($dimension, $score) => [$dimension, $score],
            array_keys($measurement->scores->scores),
            $measurement->scores->scores,
        ));

        foreach ($measurement->scores->notMeasuredReasons as $dimension => $reason) {
            $this->warn("{$dimension}: not measured — ".NotMeasuredReason::describe($reason)." ({$reason})");
        }

        $this->table(['Family', 'Directory', 'Severity', 'Count'], array_map(
            fn ($g) => [$g->ruleFamily, $g->directory, $g->severity->value, $g->count],
            $measurement->groups,
        ));

        return self::SUCCESS;
    }
}
```

Laravel 13 auto-discovers commands in `app/Console/Commands`. If this project registers them explicitly (check `bootstrap/app.php` `withCommands`), register it there.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='AuditPrecisionRegressionTest|AuditPipelineTest|AuditPrepaidRunTest|AuditRequestRoutingTest'`
Expected: PASS. `AuditRequestRoutingTest` has a known pre-existing preflight failure on `file://` fixture URLs (see project memory, 2026-10-01). Compare against `git stash` to confirm any failure there predates this branch before you investigate it.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/AuditReport app/Console/Commands/AuditDryRun.php tests/Feature/Services/AuditPrecisionRegressionTest.php tests/Support
git add backend/app/Services/AuditReport/AuditMeasurer.php backend/app/Services/AuditReport/Measurement.php backend/app/Services/AuditReport/AuditPipeline.php backend/app/Console/Commands/AuditDryRun.php backend/tests/Feature/Services/AuditPrecisionRegressionTest.php backend/tests/Support/RunsAuditPipelineWithFakes.php
git commit -m "feat(audit): extract AuditMeasurer, add app:audit-dry-run, and pin the platform false positives in a regression fixture"
```

---

### Task 13: Acceptance run on `platform-main`, then the full gate

**Files:**
- Create: `backend/docs/superpowers/plans/2026-10-05-audit-precision-acceptance.md` (results record)
- Possibly modify: `backend/resources/scanners/gitleaks.toml` (public sample values only)

- [ ] **Step 1: Copy the reference repository into the container's reach**

`/var/www/html/platform-main` is a host path the container can't see, and it has no `.git`. Copy it into the bind-mounted backend storage. Leave out Windows `Zone.Identifier` streams and dependencies:

```bash
rsync -a --delete --exclude='*:Zone.Identifier' --exclude='node_modules' /var/www/html/platform-main/ /var/www/html/flexpick.net/backend/storage/framework/testing/platform-main/
```

- [ ] **Step 2: Run the dry run**

```bash
docker compose exec -T laravel.test php artisan app:audit-dry-run storage/framework/testing/platform-main --tier=deep_ai --json > /tmp/claude-1000/platform-dry-run.json
docker compose exec -T laravel.test php artisan app:audit-dry-run storage/framework/testing/platform-main --tier=deep_ai
```

OSV makes real network calls here. That's intended.

- [ ] **Step 3: Check against the customer's rebuttal**

Each line must hold. Record the actual value next to it in the acceptance file:

| Customer rebuttal | Expected now |
|---|---|
| CI configured: "no" is wrong (17 workflows) | `has_ci` yes; `ci_systems` includes `github_actions`, `bitbucket_pipelines`, `jenkins` |
| Test file ratio 0% is wrong | ≈ 20% |
| Potential secrets 0 contradicts 131 | `secrets_likely` small (single digits), `secrets_fixtures` holds the rest; total far below 131 |
| No `postxl-lock.json` hits | no secret example path equals `postxl-lock.json` |
| The GIF-search key is the one real key | `backend/libs/capabilities/src/gif-search.service.ts` is in a critical or high group |
| bun.lock is committed | every `package.json` manifest has `lockfile: true` and `lockfile_kind: bun.lock`; `dependencies` is measured, or not-measured with `lockfile_unreadable` only if a package uses `bun.lockb` |
| Storybook output is build output | one `structure.committed-build-output` group for `_concept/prototype/storybook/storybook-static` |
| Prisma client committed by design | `backend/libs/database/src/prisma/models/User.ts` absent from `largest_files`, counted in `excluded_summary.generated` |
| Structure 0 / security 0 | both well above 0 |
| Duplication 0% | either a measured, plausible percentage, or `duplication` not measured with a reason. Never 0% from a failed run |

- [ ] **Step 4: Tune only what the spec allows**

If a remaining `secrets_likely` hit is a **publicly known sample value** (for example a library's documented demo key), add a value regex for it to the "Well-known public sample values" allowlist, with a comment naming the source. Then re-run Step 2 and add a row to `GitleaksScannerTest::test_the_real_binary_honours_our_allowlists`.

Don't allowlist by value anything the customer wrote, and don't add path allowlists for test directories: test hits are meant to stay visible as `likely-fixture`. Any other deviation from the table goes in the acceptance file as an open item. Don't patch it in this task.

- [ ] **Step 5: Run the full gate**

```bash
docker compose exec -T laravel.test php artisan test --compact
docker compose exec -T laravel.test vendor/bin/pint --test
docker compose exec -T laravel.test vendor/bin/phpstan analyse
```

Expected: all pass. On a `QueryException` storm, re-run once: the shared test DB is a known issue. Fix any PHPStan errors in the files this branch touched.

- [ ] **Step 6: Commit the acceptance record**

```bash
git add backend/docs/superpowers/plans/2026-10-05-audit-precision-acceptance.md backend/resources/scanners/gitleaks.toml backend/tests/Feature/Services/Scanners/GitleaksScannerTest.php
git commit -m "docs(audit): record the platform-main acceptance run for audit precision"
```

Don't commit `storage/framework/testing/platform-main` (gitignored) or `/tmp/claude-1000/platform-dry-run.json`.
