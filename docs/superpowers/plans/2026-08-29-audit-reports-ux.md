# Audit Reports Page UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve `App\Filament\Dashboard\Pages\AuditReports` — a richer score-history chart, weekly day-of-week scheduling with a calendar of past/upcoming runs, skip-scheduled-run-when-unchanged via `git ls-remote`, and a GitHub branch selector for both one-off and scheduled audits.

**Architecture:** All work is backend-only (`backend/`), inside the existing Filament dashboard panel + Livewire page pattern already used by `AuditReports`. New logic is decomposed into small, independently-testable service classes (`GitHubApiClient`, `ScheduledAuditChangeChecker`, `ScoreChartBuilder`, `ScheduleOccurrenceProjector`) consumed by the existing `AuditReports` page and `RunScheduledAudits` command, rather than growing those two classes internally. No new Composer/npm dependency.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Alpine.js (already shipped with Livewire), plain Blade/SVG (no JS chart library).

**Spec:** `docs/superpowers/specs/2026-08-29-audit-reports-ux-design.md`

## Global Constraints

- No new Composer or npm dependency (design non-goal — no chart library, no calendar plugin).
- Monthly schedules do not get day-of-week/occurrence-of-month picking — only weekly schedules gain `day_of_week`. Monthly keeps today's "same day-of-month as last run" behavior.
- Branch listing reuses the existing shared `AUDIT_GITHUB_TOKEN` PAT (`config('audit.github_token')`) via a new REST client — no per-user OAuth scope changes.
- The change check (`git ls-remote`) **fails open**: any error there must still let the scheduled run proceed, never silently stop a schedule.
- Run `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` clean before any task is considered done (per `backend/CLAUDE.MD`).
- All new/changed backend code follows this repo's existing conventions: PHPUnit (`TestCase`-based, not Pest), `php artisan test --filter=ClassName` to run a single test class, factories for fixtures, `Tests\Feature\FeatureTest` as the base class for feature tests.

---

### Task 1: `audit_schedules` gains `branch`, `day_of_week`, `last_commit_sha`

**Files:**
- Create: `backend/database/migrations/2026_08_29_000001_add_scheduling_columns_to_audit_schedules_table.php`
- Modify: `backend/app/Models/AuditSchedule.php`
- Modify: `backend/database/factories/AuditScheduleFactory.php`
- Test: `backend/tests/Unit/Models/AuditScheduleTest.php`

**Interfaces:**
- Produces: `AuditSchedule::$fillable` now includes `branch`, `day_of_week`, `last_commit_sha`; `day_of_week` casts to `int`. Later tasks (8, 11) read/write these three columns directly on the model.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\AuditSchedule;
use Tests\Feature\FeatureTest;

class AuditScheduleTest extends FeatureTest
{
    public function test_branch_day_of_week_and_last_commit_sha_are_mass_assignable(): void
    {
        $schedule = AuditSchedule::factory()->create([
            'branch' => 'develop',
            'day_of_week' => 3,
            'last_commit_sha' => 'abc123',
        ]);

        $schedule->refresh();

        $this->assertSame('develop', $schedule->branch);
        $this->assertSame(3, $schedule->day_of_week);
        $this->assertIsInt($schedule->day_of_week);
        $this->assertSame('abc123', $schedule->last_commit_sha);
    }

    public function test_the_new_columns_default_to_null(): void
    {
        $schedule = AuditSchedule::factory()->create();

        $this->assertNull($schedule->branch);
        $this->assertNull($schedule->day_of_week);
        $this->assertNull($schedule->last_commit_sha);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AuditScheduleTest`
Expected: FAIL — "branch" is not a fillable attribute / column does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->string('branch')->nullable()->after('repo_url');
            $table->unsignedTinyInteger('day_of_week')->nullable()->after('frequency');
            $table->string('last_commit_sha', 64)->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->dropColumn(['branch', 'day_of_week', 'last_commit_sha']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

Edit `backend/app/Models/AuditSchedule.php` (currently lines 14-16):

```php
    protected $fillable = [
        'user_id', 'tenant_id', 'repo_url', 'frequency', 'tier', 'last_run_at',
        'branch', 'day_of_week', 'last_commit_sha',
    ];

    protected $casts = ['last_run_at' => 'datetime', 'tier' => AuditTier::class, 'day_of_week' => 'integer'];
```

- [ ] **Step 5: Update the factory**

Edit `backend/database/factories/AuditScheduleFactory.php` — no change needed to `definition()` itself (the new columns are nullable and default to `null`, matching the "defaults to null" test above); leave the factory as-is.

- [ ] **Step 6: Run migrations and the test**

Run: `cd backend && php artisan migrate && php artisan test --filter=AuditScheduleTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
cd backend && git add database/migrations/2026_08_29_000001_add_scheduling_columns_to_audit_schedules_table.php app/Models/AuditSchedule.php tests/Unit/Models/AuditScheduleTest.php
git commit -m "feat(audit): add branch, day_of_week, last_commit_sha to audit_schedules"
```

---

### Task 2: `audit_requests` gains `branch`

**Files:**
- Create: `backend/database/migrations/2026_08_29_000002_add_branch_to_audit_requests_table.php`
- Modify: `backend/app/Models/AuditRequest.php`
- Test: `backend/tests/Unit/Models/AuditRequestBranchTest.php`

**Interfaces:**
- Produces: `AuditRequest::$fillable` includes `branch`. Task 6 (`AuditPipeline`) and Task 11 (`AuditReports::launchAudit()`) read/write it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestBranchTest extends FeatureTest
{
    public function test_branch_is_mass_assignable_and_nullable(): void
    {
        $request = AuditRequest::factory()->create(['branch' => 'release/2.0']);
        $this->assertSame('release/2.0', $request->refresh()->branch);

        $default = AuditRequest::factory()->create();
        $this->assertNull($default->refresh()->branch);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AuditRequestBranchTest`
Expected: FAIL — column does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->string('branch')->nullable()->after('repo_url');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('branch');
        });
    }
};
```

- [ ] **Step 4: Update the model**

Edit `backend/app/Models/AuditRequest.php` — in the `$fillable` array (currently lines 27-33), add `'branch'` right after `'repo_url'`:

```php
    protected $fillable = [
        'name', 'email', 'repo_url', 'branch', 'message', 'status', 'failure_reason', 'meta', 'metrics',
        'email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'funding', 'source', 'tier', 'user_id', 'prepaid',
        'manually_paid', 'admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at', 'scanner_runs',
        'ai_input_tokens', 'ai_output_tokens', 'scanner_ms', 'repo_size_kb',
        'risk_files', 'deep_review_input_tokens', 'deep_review_output_tokens', 'deep_review_ms',
    ];
```

- [ ] **Step 5: Run migrations and the test**

Run: `cd backend && php artisan migrate && php artisan test --filter=AuditRequestBranchTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/migrations/2026_08_29_000002_add_branch_to_audit_requests_table.php app/Models/AuditRequest.php tests/Unit/Models/AuditRequestBranchTest.php
git commit -m "feat(audit): add branch column to audit_requests"
```

---

### Task 3: `audit_schedule_runs` table and `AuditScheduleRun` model

**Files:**
- Create: `backend/database/migrations/2026_08_29_000003_create_audit_schedule_runs_table.php`
- Create: `backend/app/Models/AuditScheduleRun.php`
- Create: `backend/database/factories/AuditScheduleRunFactory.php`
- Modify: `backend/app/Models/AuditSchedule.php`
- Test: `backend/tests/Unit/Models/AuditScheduleRunTest.php`

**Interfaces:**
- Produces: `AuditScheduleRun` model with `$fillable = ['audit_schedule_id', 'scheduled_for', 'status', 'reason', 'audit_request_id', 'commit_sha']`, `scheduled_for` cast to `date`. `AuditSchedule::scheduleRuns(): HasMany`. Task 8 creates rows; Task 12 reads them for the calendar.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class AuditScheduleRunTest extends FeatureTest
{
    public function test_a_run_row_belongs_to_its_schedule_and_optionally_a_request(): void
    {
        $schedule = AuditSchedule::factory()->create();
        $request = AuditRequest::factory()->create();

        $run = AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-09-01',
            'status' => 'completed',
            'reason' => null,
            'audit_request_id' => $request->id,
            'commit_sha' => 'sha123',
        ]);

        $this->assertTrue($run->scheduled_for->equalTo(Carbon::parse('2026-09-01')));
        $this->assertSame($schedule->id, $run->auditSchedule->id);
        $this->assertSame($request->id, $run->auditRequest->id);
        $this->assertCount(1, $schedule->refresh()->scheduleRuns);
    }

    public function test_a_skipped_run_row_has_no_audit_request(): void
    {
        $schedule = AuditSchedule::factory()->create();

        $run = AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-09-08',
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);

        $this->assertNull($run->audit_request_id);
        $this->assertSame('no_changes', $run->reason);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AuditScheduleRunTest`
Expected: FAIL — class `App\Models\AuditScheduleRun` not found.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_for');
            $table->string('status', 20);
            $table->string('reason', 20)->nullable();
            $table->foreignId('audit_request_id')->nullable()->constrained('audit_requests')->nullOnDelete();
            $table->string('commit_sha', 64)->nullable();
            $table->timestamps();

            $table->index(['audit_schedule_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_schedule_runs');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditScheduleRun extends Model
{
    use HasFactory;

    protected $fillable = ['audit_schedule_id', 'scheduled_for', 'status', 'reason', 'audit_request_id', 'commit_sha'];

    protected $casts = ['scheduled_for' => 'date'];

    public function auditSchedule(): BelongsTo
    {
        return $this->belongsTo(AuditSchedule::class);
    }

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\AuditSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditScheduleRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_schedule_id' => AuditSchedule::factory(),
            'scheduled_for' => now()->toDateString(),
            'status' => 'completed',
            'reason' => null,
            'audit_request_id' => null,
            'commit_sha' => fake()->sha1(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `AuditSchedule`**

Edit `backend/app/Models/AuditSchedule.php`, after the `tenant()` method:

```php
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<AuditScheduleRun, $this> */
    public function scheduleRuns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AuditScheduleRun::class);
    }
```

(Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports and use the bare `HasMany` type instead of the FQCN, matching the existing `BelongsTo` import style in this file.)

- [ ] **Step 7: Run migrations and the test**

Run: `cd backend && php artisan migrate && php artisan test --filter=AuditScheduleRunTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
cd backend && git add database/migrations/2026_08_29_000003_create_audit_schedule_runs_table.php app/Models/AuditScheduleRun.php app/Models/AuditSchedule.php database/factories/AuditScheduleRunFactory.php tests/Unit/Models/AuditScheduleRunTest.php
git commit -m "feat(audit): add audit_schedule_runs table for calendar history"
```

---

### Task 4: `GitHubApiClient` — list branches from the GitHub REST API

**Files:**
- Create: `backend/app/Services/GitHub/GitHubApiClient.php`
- Test: `backend/tests/Feature/Services/GitHub/GitHubApiClientTest.php`

**Interfaces:**
- Consumes: `config('audit.github_token')` (already exists, `config/audit.php:14`).
- Produces: `GitHubApiClient::listBranches(string $repoUrl): array` — `list<string>` of branch names, or `[]` on any failure. Consumed by Task 11 (`AuditReports::loadBranches()`).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Services\GitHub;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitHubApiClientTest extends FeatureTest
{
    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        config(['audit.github_token' => 'ghp_test']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test'));
    }

    public function test_returns_branch_names_when_the_url_has_a_dot_git_suffix(): void
    {
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app.git');

        $this->assertSame(['main'], $branches);
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 404)]);

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://github.com/acme/private'));
    }

    public function test_returns_empty_array_for_a_non_github_url(): void
    {
        Http::fake();

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://gitlab.com/acme/app'));
        Http::assertNothingSent();
    }

    public function test_returns_empty_array_on_network_failure(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://github.com/acme/app'));
    }

    public function test_works_without_a_configured_token(): void
    {
        config(['audit.github_token' => null]);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app');

        $this->assertSame(['main'], $branches);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=GitHubApiClientTest`
Expected: FAIL — class `App\Services\GitHub\GitHubApiClient` not found.

- [ ] **Step 3: Implement `GitHubApiClient`**

```php
<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubApiClient
{
    /** @return list<string> */
    public function listBranches(string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        try {
            $request = Http::timeout(10)->connectTimeout(5);

            $token = (string) config('audit.github_token');
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
                ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/branches", ['per_page' => 100])
                ->throw();

            return collect($response->json())->pluck('name')->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{owner:string,name:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#github\.com[:/]([^/]+)/([^/.]+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['owner' => $matches[1], 'name' => $matches[2]];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=GitHubApiClientTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/GitHub/GitHubApiClient.php tests/Feature/Services/GitHub/GitHubApiClientTest.php
git commit -m "feat(audit): add GitHubApiClient for branch listing"
```

---

### Task 5: `RepositoryCloner` — branch targeting and `remoteHeadSha()`

**Files:**
- Modify: `backend/app/Services/AuditReport/RepositoryCloner.php`
- Modify: `backend/tests/Feature/Services/RepositoryClonerTest.php`

**Interfaces:**
- Produces: `RepositoryCloner::clone(string $url, string $uuid, ?string $branch = null): string` (was 2-arg); `RepositoryCloner::remoteHeadSha(string $url, ?string $branch = null): ?string` (new). Task 6 passes `$auditRequest->branch` into `clone()`; Task 7 (`ScheduledAuditChangeChecker`) consumes `remoteHeadSha()`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Services/RepositoryClonerTest.php` (add a second fixture repo with two branches, and new test methods). First add the property and its lazy setup, then the tests:

```php
    private string $branchFixtureRepo;

    private function branchFixtureRepo(): string
    {
        if (isset($this->branchFixtureRepo)) {
            return $this->branchFixtureRepo;
        }

        $this->branchFixtureRepo = storage_path('framework/testing/fixture-repo-branches');

        if (! File::isDirectory($this->branchFixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->branchFixtureRepo);
            File::put($this->branchFixtureRepo.'/README.md', "# Fixture\n");
            Process::path($this->branchFixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
            Process::path($this->branchFixtureRepo)->run('git checkout -qb feature-branch')->throw();
            File::put($this->branchFixtureRepo.'/FEATURE.md', "# Feature\n");
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm feature')->throw();
            Process::path($this->branchFixtureRepo)->run('git checkout -q main')->throw();
        }

        return $this->branchFixtureRepo;
    }

    public function test_clone_with_no_branch_uses_the_default_branch(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-default-'.uniqid();

        $path = $cloner->clone('file://'.$this->branchFixtureRepo(), $uuid);

        $this->assertFileExists($path.'/README.md');
        $this->assertFileDoesNotExist($path.'/FEATURE.md');
        $cloner->cleanup($uuid);
    }

    public function test_clone_with_a_branch_checks_out_that_branch(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-branch-'.uniqid();

        $path = $cloner->clone('file://'.$this->branchFixtureRepo(), $uuid, 'feature-branch');

        $this->assertFileExists($path.'/FEATURE.md');
        $cloner->cleanup($uuid);
    }

    public function test_clone_with_a_nonexistent_branch_throws(): void
    {
        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->clone('file://'.$this->branchFixtureRepo(), 'test-clone-missing-'.uniqid(), 'does-not-exist');
    }

    public function test_remote_head_sha_returns_the_resolved_sha(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\tHEAD\n")]);

        $this->assertSame('abc123', app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app'));
    }

    public function test_remote_head_sha_returns_null_on_failure(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->assertNull(app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app'));
    }

    public function test_remote_head_sha_targets_the_given_branch_ref(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\trefs/heads/develop\n")]);

        app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app', 'develop');

        Process::assertRan(fn (\Illuminate\Process\PendingProcess $process) => in_array('refs/heads/develop', $process->command, true));
    }
```

Also add `use Illuminate\Support\Facades\Process;` alongside the existing `File` import if not already present (it already is, per the current file).

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=RepositoryClonerTest`
Expected: FAIL — `clone()` does not accept a third argument, `remoteHeadSha()` does not exist.

- [ ] **Step 3: Implement the changes**

Edit `backend/app/Services/AuditReport/RepositoryCloner.php`. Replace `clone()` (current lines 24-49):

```php
    public function clone(string $url, string $uuid, ?string $branch = null): string
    {
        $path = $this->workdirPath($uuid);
        File::ensureDirectoryExists(dirname($path));

        $command = ['git', 'clone', '--depth', (string) config('audit.clone_depth'), '--no-tags', '--single-branch'];
        if ($branch !== null) {
            $command[] = '--branch';
            $command[] = $branch;
        }
        $command[] = $this->authenticatedUrl($url);
        $command[] = $path;

        $result = Process::timeout(config('audit.clone_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run($command);

        if (! $result->successful()) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException('Repository could not be cloned: '.$this->redactUrl($url));
        }

        $sizeMb = $this->directorySizeMb($path);
        if ($sizeMb > config('audit.max_repo_size_mb')) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException(
                sprintf('Repository too large for automated analysis (%d MB)', $sizeMb)
            );
        }

        return $path;
    }

    /**
     * The current SHA of a remote ref, without cloning. `null` on any
     * failure (unreachable host, private repo, network error) -- callers
     * (ScheduledAuditChangeChecker) must treat that as "unknown," never as
     * "unchanged."
     */
    public function remoteHeadSha(string $url, ?string $branch = null): ?string
    {
        $ref = $branch !== null ? 'refs/heads/'.$branch : 'HEAD';

        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', $this->authenticatedUrl($url), $ref]);

        if (! $result->successful()) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($result->output()))[0] ?? '');
        $sha = strtok($firstLine, "\t ");

        return $sha !== false && $sha !== '' ? $sha : null;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=RepositoryClonerTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/AuditReport/RepositoryCloner.php tests/Feature/Services/RepositoryClonerTest.php
git commit -m "feat(audit): support branch targeting and remote SHA lookup in RepositoryCloner"
```

---

### Task 6: `AuditPipeline` threads `AuditRequest::$branch` into the clone

**Files:**
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php:79`
- Modify: `backend/tests/Feature/Services/AuditPipelineTest.php`

**Interfaces:**
- Consumes: `RepositoryCloner::clone(string, string, ?string)` (Task 5), `AuditRequest::$branch` (Task 2).

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Services/AuditPipelineTest.php` (needs `use App\Services\AuditReport\RepositoryCloner;` added to the imports):

```php
    public function test_the_requests_branch_is_passed_through_to_the_cloner(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'branch' => 'main',
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        $this->partialMock(RepositoryCloner::class, function ($mock) use ($request) {
            $mock->shouldReceive('clone')
                ->once()
                ->withArgs(fn (string $url, string $uuid, ?string $branch) => $url === $request->repo_url
                    && $uuid === $request->uuid
                    && $branch === 'main')
                ->passthru();
        });

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $this->assertSame(AuditRequestStatus::SENT->value, $request->refresh()->status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AuditPipelineTest::test_the_requests_branch_is_passed_through_to_the_cloner`
Expected: FAIL — the mock's `withArgs` check fails because `clone()` is called with only 2 arguments (branch is never passed).

- [ ] **Step 3: Implement the change**

Edit `backend/app/Services/AuditReport/AuditPipeline.php` line 79:

```php
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid, $auditRequest->branch);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=AuditPipelineTest`
Expected: PASS (full class, to confirm no regression to the other pipeline tests)

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/AuditReport/AuditPipeline.php tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(audit): thread AuditRequest branch through the clone step"
```

---

### Task 7: `ScheduledAuditChangeChecker`

**Files:**
- Create: `backend/app/Services/AuditReport/ChangeCheckResult.php`
- Create: `backend/app/Services/AuditReport/ScheduledAuditChangeChecker.php`
- Test: `backend/tests/Feature/Services/ScheduledAuditChangeCheckerTest.php`

**Interfaces:**
- Consumes: `RepositoryCloner::remoteHeadSha()` (Task 5).
- Produces: `ScheduledAuditChangeChecker::check(AuditSchedule $schedule): ChangeCheckResult`, where `ChangeCheckResult` has `public bool $shouldRun` and `public ?string $sha`. Consumed by Task 8.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditSchedule;
use App\Services\AuditReport\ScheduledAuditChangeChecker;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class ScheduledAuditChangeCheckerTest extends FeatureTest
{
    public function test_same_sha_as_last_run_means_no_run_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha123\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertFalse($result->shouldRun);
        $this->assertSame('sha123', $result->sha);
    }

    public function test_different_sha_means_a_run_is_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_no_prior_sha_always_runs(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => null]);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_ls_remote_failure_fails_open(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertNull($result->sha);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ScheduledAuditChangeCheckerTest`
Expected: FAIL — class `App\Services\AuditReport\ScheduledAuditChangeChecker` not found.

- [ ] **Step 3: Implement `ChangeCheckResult`**

```php
<?php

namespace App\Services\AuditReport;

final readonly class ChangeCheckResult
{
    public function __construct(
        public bool $shouldRun,
        public ?string $sha,
    ) {}
}
```

- [ ] **Step 4: Implement `ScheduledAuditChangeChecker`**

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditSchedule;

class ScheduledAuditChangeChecker
{
    public function __construct(private RepositoryCloner $cloner) {}

    public function check(AuditSchedule $schedule): ChangeCheckResult
    {
        $sha = $this->cloner->remoteHeadSha($schedule->repo_url, $schedule->branch);

        // Fail open: an unreadable SHA (network error, transient outage) is
        // indistinguishable here from "definitely changed" -- both must let
        // the run proceed (spec: change check fails open).
        if ($sha === null) {
            return new ChangeCheckResult(shouldRun: true, sha: null);
        }

        if ($schedule->last_commit_sha !== null && $schedule->last_commit_sha === $sha) {
            return new ChangeCheckResult(shouldRun: false, sha: $sha);
        }

        return new ChangeCheckResult(shouldRun: true, sha: $sha);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ScheduledAuditChangeCheckerTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Services/AuditReport/ChangeCheckResult.php app/Services/AuditReport/ScheduledAuditChangeChecker.php tests/Feature/Services/ScheduledAuditChangeCheckerTest.php
git commit -m "feat(audit): add ScheduledAuditChangeChecker for skip-if-unchanged runs"
```

---

### Task 8: `RunScheduledAudits` — day-of-week due-check, change-check integration, `audit_schedule_runs` history

**Files:**
- Modify: `backend/app/Console/Commands/RunScheduledAudits.php`
- Modify: `backend/tests/Feature/Console/RunScheduledAuditsTest.php`

**Interfaces:**
- Consumes: `ScheduledAuditChangeChecker::check()` (Task 7), `AuditScheduleRun` (Task 3), `AuditSchedule::$day_of_week` (Task 1).

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/Console/RunScheduledAuditsTest.php` (needs `use App\Models\AuditScheduleRun;`, `use Illuminate\Support\Facades\Process;`, and `use Illuminate\Support\Carbon;` added to imports):

```php
    public function test_skips_when_the_repo_is_unchanged_since_the_last_run(): void
    {
        Process::fake(['*' => Process::result(output: "sha-same\tHEAD\n")]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-same',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
        $this->assertNotNull($schedule->refresh()->last_run_at); // unchanged, still the old value
        $this->assertDatabaseHas('audit_schedule_runs', [
            'audit_schedule_id' => $schedule->id,
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);
    }

    public function test_runs_and_records_the_new_sha_when_the_repo_changed(): void
    {
        Process::fake(['*' => Process::result(output: "sha-new\tHEAD\n")]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-old',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertSame('sha-new', $schedule->refresh()->last_commit_sha);
        $this->assertDatabaseHas('audit_schedule_runs', [
            'audit_schedule_id' => $schedule->id,
            'status' => 'completed',
            'audit_request_id' => $request->id,
            'commit_sha' => 'sha-new',
        ]);
    }

    public function test_a_failed_change_check_fails_open_and_runs_anyway(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-old',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_weekly_schedule_with_day_of_week_only_runs_on_that_weekday(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]); // irrelevant here; fails open either way
        Queue::fake();
        $today = Carbon::now();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/today',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_week' => $today->dayOfWeek,
            'last_run_at' => now()->subWeek(),
        ]);
        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/tomorrow',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_week' => ($today->dayOfWeek + 1) % 7,
            'last_run_at' => now()->subWeek(),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(1, AuditRequest::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_requests', ['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/today']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=RunScheduledAuditsTest`
Expected: The 5 new tests FAIL (no change-check integration, no day-of-week filtering, no `audit_schedule_runs` rows yet); the 4 existing tests should still PASS unmodified at this point.

- [ ] **Step 3: Implement the changes**

Replace the full contents of `backend/app/Console/Commands/RunScheduledAudits.php`:

```php
<?php

namespace App\Console\Commands;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\ScheduledAuditChangeChecker;
use Illuminate\Console\Command;

class RunScheduledAudits extends Command
{
    protected $signature = 'app:run-scheduled-audits';

    protected $description = 'Dispatch dashboard audits for schedules that are due';

    public function handle(AuditEntitlementService $entitlements, ScheduledAuditChangeChecker $changeChecker): int
    {
        $due = AuditSchedule::query()->with(['user', 'tenant'])->get()->filter($this->isDue(...));

        $started = 0;

        foreach ($due as $schedule) {
            $tier = $schedule->tier;
            $quota = $entitlements->quotaFor($schedule->user, $schedule->tenant, $tier);

            if (! $quota->hasRuns()) {
                // Never downgrade to a cheaper tier and never auto-charge:
                // both deliver something the customer did not agree to at
                // schedule time. Logged, because a schedule that quietly
                // stops firing is otherwise invisible.
                $this->warn("Skipping {$schedule->repo_url}: no {$tier->value} runs left for {$schedule->user->email}");
                $this->recordRun($schedule, 'skipped', 'no_quota');

                continue;
            }

            $check = $changeChecker->check($schedule);

            if (! $check->shouldRun) {
                $this->info("Skipping {$schedule->repo_url}: no changes since the last audit");
                $this->recordRun($schedule, 'skipped', 'no_changes', commitSha: $check->sha);

                continue;
            }

            $auditRequest = AuditRequest::create([
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'repo_url' => $schedule->repo_url,
                'branch' => $schedule->branch,
                'status' => AuditRequestStatus::QUEUED->value,
                'email_verified_at' => now(),
                'source' => 'dashboard',
                'tier' => $tier->value,
                'funding' => $quota->isLifetime
                    ? AuditFunding::FREE->value
                    : AuditFunding::ALLOWANCE->value,
                'user_id' => $schedule->user->id,
            ]);

            // An allowance run is metered simply by existing at its tier. A
            // free run has to be flagged on the request to be deducted --
            // setSchedule() already refuses to create a lifetime-tier
            // schedule, but a pre-existing schedule row (created before that
            // guard existed) could still reach here at a lifetime tier.
            if ($quota->isLifetime) {
                $entitlements->consumeFreeRun($auditRequest);
            }

            GenerateAuditReport::dispatch($auditRequest);
            $schedule->update(['last_run_at' => now(), 'last_commit_sha' => $check->sha]);
            $this->recordRun($schedule, 'completed', auditRequestId: $auditRequest->id, commitSha: $check->sha);
            $started++;
        }

        $this->info("Started {$started} scheduled audits.");

        return self::SUCCESS;
    }

    /**
     * Weekly schedules with a chosen day_of_week are due only on that
     * weekday. A weekly schedule created before day_of_week existed (still
     * null) falls back to the original last_run_at + 1 week check, so no
     * pre-existing row silently stops firing.
     */
    private function isDue(AuditSchedule $schedule): bool
    {
        if ($schedule->last_run_at === null) {
            return true;
        }

        if ($schedule->frequency === 'weekly' && $schedule->day_of_week !== null) {
            return now()->dayOfWeek === $schedule->day_of_week && $schedule->last_run_at->isBefore(now()->startOfDay());
        }

        return $schedule->last_run_at <= ($schedule->frequency === 'weekly' ? now()->subWeek() : now()->subMonth());
    }

    private function recordRun(
        AuditSchedule $schedule,
        string $status,
        ?string $reason = null,
        ?int $auditRequestId = null,
        ?string $commitSha = null,
    ): void {
        AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => now()->toDateString(),
            'status' => $status,
            'reason' => $reason,
            'audit_request_id' => $auditRequestId,
            'commit_sha' => $commitSha,
        ]);
    }
}
```

- [ ] **Step 4: Update the two existing tests that now reach the change-check**

`test_a_schedule_runs_at_its_own_tier` and `test_a_diagnostic_schedule_debits_the_free_quota_not_the_allowance` both have quota, so execution now reaches `ScheduledAuditChangeChecker`, which shells out to `git ls-remote`. Add `Process::fake(['*' => Process::result(exitCode: 1)]);` (simulating a failed check, which fails open exactly like today's unconditional run) as the first line of each test body, and add `use Illuminate\Support\Facades\Process;` to the file's imports. `test_an_exhausted_tier_is_skipped_not_downgraded` and `test_not_yet_due_schedule_is_skipped` are unaffected — both short-circuit before the change-check runs (no quota / not due) — leave them as-is.

- [ ] **Step 5: Run the full test file to verify everything passes**

Run: `cd backend && php artisan test --filter=RunScheduledAuditsTest`
Expected: PASS (all 9 tests: the original 4, updated where needed, plus the 5 new ones)

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Console/Commands/RunScheduledAudits.php tests/Feature/Console/RunScheduledAuditsTest.php
git commit -m "feat(audit): skip scheduled runs when the repo is unchanged, add day-of-week due-check"
```

---

### Task 9: `ScoreChartBuilder` — data for the enhanced score-history chart

**Files:**
- Create: `backend/app/Services/AuditReport/ScoreChartPoint.php`
- Create: `backend/app/Services/AuditReport/ScoreChartBuilder.php`
- Test: `backend/tests/Unit/Services/ScoreChartBuilderTest.php`

**Interfaces:**
- Produces: `ScoreChartBuilder::build(Collection $scores, Collection $dates): list<ScoreChartPoint>`, where `$scores` is `Collection<int,int>` oldest-to-newest and `$dates` is a same-length/order `Collection<int,Carbon>`. `ScoreChartPoint` has `float $x`, `float $y`, `int $score`, `?int $delta`, `string $colorClass`, `string $tooltip`. Consumed by Task 12's Blade partial.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Services;

use App\Services\AuditReport\ScoreChartBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ScoreChartBuilderTest extends TestCase
{
    public function test_a_single_score_produces_no_points(): void
    {
        $points = (new ScoreChartBuilder)->build(collect([60]), collect([Carbon::parse('2026-08-01')]));

        $this->assertSame([], $points);
    }

    public function test_a_rising_score_gets_the_positive_color_and_a_directional_tooltip(): void
    {
        $scores = collect([60, 75]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertCount(2, $points);
        $this->assertNull($points[0]->delta);
        $this->assertSame(15, $points[1]->delta);
        $this->assertSame('text-emerald-500', $points[1]->colorClass);
        $this->assertSame('60 → 75 (+15) on Aug 20, 2026', $points[1]->tooltip);
    }

    public function test_a_falling_score_gets_the_negative_color(): void
    {
        $scores = collect([80, 70]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(-10, $points[1]->delta);
        $this->assertSame('text-rose-500', $points[1]->colorClass);
    }

    public function test_an_unchanged_score_gets_the_neutral_color(): void
    {
        $scores = collect([70, 70]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(0, $points[1]->delta);
        $this->assertSame('text-gray-400', $points[1]->colorClass);
    }

    public function test_points_span_the_full_200_unit_width(): void
    {
        $scores = collect([50, 60, 70, 80]);
        $dates = collect(array_fill(0, 4, Carbon::parse('2026-08-01')));

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(0.0, $points[0]->x);
        $this->assertSame(200.0, $points[3]->x);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ScoreChartBuilderTest`
Expected: FAIL — class `App\Services\AuditReport\ScoreChartBuilder` not found.

- [ ] **Step 3: Implement `ScoreChartPoint`**

```php
<?php

namespace App\Services\AuditReport;

final readonly class ScoreChartPoint
{
    public function __construct(
        public float $x,
        public float $y,
        public int $score,
        public ?int $delta,
        public string $colorClass,
        public string $tooltip,
    ) {}
}
```

- [ ] **Step 4: Implement `ScoreChartBuilder`**

```php
<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Collection;

class ScoreChartBuilder
{
    /**
     * @param  Collection<int, int>  $scores  oldest-to-newest
     * @param  Collection<int, \Illuminate\Support\Carbon>  $dates  same order/length as $scores
     * @return list<ScoreChartPoint>
     */
    public function build(Collection $scores, Collection $dates): array
    {
        if ($scores->count() < 2) {
            return [];
        }

        $max = max(1, $scores->max());
        $step = 200 / max(1, $scores->count() - 1);
        $points = [];
        $previous = null;

        foreach ($scores->values() as $i => $score) {
            $delta = $previous !== null ? $score - $previous : null;
            $colorClass = $delta === null || $delta === 0
                ? 'text-gray-400'
                : ($delta > 0 ? 'text-emerald-500' : 'text-rose-500');

            $date = $dates->get($i);
            $tooltip = $previous !== null
                ? sprintf('%d → %d (%+d) on %s', $previous, $score, $delta, $date?->format('M j, Y'))
                : sprintf('%d on %s', $score, $date?->format('M j, Y'));

            $points[] = new ScoreChartPoint(
                x: round($i * $step, 2),
                y: round(34 - ($score / $max) * 30, 2),
                score: $score,
                delta: $delta,
                colorClass: $colorClass,
                tooltip: $tooltip,
            );

            $previous = $score;
        }

        return $points;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ScoreChartBuilderTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Services/AuditReport/ScoreChartPoint.php app/Services/AuditReport/ScoreChartBuilder.php tests/Unit/Services/ScoreChartBuilderTest.php
git commit -m "feat(audit): add ScoreChartBuilder for the enhanced score-history chart"
```

---

### Task 10: `ScheduleOccurrenceProjector` — upcoming calendar dates

**Files:**
- Create: `backend/app/Services/AuditReport/ScheduleOccurrenceProjector.php`
- Test: `backend/tests/Feature/Services/ScheduleOccurrenceProjectorTest.php`

**Interfaces:**
- Produces: `ScheduleOccurrenceProjector::upcomingDatesInMonth(AuditSchedule $schedule, Carbon $monthStart): list<Carbon>`. Consumed by Task 12's calendar data in `getViewData()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditSchedule;
use App\Services\AuditReport\ScheduleOccurrenceProjector;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class ScheduleOccurrenceProjectorTest extends FeatureTest
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_schedule_projects_every_matching_weekday_from_today_onward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10')); // exact date irrelevant; only its weekday and month matter
        $today = Carbon::now();
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => $today->dayOfWeek]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, $today->copy()->startOfMonth());

        $expected = [];
        $cursor = $today->copy();
        while ($cursor->month === $today->month) {
            $expected[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        $this->assertSame($expected, array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_weekly_schedule_without_day_of_week_projects_nothing(): void
    {
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => null]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::now()->startOfMonth());

        $this->assertSame([], $dates);
    }

    public function test_monthly_schedule_projects_the_anchor_day_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));
        $schedule = AuditSchedule::factory()->make([
            'frequency' => 'monthly',
            'last_run_at' => Carbon::parse('2026-07-15'),
        ]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-08-01'));

        $this->assertSame(['2026-08-15'], array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_monthly_schedule_clamps_the_anchor_day_to_a_shorter_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-01'));
        $schedule = AuditSchedule::factory()->make([
            'frequency' => 'monthly',
            'last_run_at' => Carbon::parse('2026-01-31'),
        ]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-02-01'));

        $this->assertSame(['2026-02-28'], array_map(fn ($d) => $d->toDateString(), $dates)); // 2026 is not a leap year
    }

    public function test_a_past_month_projects_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        $today = Carbon::now();
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => $today->dayOfWeek]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-07-01'));

        $this->assertSame([], $dates);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ScheduleOccurrenceProjectorTest`
Expected: FAIL — class `App\Services\AuditReport\ScheduleOccurrenceProjector` not found.

- [ ] **Step 3: Implement `ScheduleOccurrenceProjector`**

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditSchedule;
use Illuminate\Support\Carbon;

/**
 * Projects the schedule's likely future occurrence dates within a given
 * calendar month, purely for display. This mirrors -- but does not
 * replace -- RunScheduledAudits::isDue()'s authoritative due-check.
 */
class ScheduleOccurrenceProjector
{
    /** @return list<Carbon> */
    public function upcomingDatesInMonth(AuditSchedule $schedule, Carbon $monthStart): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $today = Carbon::today();

        if ($schedule->frequency === 'weekly') {
            if ($schedule->day_of_week === null) {
                return [];
            }

            $dates = [];
            $cursor = $monthStart->copy();

            while ($cursor->lte($monthEnd)) {
                if ($cursor->dayOfWeek === $schedule->day_of_week && $cursor->gte($today)) {
                    $dates[] = $cursor->copy();
                }
                $cursor->addDay();
            }

            return $dates;
        }

        $anchorDay = ($schedule->last_run_at ?? $schedule->created_at ?? $today)->day;
        $day = min($anchorDay, $monthEnd->day);
        $date = $monthStart->copy()->addDays($day - 1);

        return ($date->gte($today) && $date->lte($monthEnd)) ? [$date] : [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ScheduleOccurrenceProjectorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/AuditReport/ScheduleOccurrenceProjector.php tests/Feature/Services/ScheduleOccurrenceProjectorTest.php
git commit -m "feat(audit): add ScheduleOccurrenceProjector for calendar upcoming dates"
```

---

### Task 11: `AuditReports` — branch selection and per-schedule day/branch controls

**Files:**
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Modify: `backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php`

**Interfaces:**
- Consumes: `GitHubApiClient::listBranches()` (Task 4).
- Produces: `AuditReports::$branch`, `$branchesByRepo`; `launchAudit(?string $repoUrl, ?string $tier, ?string $branch)`; `setScheduleDay(string $repoUrl, int $dayOfWeek)`; `setScheduleBranch(string $repoUrl, ?string $branch)`; `loadBranches(string $repoUrl)`; `updatedRepoUrl()`. `setSchedule()` now preserves/defaults `day_of_week` on create. Task 12 builds on the same class/view for the chart and calendar.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php` (needs `use App\Services\GitHub\GitHubApiClient;` and `use Illuminate\Support\Facades\Http;` added to imports):

```php
    public function test_launch_audit_persists_the_chosen_branch(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app', null, 'release/2.0');

        $this->assertSame('release/2.0', AuditRequest::where('user_id', $user->id)->firstOrFail()->branch);
    }

    public function test_launch_audit_leaves_branch_null_when_not_supplied(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $this->assertNull(AuditRequest::where('user_id', $user->id)->firstOrFail()->branch);
    }

    public function test_a_new_weekly_schedule_defaults_day_of_week_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app', 'weekly');

        $this->assertSame(
            Carbon::now()->dayOfWeek,
            \App\Models\AuditSchedule::where('user_id', $user->id)->firstOrFail()->day_of_week,
        );

        Carbon::setTestNow();
    }

    public function test_set_schedule_day_updates_an_existing_weekly_schedule(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = \App\Models\AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly', 'tier' => AuditTier::DIAGNOSTIC->value, 'day_of_week' => 1,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleDay', 'https://github.com/acme/app', 4);

        $this->assertSame(4, $schedule->refresh()->day_of_week);
    }

    public function test_set_schedule_branch_updates_an_existing_schedule(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = \App\Models\AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly', 'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleBranch', 'https://github.com/acme/app', 'develop');

        $this->assertSame('develop', $schedule->refresh()->branch);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleBranch', 'https://github.com/acme/app', '');

        $this->assertNull($schedule->refresh()->branch);
    }

    public function test_load_branches_populates_branches_by_repo_from_the_github_client(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->mock(GitHubApiClient::class, function ($mock) {
            $mock->shouldReceive('listBranches')->once()->with('https://github.com/acme/app')->andReturn(['main', 'develop']);
        });

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('loadBranches', 'https://github.com/acme/app')
            ->assertSet('branchesByRepo', ['https://github.com/acme/app' => ['main', 'develop']]);
    }
```

Also add `use Illuminate\Support\Carbon;` to the file's imports (the class already imports `App\Constants\AuditTier` and `App\Models\AuditRequest`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=AuditReportsPageTest`
Expected: FAIL — `launchAudit()` has no third parameter, `setScheduleDay`/`setScheduleBranch`/`loadBranches` don't exist, `day_of_week` is never set by `setSchedule()`.

- [ ] **Step 3: Implement the PHP changes**

Edit `backend/app/Filament/Dashboard/Pages/AuditReports.php`. Add imports:

```php
use App\Services\GitHub\GitHubApiClient;
```

Add public properties after `public string $tier = '';` (line 32):

```php
    public ?string $branch = null;

    /** @var array<string, list<string>> */
    public array $branchesByRepo = [];
```

Add the Livewire lifecycle hook and `loadBranches()` after `mount()` (after line 37):

```php
    public function updatedRepoUrl(): void
    {
        if ($this->repoUrl !== null && str_starts_with($this->repoUrl, 'http')) {
            $this->loadBranches($this->repoUrl);
        }
    }

    public function loadBranches(string $repoUrl): void
    {
        $key = rtrim($repoUrl, '/');

        if (array_key_exists($key, $this->branchesByRepo)) {
            return;
        }

        $this->branchesByRepo[$key] = app(GitHubApiClient::class)->listBranches($repoUrl);
    }
```

Change `launchAudit()`'s signature (line 83) and how the request is built (lines 83-152):

```php
    public function launchAudit(?string $repoUrl = null, ?string $tier = null, ?string $branch = null): void
    {
        $repoUrl ??= $this->repoUrl;
        $branch ??= $this->branch;
        $branch = ($branch !== null && $branch !== '') ? $branch : null;
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        // $tier arrives from a client-controlled Livewire property, so the
        // rendered UI is a hint and this method is the gate.
        $selected = AuditTier::tryFrom($tier ?? $this->tier);

        if ($selected === null) {
            Notification::make()->title(__('Choose an audit type'))->danger()->send();

            return;
        }

        if ($repoUrl === null || ! str_starts_with($repoUrl, 'http')) {
            Notification::make()->title(__('Enter a valid repository URL'))->danger()->send();

            return;
        }

        $quota = $entitlements->quotaFor($user, $tenant, $selected);

        if (! $quota->hasRuns()) {
            if ($quota->purchasable()) {
                $this->purchase($repoUrl, $selected, $branch);

                return;
            }

            Notification::make()
                ->title(__('No :tier runs left', ['tier' => $quota->label]))
                ->body(__('Upgrade your plan to run more audits.'))
                ->warning()
                ->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'branch' => $branch,
            'status' => AuditRequestStatus::QUEUED->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $selected->value,
            'funding' => $quota->isLifetime
                ? AuditFunding::FREE->value
                : AuditFunding::ALLOWANCE->value,
            'user_id' => $user->id,
        ]);

        // An allowance run is metered simply by existing at its tier. A free
        // run has to be flagged on the request to be deducted.
        if ($quota->isLifetime) {
            $entitlements->consumeFreeRun($auditRequest);
        }

        GenerateAuditReport::dispatch($auditRequest);
        $this->repoUrl = null;
        $this->branch = null;

        Notification::make()
            ->title(__('Audit started'))
            ->body(__('You\'ll get an email when the report is ready.'))
            ->success()
            ->send();
    }
```

Update `purchase()`'s signature and payload (lines 162-192):

```php
    private function purchase(string $repoUrl, AuditTier $tier, ?string $branch = null): void
    {
        $user = auth()->user();
        $slug = collect((array) config('pricing.tiers'))
            ->search(fn (array $definition): bool => ($definition['tier'] ?? null) === $tier->value);

        if ($slug === false) {
            Notification::make()->title(__('No :tier runs left', ['tier' => $tier->label()]))->danger()->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'branch' => $branch,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $tier->value,
            'funding' => AuditFunding::PURCHASE->value,
            'user_id' => $user->id,
        ]);

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        $this->redirect(route('buy.product', ['productSlug' => $slug]));
    }
```

Update `setSchedule()`'s persistence (lines 229-232) to preserve/default `day_of_week`:

```php
        $existing = AuditSchedule::query()->where('user_id', $user->id)->where('repo_url', $repoUrl)->first();

        AuditSchedule::updateOrCreate(
            ['user_id' => $user->id, 'repo_url' => $repoUrl],
            [
                'tenant_id' => $tenant->id,
                'frequency' => $frequency,
                'tier' => $selected->value,
                'day_of_week' => $frequency === 'weekly' ? ($existing->day_of_week ?? now()->dayOfWeek) : null,
            ],
        );
```

Add `setScheduleDay()` and `setScheduleBranch()` after `setSchedule()` (after line 235):

```php
    public function setScheduleDay(string $repoUrl, int $dayOfWeek): void
    {
        $user = auth()->user();
        $repoUrl = rtrim($repoUrl, '/');

        AuditSchedule::query()
            ->where('user_id', $user->id)
            ->where('repo_url', $repoUrl)
            ->where('frequency', 'weekly')
            ->update(['day_of_week' => max(0, min(6, $dayOfWeek))]);
    }

    public function setScheduleBranch(string $repoUrl, ?string $branch): void
    {
        $user = auth()->user();
        $repoUrl = rtrim($repoUrl, '/');

        AuditSchedule::query()
            ->where('user_id', $user->id)
            ->where('repo_url', $repoUrl)
            ->update(['branch' => ($branch !== null && $branch !== '') ? $branch : null]);
    }
```

- [ ] **Step 4: Add the branch selector to the launch-audit form**

Edit `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`. Change the repo-URL input (line 8) to trigger on blur, and add a branch field after the closing `</div>` of that URL block (after line 13):

```blade
                <div class="grow">
                    <label class="text-sm font-medium" for="audit-repo-url">{{ __('Repository URL') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="audit-repo-url" type="url" wire:model.live.blur="repoUrl" placeholder="https://github.com/you/repo" aria-describedby="audit-private-repo" />
                    </x-filament::input.wrapper>
                    <p id="audit-private-repo" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Private repository? Invite :account on GitHub as a read-only collaborator (Settings → Collaborators → Add people), then paste the URL here. We start the audit as soon as the invite lands.', ['account' => config('audit.github_account')]) }}
                    </p>
                </div>

                @php($launchBranches = $repoUrl ? ($branchesByRepo[rtrim($repoUrl, '/')] ?? null) : null)
                @if ($launchBranches !== null)
                    <div>
                        <label class="text-sm font-medium" for="audit-branch">{{ __('Branch') }}</label>
                        @if ($launchBranches !== [])
                            <select id="audit-branch" class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" wire:model="branch">
                                <option value="">{{ __('Repo default branch') }}</option>
                                @foreach ($launchBranches as $b)
                                    <option value="{{ $b }}">{{ $b }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-filament::input.wrapper>
                                <x-filament::input id="audit-branch" type="text" wire:model="branch" placeholder="{{ __('branch name (optional)') }}" />
                            </x-filament::input.wrapper>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('We couldn\'t look up branches for this repo — you can still type one, or leave blank for the default branch.') }}
                            </p>
                        @endif
                    </div>
                @endif
```

- [ ] **Step 5: Add day-of-week and branch controls to each scheduled repo row**

Edit the schedule block (currently lines 111-139), inserting the day-of-week select right after the frequency `<select>`'s closing tag (after line 120) and a branch select after the tier `<select>`'s `@endif` (after line 134):

```blade
                    @if ($scheduleFrequency === 'weekly')
                        <select
                            class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            wire:change="setScheduleDay('{{ $repoUrl }}', $event.target.value)"
                            aria-label="{{ __('Day of week for :repo', ['repo' => $repoUrl]) }}"
                        >
                            @foreach ([0 => __('Sun'), 1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat')] as $value => $dayLabel)
                                <option value="{{ $value }}" @selected(($schedule->day_of_week ?? now()->dayOfWeek) === $value)>{{ $dayLabel }}</option>
                            @endforeach
                        </select>
                    @endif
```

and, after the tier `@endif` block:

```blade
                    <div x-data x-init="$wire.loadBranches('{{ $repoUrl }}')">
                        @php($scheduleBranches = $branchesByRepo[rtrim($repoUrl, '/')] ?? [])
                        <select
                            class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            wire:change="setScheduleBranch('{{ $repoUrl }}', $event.target.value)"
                            aria-label="{{ __('Branch to schedule for :repo', ['repo' => $repoUrl]) }}"
                        >
                            <option value="">{{ __('Repo default branch') }}</option>
                            @foreach ($scheduleBranches as $b)
                                <option value="{{ $b }}" @selected($schedule?->branch === $b)>{{ $b }}</option>
                            @endforeach
                        </select>
                    </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=AuditReportsPageTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Filament/Dashboard/Pages/AuditReports.php resources/views/filament/dashboard/pages/audit-reports.blade.php tests/Feature/Filament/Dashboard/AuditReportsPageTest.php
git commit -m "feat(audit): add GitHub branch selector to launch and schedule forms"
```

---

### Task 12: Score chart rendering, calendar month state and partial

**Files:**
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Create: `backend/resources/views/filament/dashboard/pages/partials/audit-calendar.blade.php`
- Modify: `backend/tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php`

**Interfaces:**
- Consumes: `ScoreChartBuilder` (Task 9), `ScheduleOccurrenceProjector` (Task 10), `AuditScheduleRun` (Task 3).
- Produces: `AuditReports::$calendarMonth`, `prevCalendarMonth()`, `nextCalendarMonth()`; `getViewData()` gains `chartPoints` (per repo group) and `calendarByRepo`/`calendarMonth` keys.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php` (needs `use App\Models\AuditRequest;`, `use App\Models\AuditSchedule;`, `use App\Models\AuditScheduleRun;`, and `use Illuminate\Support\Carbon;` added to imports):

```php
    public function test_the_score_chart_renders_a_colored_point_per_report(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        foreach ([[60, 10], [75, 0]] as [$score, $daysAgo]) {
            $request = AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => 'https://github.com/acme/charted',
                'created_at' => now()->subDays($daysAgo),
            ]);
            AuditReport::factory()->create([
                'audit_request_id' => $request->id,
                'user_id' => $user->id,
                'payload' => ['scores' => ['overall' => $score]],
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('text-emerald-500', false)
            ->assertSee('60 → 75', false);
    }

    public function test_the_calendar_shows_a_completed_and_a_skipped_day_for_a_scheduled_repo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/calendared',
            'frequency' => 'weekly',
            'tier' => AuditTier::DIAGNOSTIC->value,
            'day_of_week' => 1,
        ]);
        AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-08-03',
            'status' => 'completed',
        ]);
        AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-08-04',
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('audit-calendar-day-completed', false)
            ->assertSee('audit-calendar-day-skipped', false);

        Carbon::setTestNow();
    }

    public function test_calendar_month_navigation_moves_forward_and_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertSet('calendarMonth', '2026-08')
            ->call('nextCalendarMonth')
            ->assertSet('calendarMonth', '2026-09')
            ->call('prevCalendarMonth')
            ->call('prevCalendarMonth')
            ->assertSet('calendarMonth', '2026-07');

        Carbon::setTestNow();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=AuditReportsRenderTest`
Expected: FAIL — `calendarMonth`/`prevCalendarMonth`/`nextCalendarMonth` don't exist, chart is still the plain single-color polyline, no calendar markup is rendered.

- [ ] **Step 3: Implement the PHP changes**

Edit `backend/app/Filament/Dashboard/Pages/AuditReports.php`. Add imports:

```php
use App\Models\AuditScheduleRun;
use App\Services\AuditReport\ScheduleOccurrenceProjector;
use App\Services\AuditReport\ScoreChartBuilder;
use Illuminate\Support\Carbon;
```

Add a public property after `$branchesByRepo`:

```php
    public string $calendarMonth = '';
```

Update `mount()` (lines 34-37):

```php
    public function mount(): void
    {
        $this->tier = $this->defaultTier()->value;
        $this->calendarMonth = now()->format('Y-m');
    }
```

Add month-navigation methods after `loadBranches()`:

```php
    public function prevCalendarMonth(): void
    {
        $this->calendarMonth = $this->calendarMonthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextCalendarMonth(): void
    {
        $this->calendarMonth = $this->calendarMonthStart()->addMonthNoOverflow()->format('Y-m');
    }

    private function calendarMonthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->calendarMonth)->startOfMonth();
    }
```

Rewrite `getViewData()` (lines 237-283) to add chart points and calendar data, keeping every existing key:

```php
    public function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);
        $chartBuilder = app(ScoreChartBuilder::class);
        $projector = app(ScheduleOccurrenceProjector::class);

        $reports = $user->auditReports()
            ->with('auditRequest')
            ->latest()
            ->get();

        $quotas = $entitlements->quotas($user, $tenant);

        $schedules = AuditSchedule::query()->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (AuditSchedule $s): string => rtrim($s->repo_url, '/'));

        $repoGroups = $reports
            ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
            ->map(function ($group) use ($chartBuilder) {
                $ordered = $group->reverse()->values();
                $scores = $ordered->map(fn ($r) => (int) data_get($r->payload, 'scores.overall', 0));
                $dates = $ordered->map(fn ($r) => $r->created_at);

                return [
                    'reports' => $group,
                    'scores' => $scores,
                    'chartPoints' => $chartBuilder->build($scores, $dates),
                ];
            });

        $calendarMonthStart = $this->calendarMonthStart();
        $calendarMonthEnd = $calendarMonthStart->copy()->endOfMonth();

        $calendarByRepo = $schedules->mapWithKeys(function (AuditSchedule $schedule) use ($projector, $calendarMonthStart, $calendarMonthEnd) {
            $past = AuditScheduleRun::query()
                ->where('audit_schedule_id', $schedule->id)
                ->whereBetween('scheduled_for', [$calendarMonthStart->toDateString(), $calendarMonthEnd->toDateString()])
                ->get()
                ->keyBy(fn (AuditScheduleRun $run) => $run->scheduled_for->toDateString());

            $upcoming = $projector->upcomingDatesInMonth($schedule, $calendarMonthStart);

            return [rtrim($schedule->repo_url, '/') => ['past' => $past, 'upcoming' => $upcoming]];
        });

        return [
            'reports' => $reports,
            'quotas' => $quotas,
            // Any tier can start a run: one from quota, the rest by purchase.
            'canRun' => collect($quotas)->contains(
                fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable(),
            ),
            'schedules' => $schedules,
            'repoGroups' => $repoGroups,
            'calendarMonth' => $calendarMonthStart,
            'calendarByRepo' => $calendarByRepo,
            'deltas' => $reports
                ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
                ->map(function ($group): ?int {
                    // $reports is ordered latest-first, so index 0 is current.
                    $scores = $group
                        ->map(fn ($r): ?int => data_get($r->payload, 'scores.overall'))
                        ->filter(fn (?int $s): bool => $s !== null)
                        ->values();

                    if ($scores->count() < 2) {
                        return null;
                    }

                    return $scores->get(0) - $scores->get(1);
                })
                ->all(),
        ];
    }
```

- [ ] **Step 4: Rewrite the score-chart markup**

Edit `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`, replacing the existing chart block (current lines 86-97):

```blade
            {{-- Only meaningful with history; single-audit repos show a score and no chart. --}}
            @if (count($group['chartPoints']) > 1)
                <svg viewBox="0 0 200 40" class="mt-3 h-10 w-full" fill="none" aria-hidden="true">
                    <line x1="0" y1="9" x2="200" y2="9" stroke="currentColor" stroke-width="0.5" class="text-gray-200 dark:text-gray-700" />
                    <line x1="0" y1="19" x2="200" y2="19" stroke="currentColor" stroke-width="0.5" class="text-gray-200 dark:text-gray-700" />
                    <line x1="0" y1="29" x2="200" y2="29" stroke="currentColor" stroke-width="0.5" class="text-gray-200 dark:text-gray-700" />

                    @foreach ($group['chartPoints'] as $i => $point)
                        @if ($i > 0)
                            @php($previousPoint = $group['chartPoints'][$i - 1])
                            <line x1="{{ $previousPoint->x }}" y1="{{ $previousPoint->y }}" x2="{{ $point->x }}" y2="{{ $point->y }}" stroke="currentColor" stroke-width="2" class="{{ $point->colorClass }}" />
                        @endif

                        <circle cx="{{ $point->x }}" cy="{{ $point->y }}" r="{{ $point->delta !== null ? min(4, 1.5 + abs($point->delta) / 10) : 1.5 }}" fill="currentColor" class="{{ $point->colorClass }}">
                            <title>{{ $point->tooltip }}</title>
                        </circle>
                    @endforeach
                </svg>
            @endif
```

- [ ] **Step 5: Add the calendar section and its partial**

In `audit-reports.blade.php`, after the branch-select block added in Task 11 Step 5 (inside the `@if ($diagnostic && $diagnostic->limit > 0)` block, right after that closing `</div>` of the schedule controls), add:

```blade
                @if ($scheduleFrequency !== 'off')
                    @include('filament.dashboard.pages.partials.audit-calendar', [
                        'calendarMonth' => $calendarMonth,
                        'calendarData' => $calendarByRepo[$repoUrl] ?? ['past' => collect(), 'upcoming' => []],
                    ])
                @endif
```

Create `backend/resources/views/filament/dashboard/pages/partials/audit-calendar.blade.php`:

```blade
@php
    $monthStart = $calendarMonth->copy()->startOfMonth();
    $monthEnd = $calendarMonth->copy()->endOfMonth();
    $leadingBlanks = $monthStart->dayOfWeek;
    $upcomingDates = collect($calendarData['upcoming'])->map->toDateString();
@endphp

<div class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
    <div class="mb-2 flex items-center justify-between">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="prevCalendarMonth" :label="__('Previous month')" size="sm" />
        <p class="text-sm font-medium">{{ $monthStart->format('F Y') }}</p>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="nextCalendarMonth" :label="__('Next month')" size="sm" />
    </div>

    <div class="grid grid-cols-7 gap-1 text-center text-xs">
        @foreach ([__('Su'), __('Mo'), __('Tu'), __('We'), __('Th'), __('Fr'), __('Sa')] as $dayLabel)
            <div class="text-gray-400">{{ $dayLabel }}</div>
        @endforeach

        @for ($i = 0; $i < $leadingBlanks; $i++)
            <div></div>
        @endfor

        @for ($day = 1; $day <= $monthEnd->day; $day++)
            @php
                $date = $monthStart->copy()->addDays($day - 1);
                $dateKey = $date->toDateString();
                $run = $calendarData['past']->get($dateKey);
            @endphp

            <div class="flex flex-col items-center gap-0.5 rounded p-1" title="{{ $run?->status === 'skipped' ? __('Skipped: :reason', ['reason' => $run->reason]) : ($run !== null ? __('Audit completed') : ($upcomingDates->contains($dateKey) ? __('Scheduled') : '')) }}">
                <span>{{ $day }}</span>
                @if ($run?->status === 'completed')
                    <span class="audit-calendar-day-completed h-1.5 w-1.5 rounded-full bg-success-500"></span>
                @elseif ($run?->status === 'skipped')
                    <span class="audit-calendar-day-skipped h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                @elseif ($upcomingDates->contains($dateKey))
                    <span class="audit-calendar-day-scheduled h-1.5 w-1.5 rounded-full border border-primary-400"></span>
                @endif
            </div>
        @endfor
    </div>
</div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=AuditReportsRenderTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Filament/Dashboard/Pages/AuditReports.php resources/views/filament/dashboard/pages/audit-reports.blade.php resources/views/filament/dashboard/pages/partials/audit-calendar.blade.php tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php
git commit -m "feat(audit): enhanced score chart and per-schedule calendar view"
```

---

### Task 13: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend test suite**

Run: `cd backend && php artisan test --compact`
Expected: PASS, zero failures.

- [ ] **Step 2: Run Larastan**

Run: `cd backend && vendor/bin/phpstan analyse`
Expected: No errors.

- [ ] **Step 3: Format and verify Pint**

Run: `cd backend && vendor/bin/pint && vendor/bin/pint --test`
Expected: `pint --test` reports clean (no files need formatting) after `pint` has run.

- [ ] **Step 4: If Pint reformatted anything, commit the formatting fix**

```bash
cd backend && git add -A && git status
```

If `git status` shows changes, commit them:

```bash
git commit -m "style(audit): pint formatting pass"
```

If there are no changes, skip this step — nothing to commit.

---

## Self-Review Notes

- **Spec coverage:** §A (data model) → Tasks 1-3. §B.1 (`GitHubApiClient`) → Task 4. §B.2 (`RepositoryCloner` branch) → Task 5, threaded in Task 6. §B.3 (change check) → Task 7, wired into the command in Task 8. §B.4 (due-check update) → Task 8. §C score chart → Tasks 9 & 12. §C branch selector → Task 11. §C calendar → Tasks 10 & 12. §C testing → a dedicated test file/method per task throughout.
- **Backward compatibility:** Task 8's `isDue()` falls back to the pre-existing `last_run_at`-based check for any weekly schedule without `day_of_week` set, so the 2 unmodified `RunScheduledAuditsTest` cases (no quota / not yet due) keep passing without changes, and legacy rows never stop firing.
- **Type consistency verified:** `RepositoryCloner::clone()`'s third parameter (`?string $branch = null`, Task 5) matches its caller in `AuditPipeline` (Task 6) and its caller via `RunScheduledAudits` → `AuditRequest::create(['branch' => $schedule->branch])` (Task 8). `ScheduledAuditChangeChecker::check()` returns `ChangeCheckResult` (Task 7) whose `$shouldRun`/`$sha` properties are read identically in Task 8's `handle()`. `ScoreChartBuilder::build()` (Task 9) is called with `(Collection<int,int> $scores, Collection<int,Carbon> $dates)` both where it's implemented and where `getViewData()` calls it in Task 12. `ScheduleOccurrenceProjector::upcomingDatesInMonth()` (Task 10) signature matches its Task 12 call site.
