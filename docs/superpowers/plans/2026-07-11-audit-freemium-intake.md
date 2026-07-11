# Audit Freemium Intake & Monetization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the audit intake to gate on email verification, support private repos via GitHub collaborator invites with manual admin launch, capture marketing consent, and monetize reports (3 free basic per email; $5 one-time detail unlock; flat-rate subscription plans with monthly analysis allowances) plus a web report page, sample report, benchmark percentile, subscriber re-audit trends, and referral bonus.

**Architecture:** All intake changes live in the existing `AuditRequestService` / `AuditPipeline` flow. Entitlements are resolved at write time into a single field (`audit_reports.unlocked_at`); a new `AuditEntitlementService` owns quota math. Monetization rides existing SaaSykit rails: `OneTimeProduct` + `App\Events\Order\Ordered` listener (auto-discovered — no registration needed) for the $5 unlock, flat-rate `Plan`s with the allowance in `product.metadata['audit_analyses_per_month']` for subscriptions (SaaSykit has **no built-in allowance counting** — we count `audit_requests` rows ourselves). Per-user counters (referral bonus, unlock intent) use the existing `UserParameter` key/value model.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Pest-less PHPUnit feature tests extending `Tests\Feature\FeatureTest`, Astro 6 frontend.

**Spec:** `docs/superpowers/specs/2026-07-11-audit-freemium-intake-design.md`

## Global Constraints

- Backend commands run inside Docker: `docker compose exec laravel.test <cmd>` (from repo root). **The exit code of `php artisan test` is unreliable in this environment (systemic EXIT 1 despite green runs) — judge by reading the output, not `$?`.**
- Tests: `docker compose exec laravel.test php artisan test --filter=<Name> --compact`. Full suite must stay green (427 passing at baseline).
- Format with `docker compose exec laravel.test vendor/bin/pint --dirty` before every commit; static analysis `docker compose exec laravel.test vendor/bin/phpstan analyse` must stay clean at the end.
- Audit pipeline job stays on connection `redis-audit`, queue `audit`. The new lightweight routing job uses the **default** connection/queue.
- Pricing (verbatim from spec): unlock **$5** one-time (`500` minor units USD); plans **Starter 5/mo = $10** (`1000`), **Growth 20/mo = $30** (`3000`), **Scale 50/mo = $60** (`6000`).
- Free quota: **3 lifetime free runs per verified email** (config `audit.free_reports_limit`), plus per-user referral bonus.
- GitHub invite identity: dedicated account **`flexpick-audit`** (config-driven, GitHub-only instructions).
- Status strings are snake_case lowercase, matching the existing enum style.
- Frontend checks: `cd frontend && npm run check` must pass.
- Existing behavior that must NOT change: honeypot `website` field, 10-minute duplicate-email throttle, credential redaction in cloner errors, signed 30-day report links.

---

### Task 1: Schema, statuses, models, factories, config groundwork

**Files:**
- Create: `backend/database/migrations/2026_07_12_000001_add_verification_and_monetization_to_audit_tables.php`
- Modify: `backend/app/Constants/AuditRequestStatus.php`
- Modify: `backend/app/Mapper/AuditRequestStatusMapper.php`
- Modify: `backend/app/Models/AuditRequest.php`
- Modify: `backend/app/Models/AuditReport.php`
- Modify: `backend/database/factories/AuditRequestFactory.php`
- Modify: `backend/database/factories/AuditReportFactory.php`
- Modify: `backend/config/audit.php`
- Test: `backend/tests/Feature/Models/AuditMonetizationSchemaTest.php`
- Modify test: `backend/tests/Unit/AuditRequestStatusMapperTest.php`

**Interfaces:**
- Consumes: existing audit tables/models.
- Produces (later tasks rely on these exact names):
  - Statuses: `AuditRequestStatus::PENDING_VERIFICATION` (`pending_verification`), `AWAITING_ACCESS` (`awaiting_access`), `AWAITING_PAYMENT` (`awaiting_payment`).
  - `audit_requests` columns: `email_verified_at` (nullable ts), `marketing_consent` (bool, default false), `consented_at` (nullable ts), `free_run` (bool, default false), `source` (string, default `'web'`; values `'web'|'dashboard'`), `user_id` (nullable FK users, nullOnDelete).
  - `audit_reports` columns: `unlocked_at` (nullable ts), `unlock_order_id` (nullable FK orders, nullOnDelete); `pdf_path` becomes nullable.
  - `AuditRequest::user(): BelongsTo`; casts for new columns; all new columns fillable.
  - Factory states: `AuditRequestFactory::verified()`, `::freeRun()`, `::dashboardSource()`; `AuditReportFactory::unlocked()`, `::locked()` (locked = `pdf_path => null, unlocked_at => null`).
  - Config keys (exact): `audit.github_account` (default `flexpick-audit`, env `AUDIT_GITHUB_ACCOUNT`), `audit.github_token` (env `AUDIT_GITHUB_TOKEN`), `audit.free_reports_limit` (3), `audit.verification_link_hours` (48), `audit.unverified_purge_days` (7), `audit.benchmark_min_sample` (20), `audit.unlock_product_slug` (`audit-report-unlock`).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Models/AuditMonetizationSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Tests\Feature\FeatureTest;

class AuditMonetizationSchemaTest extends FeatureTest
{
    public function test_audit_request_verification_and_quota_columns(): void
    {
        $user = User::factory()->create();

        $request = AuditRequest::factory()->verified()->freeRun()->create([
            'marketing_consent' => true,
            'consented_at' => now(),
            'user_id' => $user->id,
        ]);

        $request->refresh();

        $this->assertNotNull($request->email_verified_at);
        $this->assertTrue($request->free_run);
        $this->assertTrue($request->marketing_consent);
        $this->assertNotNull($request->consented_at);
        $this->assertSame('web', $request->source);
        $this->assertTrue($request->user->is($user));
    }

    public function test_dashboard_source_factory_state(): void
    {
        $request = AuditRequest::factory()->dashboardSource()->create();

        $this->assertSame('dashboard', $request->refresh()->source);
    }

    public function test_audit_report_lock_columns(): void
    {
        $locked = AuditReport::factory()->locked()->create();
        $unlocked = AuditReport::factory()->unlocked()->create();

        $this->assertNull($locked->refresh()->unlocked_at);
        $this->assertNull($locked->pdf_path);
        $this->assertNotNull($unlocked->refresh()->unlocked_at);
    }

    public function test_new_statuses_exist(): void
    {
        $this->assertSame('pending_verification', AuditRequestStatus::PENDING_VERIFICATION->value);
        $this->assertSame('awaiting_access', AuditRequestStatus::AWAITING_ACCESS->value);
        $this->assertSame('awaiting_payment', AuditRequestStatus::AWAITING_PAYMENT->value);
    }
}
```

Add to `backend/tests/Unit/AuditRequestStatusMapperTest.php` (inside the existing class, matching its existing assertion style):

```php
    public function test_maps_new_intake_statuses(): void
    {
        $mapper = new \App\Mapper\AuditRequestStatusMapper;

        $this->assertSame('Pending verification', $mapper->mapForDisplay('pending_verification'));
        $this->assertSame('Awaiting repo access', $mapper->mapForDisplay('awaiting_access'));
        $this->assertSame('Awaiting payment', $mapper->mapForDisplay('awaiting_payment'));
        $this->assertSame('gray', $mapper->mapColor('pending_verification'));
        $this->assertSame('warning', $mapper->mapColor('awaiting_access'));
        $this->assertSame('warning', $mapper->mapColor('awaiting_payment'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditMonetizationSchemaTest --compact`
Expected: FAIL (unknown columns / undefined factory states / undefined enum cases).

- [ ] **Step 3: Implement**

Migration `backend/database/migrations/2026_07_12_000001_add_verification_and_monetization_to_audit_tables.php`:

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
            $table->timestamp('email_verified_at')->nullable()->after('status');
            $table->boolean('marketing_consent')->default(false)->after('email_verified_at');
            $table->timestamp('consented_at')->nullable()->after('marketing_consent');
            $table->boolean('free_run')->default(false)->index()->after('consented_at');
            $table->string('source')->default('web')->after('free_run');
            $table->foreignId('user_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });

        Schema::table('audit_reports', function (Blueprint $table) {
            $table->timestamp('unlocked_at')->nullable()->after('pdf_path');
            $table->foreignId('unlock_order_id')->nullable()->after('unlocked_at')->constrained('orders')->nullOnDelete();
            $table->string('pdf_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'source']);
        });

        Schema::table('audit_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unlock_order_id');
            $table->dropColumn(['unlocked_at']);
            $table->string('pdf_path')->nullable(false)->change();
        });
    }
};
```

`AuditRequestStatus.php` — add cases (keep existing ones):

```php
    case PENDING_VERIFICATION = 'pending_verification';
    case AWAITING_ACCESS = 'awaiting_access';
    case AWAITING_PAYMENT = 'awaiting_payment';
```

`AuditRequestStatusMapper.php` — add match arms before `default`:

```php
            // in mapForDisplay():
            AuditRequestStatus::PENDING_VERIFICATION->value => __('Pending verification'),
            AuditRequestStatus::AWAITING_ACCESS->value => __('Awaiting repo access'),
            AuditRequestStatus::AWAITING_PAYMENT->value => __('Awaiting payment'),

            // in mapColor(), add to the 'warning' arm:
            AuditRequestStatus::NEEDS_FOLLOWUP->value, AuditRequestStatus::AWAITING_ACCESS->value, AuditRequestStatus::AWAITING_PAYMENT->value => 'warning',
            // PENDING_VERIFICATION intentionally falls through to default 'gray'
```

`AuditRequest.php` — replace `$fillable`/`$casts` and add relation (keep everything else):

```php
    protected $fillable = [
        'name', 'email', 'repo_url', 'message', 'status', 'failure_reason', 'meta', 'metrics',
        'email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'source', 'user_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
        'email_verified_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'consented_at' => 'datetime',
        'free_run' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

(add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` and `use App\Models\User;` — User is same namespace, so just the BelongsTo import.)

`AuditReport.php`:

```php
    protected $fillable = ['audit_request_id', 'user_id', 'payload', 'pdf_path', 'unlocked_at', 'unlock_order_id'];

    protected $casts = [
        'payload' => 'array',
        'unlocked_at' => 'datetime',
    ];
```

`AuditRequestFactory.php` — add states:

```php
    public function verified(): static
    {
        return $this->state(fn () => ['email_verified_at' => now()]);
    }

    public function freeRun(): static
    {
        return $this->state(fn () => ['free_run' => true]);
    }

    public function dashboardSource(): static
    {
        return $this->state(fn () => ['source' => 'dashboard']);
    }
```

`AuditReportFactory.php` — add states:

```php
    public function unlocked(): static
    {
        return $this->state(fn () => ['unlocked_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['unlocked_at' => null, 'pdf_path' => null]);
    }
```

`config/audit.php` — add keys (keep existing):

```php
    'github_account' => env('AUDIT_GITHUB_ACCOUNT', 'flexpick-audit'),
    'github_token' => env('AUDIT_GITHUB_TOKEN'),
    'free_reports_limit' => 3,
    'verification_link_hours' => 48,
    'unverified_purge_days' => 7,
    'benchmark_min_sample' => 20,
    'unlock_product_slug' => 'audit-report-unlock',
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditMonetizationSchemaTest|AuditRequestStatusMapperTest' --compact`
Expected: PASS (read output; ignore exit code).

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/database/migrations/2026_07_12_000001_add_verification_and_monetization_to_audit_tables.php backend/app/Constants/AuditRequestStatus.php backend/app/Mapper/AuditRequestStatusMapper.php backend/app/Models/AuditRequest.php backend/app/Models/AuditReport.php backend/database/factories/ backend/config/audit.php backend/tests/
git commit -m "feat(backend): audit schema groundwork for verification, quota, and unlock entitlement"
```

---

### Task 2: AuditEntitlementService — free quota

**Files:**
- Create: `backend/app/Services/AuditReport/AuditEntitlementService.php`
- Test: `backend/tests/Feature/Services/AuditEntitlementServiceTest.php`

**Interfaces:**
- Consumes: Task 1 columns/states; `App\Models\UserParameter` (fillable `user_id, name, value` — `value` is a **string** column, cast it yourself).
- Produces:
  - `const BONUS_PARAM = 'audit_bonus_free_runs';`
  - `freeRunsLimit(string $email): int` — `config('audit.free_reports_limit')` + bonus from the matching user's `UserParameter` (0 if no user/param).
  - `freeRunsUsed(string $email): int` — count of `audit_requests` with that email and `free_run = true`.
  - `hasFreeRun(string $email): bool`
  - `consumeFreeRun(AuditRequest $auditRequest): void` — sets `free_run = true` (idempotent by nature).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/AuditEntitlementServiceTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditEntitlementServiceTest extends FeatureTest
{
    private AuditEntitlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditEntitlementService::class);
    }

    public function test_fresh_email_has_three_free_runs(): void
    {
        $this->assertSame(3, $this->service->freeRunsLimit('fresh@example.com'));
        $this->assertSame(0, $this->service->freeRunsUsed('fresh@example.com'));
        $this->assertTrue($this->service->hasFreeRun('fresh@example.com'));
    }

    public function test_only_free_run_flagged_requests_count(): void
    {
        AuditRequest::factory()->count(2)->freeRun()->create(['email' => 'used@example.com']);
        AuditRequest::factory()->create(['email' => 'used@example.com']); // not flagged — e.g. a failed submission

        $this->assertSame(2, $this->service->freeRunsUsed('used@example.com'));
        $this->assertTrue($this->service->hasFreeRun('used@example.com'));
    }

    public function test_quota_exhausts_at_limit(): void
    {
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);

        $this->assertFalse($this->service->hasFreeRun('maxed@example.com'));
    }

    public function test_registered_user_bonus_extends_limit(): void
    {
        $user = User::factory()->create(['email' => 'bonus@example.com']);
        UserParameter::create(['user_id' => $user->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '2']);
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'bonus@example.com']);

        $this->assertSame(5, $this->service->freeRunsLimit('bonus@example.com'));
        $this->assertTrue($this->service->hasFreeRun('bonus@example.com'));
    }

    public function test_consume_free_run_sets_flag(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'c@example.com']);

        $this->service->consumeFreeRun($request);

        $this->assertTrue($request->refresh()->free_run);
        $this->assertSame(1, $this->service->freeRunsUsed('c@example.com'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEntitlementServiceTest --compact`
Expected: FAIL — class `AuditEntitlementService` not found.

- [ ] **Step 3: Implement**

`backend/app/Services/AuditReport/AuditEntitlementService.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;

class AuditEntitlementService
{
    public const BONUS_PARAM = 'audit_bonus_free_runs';

    public function freeRunsLimit(string $email): int
    {
        $bonus = 0;

        $userId = User::where('email', $email)->value('id');
        if ($userId !== null) {
            $bonus = (int) UserParameter::query()
                ->where('user_id', $userId)
                ->where('name', self::BONUS_PARAM)
                ->value('value');
        }

        return (int) config('audit.free_reports_limit') + $bonus;
    }

    public function freeRunsUsed(string $email): int
    {
        return AuditRequest::query()
            ->where('email', $email)
            ->where('free_run', true)
            ->count();
    }

    public function hasFreeRun(string $email): bool
    {
        return $this->freeRunsUsed($email) < $this->freeRunsLimit($email);
    }

    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update(['free_run' => true]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEntitlementServiceTest --compact`
Expected: PASS (5 tests).

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app/Services/AuditReport/AuditEntitlementService.php backend/tests/Feature/Services/AuditEntitlementServiceTest.php
git commit -m "feat(backend): audit entitlement service for free-run quota with referral bonus"
```

---

### Task 3: Post-verification routing — `routeVerified()` + routing job + quota email + invite email rewrite

**Files:**
- Modify: `backend/app/Services/AuditRequestService.php`
- Create: `backend/app/Jobs/RouteVerifiedAuditRequest.php`
- Create: `backend/app/Mail/Audit/AuditQuotaExhausted.php`
- Create: `backend/resources/views/emails/audit/quota-exhausted.blade.php`
- Modify: `backend/resources/views/emails/audit/access-needed.blade.php`
- Test: `backend/tests/Feature/Services/AuditRequestRoutingTest.php`

**Interfaces:**
- Consumes: `AuditEntitlementService` (Task 2); `RepositoryCloner::preflight(string $url)` — **at this task's point in time preflight has no second parameter; Task 6 adds `bool $useToken = true` and switches routing to `useToken: false`. Call it plain here.** Existing mailables `AuditRequestReceived`, `AuditRepoAccessNeeded`; existing private `notifyAdmin()`.
- Produces:
  - `AuditRequestService::routeVerified(AuditRequest $auditRequest): void` — the single routing decision point used by the verify endpoint (Task 4) and tests.
  - `App\Jobs\RouteVerifiedAuditRequest` — plain queued job (default connection/queue), constructor `(public AuditRequest $auditRequest)`, `handle(AuditRequestService $service)` calls `routeVerified`.
  - `App\Mail\Audit\AuditQuotaExhausted` — constructor `(public AuditRequest $auditRequest)`, subject `__('Your free audits are used up — here\'s how to continue')`, view `emails.audit.quota-exhausted`.

Routing rules (exact):
1. no `repo_url` → `markNeedsFollowup($r, 'No repository URL provided')` (existing method — sends `AuditRepoAccessNeeded`), then `notifyAdmin`.
2. `repo_url` present, `preflight` **succeeds** (public):
   - `hasFreeRun(email)` → `consumeFreeRun`, status `QUEUED`, `GenerateAuditReport::dispatch`, send `AuditRequestReceived`, `notifyAdmin`.
   - no free run → status `AWAITING_PAYMENT`, send `AuditQuotaExhausted`, `notifyAdmin`.
3. `preflight` **throws** `AuditNotAnalyzableException` (private/unreachable) → status `AWAITING_ACCESS`, send `AuditRepoAccessNeeded`, `notifyAdmin`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/AuditRequestRoutingTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class AuditRequestRoutingTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake([GenerateAuditReport::class]);
        config(['audit.admin_email' => 'admin@flexpick.net']);

        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');
        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    private function route(AuditRequest $request): void
    {
        app(AuditRequestService::class)->routeVerified($request);
    }

    public function test_public_repo_with_free_quota_queues_and_consumes_run(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertTrue($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_public_repo_without_quota_awaits_payment(): void
    {
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);
        $request = AuditRequest::factory()->verified()->create([
            'email' => 'maxed@example.com',
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditQuotaExhausted::class, fn ($mail) => $mail->hasTo('maxed@example.com'));
    }

    public function test_unreachable_repo_awaits_access(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file:///nonexistent/private-repo',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_ACCESS->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }

    public function test_missing_repo_url_needs_followup(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $this->assertSame(AuditRequestStatus::NEEDS_FOLLOWUP->value, $request->refresh()->status);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestRoutingTest --compact`
Expected: FAIL — `routeVerified` undefined / `AuditQuotaExhausted` not found.

- [ ] **Step 3: Implement**

Add to `AuditRequestService` (new imports: `App\Exceptions\AuditNotAnalyzableException`, `App\Mail\Audit\AuditQuotaExhausted`, `App\Services\AuditReport\AuditEntitlementService`, `App\Services\AuditReport\RepositoryCloner`), constructor:

```php
    public function __construct(
        private AuditEntitlementService $entitlements,
        private RepositoryCloner $cloner,
    ) {}
```

New method:

```php
    public function routeVerified(AuditRequest $auditRequest): void
    {
        if ($auditRequest->repo_url === null) {
            $this->markNeedsFollowup($auditRequest, 'No repository URL provided');
            $this->notifyAdmin($auditRequest);

            return;
        }

        try {
            $this->cloner->preflight($auditRequest->repo_url);
        } catch (AuditNotAnalyzableException) {
            $auditRequest->update(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
            Mail::to($auditRequest->email)->send(new AuditRepoAccessNeeded($auditRequest));
            $this->notifyAdmin($auditRequest);

            return;
        }

        if (! $this->entitlements->hasFreeRun($auditRequest->email)) {
            $auditRequest->update(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);
            Mail::to($auditRequest->email)->send(new AuditQuotaExhausted($auditRequest));
            $this->notifyAdmin($auditRequest);

            return;
        }

        $this->entitlements->consumeFreeRun($auditRequest);
        $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);
        GenerateAuditReport::dispatch($auditRequest);
        Mail::to($auditRequest->email)->send(new AuditRequestReceived($auditRequest));
        $this->notifyAdmin($auditRequest);
    }
```

(Leave `submit()` untouched in this task — Task 4 rewrites it.)

`backend/app/Jobs/RouteVerifiedAuditRequest.php`:

```php
<?php

namespace App\Jobs;

use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RouteVerifiedAuditRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function handle(AuditRequestService $service): void
    {
        $service->routeVerified($this->auditRequest);
    }
}
```

`backend/app/Mail/Audit/AuditQuotaExhausted.php` — copy the exact structure of `AuditRepoAccessNeeded` (Mailable, ShouldQueue, `Queueable, SerializesModels`, constructor `public AuditRequest $auditRequest`), with:
- `envelope()`: `subject: __('Your free audits are used up — here\'s how to continue')`
- `content()`: `view: 'emails.audit.quota-exhausted'`

`backend/resources/views/emails/audit/quota-exhausted.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your free audits are used up — here\'s how to continue') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You\'ve used all of your free codebase audits, so we couldn\'t start this one.') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('To keep auditing, pick a subscription — plans start at $10/month for 5 analyses, and every subscription report includes full details and PDF export.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ url('/pricing') }}" style="color: #2563eb; text-decoration: underline;">{{ __('See plans and pricing') }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
```

Rewrite the middle paragraph block of `access-needed.blade.php` — replace the current `@if ($auditRequest->repo_url) ... @endif` + closing paragraph with:

```blade
            @if ($auditRequest->repo_url)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We couldn't access :url — it looks private (or the link isn't a reachable git repository).", ['url' => $auditRequest->repo_url]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('To let us analyze it, invite our review account :account as a read-only collaborator on GitHub:', ['account' => config('audit.github_account')]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Repository → Settings → Collaborators → Add people → search for ":account" → set role to "Read".', ['account' => config('audit.github_account')]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('We\'ll start the analysis as soon as the invite is accepted — usually within one business day. On another git host, or prefer not to invite us? Just reply to this email. Happy to sign an NDA first.') }}
                </p>
            @else
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("You didn't include a repository link, so we couldn't start the automated analysis.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Reply to this email with a repository URL — for private GitHub repos, also invite our review account :account as a read-only collaborator. Happy to sign an NDA first.', ['account' => config('audit.github_account')]) }}
                </p>
            @endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestRoutingTest --compact`
Expected: PASS (4 tests).

Also run the existing suite slices that touch the changed service: `docker compose exec laravel.test php artisan test --filter='AuditRequestControllerTest|AuditPipelineTest|AuditMailablesTest' --compact` — must stay green (submit() unchanged so far).

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/resources/views/emails/audit backend/tests
git commit -m "feat(backend): post-verification routing with quota gate and GitHub invite instructions"
```

---

### Task 4: Verification gate — submit() rewrite, verify mailable, signed endpoint

**Files:**
- Modify: `backend/app/Services/AuditRequestService.php` (submit)
- Modify: `backend/app/Http/Requests/StoreAuditRequestRequest.php`
- Modify: `backend/app/Http/Controllers/AuditRequestController.php`
- Create: `backend/app/Mail/Audit/AuditVerifyEmail.php`
- Create: `backend/resources/views/emails/audit/verify.blade.php`
- Create: `backend/resources/views/audit/verified.blade.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/bootstrap/app.php` (expired-signature handling)
- Test: `backend/tests/Feature/Http/Controllers/AuditVerificationTest.php`
- Modify test: `backend/tests/Feature/Http/Controllers/AuditRequestControllerTest.php` (update expectations: submit no longer dispatches/queues)

**Interfaces:**
- Consumes: `RouteVerifiedAuditRequest` job (Task 3).
- Produces:
  - `POST /api/audit-requests` accepts optional `marketing_consent` (boolean). Response unchanged (`201`, `{id: uuid}`).
  - `AuditRequestService::submit()` now: dedupe guard (unchanged) → create with `status = PENDING_VERIFICATION`, `marketing_consent`, `consented_at` → send `AuditVerifyEmail` **only**. No admin mail, no dispatch, no received mail.
  - `AuditRequestService::verificationUrl(AuditRequest): string` — `URL::temporarySignedRoute('audit-requests.verify', now()->addHours((int) config('audit.verification_link_hours')), ['auditRequest' => $auditRequest->uuid])`.
  - Route: `GET /audit-requests/{auditRequest:uuid}/verify`, name `audit-requests.verify`, middleware `signed`.
  - `AuditRequestController::verify(AuditRequest $auditRequest)` — idempotent: if already verified, render view without side effects; else set `email_verified_at = now()`, dispatch `RouteVerifiedAuditRequest`, render `audit.verified` view.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/Http/Controllers/AuditVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Mail\Audit\AuditVerifyEmail;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditVerificationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['audit.admin_email' => 'admin@flexpick.net']);
    }

    public function test_submit_creates_pending_request_and_sends_only_verification_email(): void
    {
        $response = $this->postJson('/api/audit-requests', [
            'name' => 'Ada', 'email' => 'ada@example.com',
            'repo_url' => 'https://github.com/example/repo',
            'marketing_consent' => true,
        ]);

        $response->assertCreated();
        $request = AuditRequest::firstOrFail();
        $this->assertSame(AuditRequestStatus::PENDING_VERIFICATION->value, $request->status);
        $this->assertNull($request->email_verified_at);
        $this->assertTrue($request->marketing_consent);
        $this->assertNotNull($request->consented_at);
        Mail::assertQueued(AuditVerifyEmail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
        Mail::assertNotQueued(NewAuditRequestAdminNotification::class);
        Queue::assertNothingPushed();
    }

    public function test_submit_without_consent_stores_false(): void
    {
        $this->postJson('/api/audit-requests', ['name' => 'Bob', 'email' => 'bob@example.com'])->assertCreated();

        $request = AuditRequest::firstOrFail();
        $this->assertFalse($request->marketing_consent);
        $this->assertNull($request->consented_at);
    }

    public function test_signed_verify_link_marks_verified_and_dispatches_routing(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);

        $url = app(AuditRequestService::class)->verificationUrl($request);
        $this->get($url)->assertOk()->assertSee(__('Email confirmed'));

        $this->assertNotNull($request->refresh()->email_verified_at);
        Queue::assertPushed(RouteVerifiedAuditRequest::class, 1);
    }

    public function test_verify_is_idempotent(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);
        $url = app(AuditRequestService::class)->verificationUrl($request);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        Queue::assertPushed(RouteVerifiedAuditRequest::class, 1);
    }

    public function test_unsigned_verify_link_is_rejected(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);

        $this->get("/audit-requests/{$request->uuid}/verify")->assertForbidden();
        $this->assertNull($request->refresh()->email_verified_at);
    }

    public function test_expired_verify_link_is_rejected(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);
        $url = URL::temporarySignedRoute('audit-requests.verify', now()->subMinute(), ['auditRequest' => $request->uuid]);

        $this->get($url)->assertForbidden();
        $this->assertNull($request->refresh()->email_verified_at);
    }
}
```

Update `AuditRequestControllerTest.php`: any existing assertion that `submit` dispatches `GenerateAuditReport`, sets status `queued`/`needs_followup`, or sends `AuditRequestReceived`/`NewAuditRequestAdminNotification`/`AuditRepoAccessNeeded` must now assert: status `pending_verification` + `AuditVerifyEmail` queued instead. Keep honeypot, validation, and throttle tests as-is.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditVerificationTest --compact`
Expected: FAIL — `AuditVerifyEmail` not found / route not defined.

- [ ] **Step 3: Implement**

`AuditRequestService::submit()` — replace the body after the create with verification-only behavior; full new method:

```php
    public function submit(array $data, array $meta = []): AuditRequest
    {
        $recentDuplicate = AuditRequest::query()
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentDuplicate) {
            throw new TooManyRequestsHttpException(600, __('We already received a request from this email. Give us a few minutes.'));
        }

        $consented = (bool) ($data['marketing_consent'] ?? false);

        $auditRequest = AuditRequest::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'repo_url' => $data['repo_url'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'marketing_consent' => $consented,
            'consented_at' => $consented ? now() : null,
            'meta' => $meta,
        ]);

        Mail::to($auditRequest->email)->send(new AuditVerifyEmail($auditRequest, $this->verificationUrl($auditRequest)));

        return $auditRequest;
    }

    public function verificationUrl(AuditRequest $auditRequest): string
    {
        return URL::temporarySignedRoute(
            'audit-requests.verify',
            now()->addHours((int) config('audit.verification_link_hours')),
            ['auditRequest' => $auditRequest->uuid],
        );
    }
```

(new imports: `App\Mail\Audit\AuditVerifyEmail`, `Illuminate\Support\Facades\URL`.)

`StoreAuditRequestRequest::rules()` — add:

```php
            'marketing_consent' => ['sometimes', 'boolean'],
```

`AuditRequestController` — add:

```php
    public function verify(AuditRequest $auditRequest)
    {
        if ($auditRequest->email_verified_at === null) {
            $auditRequest->update(['email_verified_at' => now()]);
            RouteVerifiedAuditRequest::dispatch($auditRequest);
        }

        return view('audit.verified', ['auditRequest' => $auditRequest]);
    }
```

(imports: `App\Jobs\RouteVerifiedAuditRequest`.)

`routes/web.php` — add next to the existing audit report routes:

```php
Route::get('/audit-requests/{auditRequest:uuid}/verify', [AuditRequestController::class, 'verify'])
    ->name('audit-requests.verify')
    ->middleware('signed');
```

(import `App\Http\Controllers\AuditRequestController` if not present.)

`backend/app/Mail/Audit/AuditVerifyEmail.php` — same Mailable/ShouldQueue structure as `AuditRepoAccessNeeded`, but constructor `(public AuditRequest $auditRequest, public string $verificationUrl)`, subject `__('Confirm your email to start your free audit')`, view `emails.audit.verify`.

`backend/resources/views/emails/audit/verify.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Confirm your email to start your free audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Confirm your email address and we\'ll start your free codebase audit right away.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                <a href="{{ $verificationUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                    {{ __('Confirm my email') }}
                </a>
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                {{ __('This link expires in :hours hours. If you didn\'t request an audit from FlexPick, you can ignore this email.', ['hours' => config('audit.verification_link_hours')]) }}
            </p>
        </td>
    </tr>
</x-layouts.email>
```

`backend/resources/views/audit/verified.blade.php` (standalone, mirrors the tone of `reports/link-expired` if present — self-contained page):

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Email confirmed') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 40px; max-width: 440px; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #57534e; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Email confirmed') }}</h1>
        <p>{{ __('Thanks — we\'re checking your repository now. You\'ll get an email with next steps (or your report) shortly.') }}</p>
    </div>
</body>
</html>
```

`bootstrap/app.php` — the existing `withExceptions` block renders `reports.link-expired` on `InvalidSignatureException` for `reports.view`; extend its route check to also match the verify route, e.g. change the condition to:

```php
if ($request->routeIs('reports.view') || $request->routeIs('audit-requests.verify')) {
```

(keep the same rendered view/response as the existing branch).

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditVerificationTest|AuditRequestControllerTest' --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/routes/web.php backend/bootstrap/app.php backend/resources/views backend/tests
git commit -m "feat(backend): email verification gate for audit intake with signed confirm links"
```

---

### Task 5: Purge command for unverified requests

**Files:**
- Create: `backend/app/Console/Commands/PurgeUnverifiedAuditRequests.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/PurgeUnverifiedAuditRequestsTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: artisan command `app:purge-unverified-audit-requests`, scheduled `dailyAt('02:10')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class PurgeUnverifiedAuditRequestsTest extends FeatureTest
{
    public function test_purges_only_old_unverified_requests(): void
    {
        $oldUnverified = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'created_at' => now()->subDays(8),
        ]);
        $freshUnverified = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'created_at' => now()->subDays(2),
        ]);
        $oldVerified = AuditRequest::factory()->verified()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('app:purge-unverified-audit-requests')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['id' => $oldUnverified->id]);
        $this->assertDatabaseHas('audit_requests', ['id' => $freshUnverified->id]);
        $this->assertDatabaseHas('audit_requests', ['id' => $oldVerified->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PurgeUnverifiedAuditRequestsTest --compact`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement**

`backend/app/Console/Commands/PurgeUnverifiedAuditRequests.php`:

```php
<?php

namespace App\Console\Commands;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Illuminate\Console\Command;

class PurgeUnverifiedAuditRequests extends Command
{
    protected $signature = 'app:purge-unverified-audit-requests';

    protected $description = 'Delete audit requests that were never email-verified after the retention window';

    public function handle(): int
    {
        $deleted = AuditRequest::query()
            ->where('status', AuditRequestStatus::PENDING_VERIFICATION->value)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays((int) config('audit.unverified_purge_days')))
            ->delete();

        $this->info("Purged {$deleted} unverified audit request(s).");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add alongside the existing `Schedule::command(...)` lines:

```php
Schedule::command('app:purge-unverified-audit-requests')->dailyAt('02:10');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PurgeUnverifiedAuditRequestsTest --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app/Console backend/routes/console.php backend/tests/Feature/Console
git commit -m "feat(backend): scheduled purge of unverified audit requests"
```

---

### Task 6: GitHub PAT injection in RepositoryCloner

**Files:**
- Modify: `backend/app/Services/AuditReport/RepositoryCloner.php`
- Modify: `backend/app/Services/AuditRequestService.php` (routing preflight → `useToken: false`)
- Test: `backend/tests/Feature/Services/RepositoryClonerAuthTest.php`

**Interfaces:**
- Consumes: `audit.github_token` config (Task 1).
- Produces:
  - `preflight(string $url, bool $useToken = true): void` — routing (Task 3's `routeVerified`) switches to `preflight($url, useToken: false)` so "public?" detection stays unauthenticated; the pipeline keeps default `true`.
  - `clone()` always uses the authenticated URL.
  - `private authenticatedUrl(string $url): string` — injects `https://x-access-token:<token>@github.com/...` only when a token is configured AND the URL starts with `https://github.com/`; all other URLs pass through unchanged.
  - Token must never appear in exception messages (existing `redactUrl` covers `//user:pass@` — keep messages built from the **original** `$url`, not the authenticated one).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Exceptions\AuditNotAnalyzableException;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class RepositoryClonerAuthTest extends FeatureTest
{
    public function test_preflight_with_token_uses_authenticated_github_url(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private');

        Process::assertRan(fn (PendingProcess $process) => in_array(
            'https://x-access-token:ghp_secret123@github.com/acme/private',
            $process->command,
            true,
        ));
    }

    public function test_preflight_without_use_token_stays_unauthenticated(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private', useToken: false);

        Process::assertRan(fn (PendingProcess $process) => in_array(
            'https://github.com/acme/private',
            $process->command,
            true,
        ));
    }

    public function test_non_github_url_is_never_authenticated(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://gitlab.com/acme/repo');

        Process::assertRan(fn (PendingProcess $process) => in_array('https://gitlab.com/acme/repo', $process->command, true));
    }

    public function test_token_never_leaks_into_exception_message(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result(exitCode: 128)]);

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/private');
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('ghp_secret123', $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=RepositoryClonerAuthTest --compact`
Expected: FAIL — token not injected / unknown named argument `useToken`.

- [ ] **Step 3: Implement**

In `RepositoryCloner`:

```php
    public function preflight(string $url, bool $useToken = true): void
    {
        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', '--exit-code', $useToken ? $this->authenticatedUrl($url) : $url, 'HEAD']);

        if (! $result->successful()) {
            throw new AuditNotAnalyzableException(
                'Repository is not publicly accessible: '.$this->redactUrl($url)
            );
        }
    }
```

In `clone()`, change the run line's URL argument to `$this->authenticatedUrl($url)` (the failure message keeps using `$this->redactUrl($url)` on the original).

Add:

```php
    private function authenticatedUrl(string $url): string
    {
        $token = config('audit.github_token');

        if (! $token || ! str_starts_with($url, 'https://github.com/')) {
            return $url;
        }

        return 'https://x-access-token:'.$token.'@'.substr($url, strlen('https://'));
    }
```

In `AuditRequestService::routeVerified()`, change the preflight call to:

```php
            $this->cloner->preflight($auditRequest->repo_url, useToken: false);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='RepositoryClonerAuthTest|AuditRequestRoutingTest|RepositoryClonerTest|AuditPipelineTest' --compact`
Expected: PASS (routing + existing cloner/pipeline tests unaffected — no token configured by default in tests).

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app/Services backend/tests
git commit -m "feat(backend): GitHub PAT injection for private repo clones with redaction guarantee"
```

---

### Task 7: Filament admin — launch action, verification/consent columns

**Files:**
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`
- Test: modify `backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: statuses (Task 1), `AuditEntitlementService` (Task 2), `GenerateAuditReport`.
- Produces: table action `launch` (visible on `AWAITING_ACCESS` and `AWAITING_PAYMENT` when `repo_url` present) — consumes a free run if available (comp launch otherwise), sets `QUEUED`, clears `failure_reason`, dispatches the job. New table columns: `email_verified_at` (dateTime), `marketing_consent` (boolean icon), `free_run` (boolean icon), `source`.

- [ ] **Step 1: Write the failing test** (add to the existing resource test class, following its existing Livewire/actions test style — if the existing test calls actions via `Livewire::test(ListAuditRequests::class)->callTableAction('retry', $record)`, mirror that):

```php
    public function test_launch_action_queues_awaiting_access_request(): void
    {
        Queue::fake([\App\Jobs\GenerateAuditReport::class]);
        $record = \App\Models\AuditRequest::factory()->verified()->create([
            'status' => \App\Constants\AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        \Livewire\Livewire::actingAs($this->createAdminUser())
            ->test(\App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(\App\Constants\AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertTrue($record->free_run);
        Queue::assertPushed(\App\Jobs\GenerateAuditReport::class);
    }

    public function test_launch_action_comps_when_quota_exhausted(): void
    {
        Queue::fake([\App\Jobs\GenerateAuditReport::class]);
        \App\Models\AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);
        $record = \App\Models\AuditRequest::factory()->verified()->create([
            'email' => 'maxed@example.com',
            'status' => \App\Constants\AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);

        \Livewire\Livewire::actingAs($this->createAdminUser())
            ->test(\App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(\App\Constants\AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertFalse($record->free_run);
        Queue::assertPushed(\App\Jobs\GenerateAuditReport::class);
    }
```

**Note:** check how the existing test file creates/authenticates an admin — reuse its helper (`createAdminUser()` above is a placeholder for whatever the existing file uses; copy its pattern exactly).

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest --compact`
Expected: FAIL — table action `launch` not found.

- [ ] **Step 3: Implement**

In `AuditRequestResource::table()` add after the `retry` action:

```php
                Action::make('launch')
                    ->label(__('Launch report'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::AWAITING_ACCESS->value,
                        AuditRequestStatus::AWAITING_PAYMENT->value,
                    ], true))
                    ->action(function (AuditRequest $record): void {
                        $entitlements = app(AuditEntitlementService::class);
                        if ($entitlements->hasFreeRun($record->email)) {
                            $entitlements->consumeFreeRun($record);
                        }

                        $record->update(['status' => AuditRequestStatus::QUEUED->value, 'failure_reason' => null]);
                        GenerateAuditReport::dispatch($record);
                    }),
```

(import `App\Services\AuditReport\AuditEntitlementService`.)

Add columns after the `status` column:

```php
                TextColumn::make('email_verified_at')->dateTime(config('app.datetime_format'))->label(__('Verified'))->placeholder(__('No')),
                IconColumn::make('marketing_consent')->boolean()->label(__('Consent')),
                IconColumn::make('free_run')->boolean()->label(__('Free run')),
                TextColumn::make('source'),
```

(import `Filament\Tables\Columns\IconColumn`.)

Add matching infolist entries in the `Request` section: `TextEntry::make('email_verified_at')->dateTime(config('app.datetime_format'))`, `TextEntry::make('marketing_consent')`, `TextEntry::make('source')`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app/Filament backend/tests
git commit -m "feat(backend): admin launch action and verification columns for audit requests"
```

---

### Task 8: AuditReportService rework — entitlement-aware creation, unlock(), PDF as paid perk

**Files:**
- Modify: `backend/app/Services/AuditReport/AuditReportService.php`
- Create: `backend/app/Mail/Audit/AuditReportUnlocked.php`
- Create: `backend/resources/views/emails/audit/unlocked.blade.php`
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (download guard)
- Test: `backend/tests/Feature/Services/AuditReportUnlockTest.php`

**Interfaces:**
- Consumes: Task 1 columns; `App\Models\Order`.
- Produces:
  - `create()` behavior change: reports from `source === 'dashboard'` requests are born unlocked (`unlocked_at = now()`) **with** PDF; all others are born locked (`unlocked_at = null`, `pdf_path = null`, no PDF generated).
  - `unlock(AuditReport $report, ?Order $order = null): void` — no-op if already unlocked; sets `unlocked_at`/`unlock_order_id`, generates the PDF, sends `AuditReportUnlocked` mail with a fresh signed URL. **Task 9's grant-unlock admin action and Task 10's order listener both call exactly this.**
  - `download` route now also 404s when `pdf_path` is null.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditReportUnlocked;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportUnlockTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }

    public function test_web_source_report_is_born_locked_without_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNull($report->unlocked_at);
        $this->assertNull($report->pdf_path);
    }

    public function test_dashboard_source_report_is_born_unlocked_with_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->dashboardSource()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
        Storage::disk('local')->assertExists($report->pdf_path);
    }

    public function test_unlock_generates_pdf_and_sends_mail_once(): void
    {
        $request = AuditRequest::factory()->verified()->create();
        $report = app(AuditReportService::class)->create($request, $this->payload());

        app(AuditReportService::class)->unlock($report);
        app(AuditReportService::class)->unlock($report); // idempotent

        $report->refresh();
        $this->assertNotNull($report->unlocked_at);
        Storage::disk('local')->assertExists($report->pdf_path);
        Mail::assertQueued(AuditReportUnlocked::class, 1);
    }

    public function test_locked_report_pdf_download_is_denied(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $user = \App\Models\User::factory()->create();
        $report->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportUnlockTest --compact`
Expected: FAIL — locked report still gets a PDF / `unlock` undefined / `AuditReportUnlocked` not found.

- [ ] **Step 3: Implement**

`AuditReportService` — full reworked class body (imports add `App\Mail\Audit\AuditReportUnlocked`, `App\Models\Order`):

```php
    public function create(AuditRequest $auditRequest, array $payload): AuditReport
    {
        if ($existing = $auditRequest->report()->first()) {
            if ($existing->pdf_path !== null) {
                Storage::disk('local')->delete($existing->pdf_path);
            }
            $existing->delete();
        }

        $unlocked = $auditRequest->source === 'dashboard';

        $report = new AuditReport([
            'audit_request_id' => $auditRequest->id,
            'user_id' => $auditRequest->user_id ?? User::where('email', $auditRequest->email)->value('id'),
            'payload' => $payload,
            'pdf_path' => null,
            'unlocked_at' => $unlocked ? now() : null,
        ]);
        $report->save();

        if ($unlocked) {
            $this->generatePdf($report);
        }

        $auditRequest->update(['status' => AuditRequestStatus::REPORT_READY->value]);

        return $report;
    }

    public function unlock(AuditReport $report, ?Order $order = null): void
    {
        if ($report->unlocked_at !== null) {
            return;
        }

        $report->update(['unlocked_at' => now(), 'unlock_order_id' => $order?->id]);
        $this->generatePdf($report);

        Mail::to($report->auditRequest->email)
            ->send(new AuditReportUnlocked($report, $this->signedUrl($report)));
    }

    private function generatePdf(AuditReport $report): void
    {
        $pdfPath = config('audit.reports_dir').'/'.$report->uuid.'.pdf';
        $pdf = Pdf::loadView('reports.audit', ['report' => $report]);
        Storage::disk('local')->put($pdfPath, $pdf->output());

        $report->update(['pdf_path' => $pdfPath]);
    }
```

(`send()` and `signedUrl()` unchanged.)

`AuditReportController::download()` — add before the return:

```php
        abort_if($auditReport->pdf_path === null, 404);
```

`AuditReportUnlocked` mailable — same structure as `AuditReportReady` (constructor `(public AuditReport $report, public string $reportUrl)`), subject `__('Your full codebase report is unlocked')`, view `emails.audit.unlocked`.

`backend/resources/views/emails/audit/unlocked.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your full codebase report is unlocked') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $report->auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Thanks for your purchase — every finding, recommendation, and the fix-first plan in your report is now visible, and the PDF export is ready.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                <a href="{{ $reportUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                    {{ __('Open my full report') }}
                </a>
            </p>
        </td>
    </tr>
</x-layouts.email>
```

**Also update existing tests** that assert `create()` writes a PDF for web-source requests (in `AuditPipelineTest` / `AuditReportControllerTest` / any `AuditReportService` test): web-source reports are now locked without PDF — adjust assertions accordingly (e.g. assert `pdf_path` null instead of file exists).

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditReportUnlockTest|AuditPipelineTest|AuditReportControllerTest' --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/resources/views/emails/audit backend/tests
git commit -m "feat(backend): entitlement-aware report creation with unlock() and PDF as paid perk"
```

---

### Task 9: Web report page, sample report, report-ready email rewrite, admin grant-unlock

**Files:**
- Create: `backend/resources/views/reports/audit-web.blade.php`
- Create: `backend/resources/data/sample-audit-report.json`
- Modify: `backend/app/Http/Controllers/AuditReportController.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/resources/views/emails/audit/report-ready.blade.php` (copy only: "View your report online")
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` (grant-unlock action)
- Test: `backend/tests/Feature/Http/Controllers/AuditReportPageTest.php`

**Interfaces:**
- Consumes: `unlocked_at` (Task 1), `AuditReportService::unlock()` (Task 8).
- Produces:
  - `reports.view` (existing signed route) renders `reports.audit-web` with `['report' => ..., 'unlocked' => bool, 'isSample' => false, 'percentile' => null]`. (`percentile` stays null until Task 11 wires it — the blade must handle null by hiding the line.)
  - Route `GET /reports/sample`, name `reports.sample`, **declared BEFORE** the `{auditReport:uuid}` route, no middleware — renders the same blade with `isSample => true`, `unlocked => true` from the JSON fixture.
  - Locked rendering contract: `summary`, all `scores`, risk `title` + `impact` always visible; risk `evidence` + `recommendation` and the whole `fix_first_plan` section hidden behind lock overlays with CTAs to `url("/reports/{$report->uuid}/unlock")` ($5) and `url('/pricing')` (subscribe). *(Deliberate `url()` not `route()` — the unlock route is created in Task 10; the link 404s until then, which is fine mid-branch.)*
  - Unlocked rendering: everything + "Download PDF" link to `route('reports.download', $report->uuid)` (hidden for sample).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportPageTest extends FeatureTest
{
    public function test_locked_report_shows_titles_but_hides_details(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('No tests')                       // risk title visible
            ->assertDontSee('Add a smoke suite')          // recommendation hidden
            ->assertDontSee('0 test files')               // evidence hidden
            ->assertSee(__('Unlock full report'))
            ->assertSee('/unlock');
    }

    public function test_unlocked_report_shows_everything_and_pdf_link(): void
    {
        $report = AuditReport::factory()->unlocked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $this->get($url)
            ->assertOk()
            ->assertSee('Add a smoke suite')
            ->assertSee('Add CI')
            ->assertSee(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertDontSee(__('Unlock full report'));
    }

    public function test_sample_report_is_public_and_unlocked(): void
    {
        $this->get('/reports/sample')
            ->assertOk()
            ->assertSee(__('Sample report'))
            ->assertSee(__('What to fix first'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportPageTest --compact`
Expected: FAIL — locked report currently renders full PDF blade (recommendation visible), `/reports/sample` 404/403.

- [ ] **Step 3: Implement**

`AuditReportController`:

```php
    public function show(AuditReport $auditReport)
    {
        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => null,
        ]);
    }

    public function sample()
    {
        $fixture = json_decode(file_get_contents(resource_path('data/sample-audit-report.json')), true);

        $request = new \App\Models\AuditRequest(['repo_url' => $fixture['repo_url']]);
        $report = new AuditReport(['payload' => $fixture['payload']]);
        $report->setRelation('auditRequest', $request);
        $report->created_at = now();

        return view('reports.audit-web', [
            'report' => $report,
            'unlocked' => true,
            'isSample' => true,
            'percentile' => $fixture['percentile'],
        ]);
    }
```

`routes/web.php` — insert **above** the existing `reports.view` route:

```php
Route::get('/reports/sample', [AuditReportController::class, 'sample'])->name('reports.sample');
```

`backend/resources/data/sample-audit-report.json`:

```json
{
    "repo_url": "https://github.com/acme/example-saas",
    "percentile": 38,
    "payload": {
        "summary": "A four-month-old AI-assisted codebase with working core flows but heavy duplication, no automated tests, and several dependency risks. Shippable today, fragile tomorrow: most incidents will come from the checkout and auth modules, where copies of the same logic have already drifted apart.",
        "scores": {"structure": 58, "duplication": 34, "testing": 12, "dependencies": 61, "security_hygiene": 55, "overall": 44},
        "risks": [
            {"title": "No automated tests on payment-critical flows", "impact": "high", "evidence": "0 test files across 214 source files; checkout, refund, and webhook handlers are fully untested.", "recommendation": "Add a smoke suite around checkout and webhooks first — 10-15 tests catch the regressions that actually cost money."},
            {"title": "Checkout logic duplicated in 4 places with drift", "impact": "high", "evidence": "Price calculation appears in 4 files; two copies already disagree on coupon rounding.", "recommendation": "Extract a single PriceCalculator service and delete the copies; the drift is a live billing bug waiting to be reported."},
            {"title": "Secrets committed to the repository", "impact": "high", "evidence": "2 API keys and 1 database password found in tracked config files.", "recommendation": "Rotate the exposed keys today, move them to environment variables, and add secret scanning to CI."},
            {"title": "37 dependencies pinned to unmaintained versions", "impact": "medium", "evidence": "Lockfile references 9 packages with no release in 2+ years, including the session middleware.", "recommendation": "Upgrade the framework minor version and replace the two abandoned packages with maintained forks."},
            {"title": "God-file controllers", "impact": "medium", "evidence": "3 controllers exceed 800 lines and mix validation, business logic, and rendering.", "recommendation": "Split by responsibility as you touch them — don't big-bang refactor; extract services per feature."},
            {"title": "No error monitoring", "impact": "low", "evidence": "No error-tracking SDK found; production failures are invisible unless a user reports them.", "recommendation": "Wire an error tracker (Sentry or similar) — 30 minutes of work, immediate visibility."}
        ],
        "fix_first_plan": [
            {"step": "Rotate the committed secrets and move them to env vars", "why": "They are exposed to anyone with repo access, today", "effort": "S"},
            {"step": "Add smoke tests around checkout and webhooks", "why": "Highest-cost regressions with zero safety net", "effort": "M"},
            {"step": "Extract the duplicated price calculation into one service", "why": "Two copies already disagree — this is a live billing bug", "effort": "M"},
            {"step": "Set up CI running the new tests plus secret scanning", "why": "Locks in the gains from the first three steps", "effort": "S"}
        ]
    }
}
```

`backend/resources/views/reports/audit-web.blade.php` — complete file:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health Report') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; margin: 0; padding: 32px 16px; font-size: 15px; line-height: 1.5; }
        .page { max-width: 860px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 28px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 14px; }
        .muted { color: #78716c; font-size: 12px; }
        .sample-banner { background: #d4a853; color: #1c1917; text-align: center; font-weight: bold; padding: 8px; border-radius: 6px; margin-bottom: 20px; letter-spacing: 0.08em; text-transform: uppercase; font-size: 12px; }
        .score-hero { display: flex; align-items: baseline; gap: 16px; flex-wrap: wrap; }
        .score-big { font-size: 52px; font-weight: bold; }
        .scores-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; }
        .score-tile { border: 1px solid #e7e5e4; border-radius: 6px; padding: 12px; text-align: center; }
        .score-tile .value { font-size: 22px; font-weight: bold; }
        .score-tile .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
        .risk { border-top: 1px solid #e7e5e4; padding: 14px 0; }
        .risk-head { display: flex; gap: 10px; align-items: center; }
        .badge { font-size: 10px; font-weight: bold; padding: 3px 8px; border-radius: 99px; text-transform: uppercase; }
        .badge-high { background: #fee2e2; color: #b91c1c; }
        .badge-medium { background: #fef3c7; color: #b45309; }
        .badge-low { background: #ecfccb; color: #4d7c0f; }
        .risk-title { font-weight: bold; }
        .risk-detail { margin-top: 8px; color: #44403c; }
        .locked-block { position: relative; margin-top: 8px; }
        .locked-blur { filter: blur(5px); user-select: none; pointer-events: none; color: #44403c; }
        .lock-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
        .lock-pill { background: #1c1917; color: #fafaf9; font-size: 12px; padding: 6px 14px; border-radius: 99px; }
        .cta-card { text-align: center; background: #1c1917; color: #fafaf9; }
        .cta-card h2 { color: #fafaf9; }
        .btn { display: inline-block; background: #d4a853; color: #1c1917; font-weight: bold; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 6px; }
        .btn-ghost { background: transparent; color: #fafaf9; border: 1px solid #57534e; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
    </style>
</head>
<body>
@php($payload = $report->payload)
<div class="page">
    @if ($isSample)
        <div class="sample-banner">{{ __('Sample report') }} — {{ __('this is what every FlexPick audit looks like') }}</div>
    @endif

    <div class="card">
        <h1>{{ __('Codebase Health Report') }}</h1>
        <p class="muted">{{ $report->auditRequest->repo_url }} · {{ $report->created_at->format('Y-m-d') }}</p>
        <div class="score-hero">
            <span class="score-big">{{ $payload['scores']['overall'] }}</span>
            <span class="muted">{{ __('overall health, 0–100 (higher is healthier)') }}</span>
        </div>
        @if ($percentile !== null)
            <p class="muted">{{ __('This codebase scores better than :p% of repositories we\'ve audited.', ['p' => $percentile]) }}</p>
        @endif
        <p style="margin-top: 14px;">{{ $payload['summary'] }}</p>
    </div>

    <div class="card">
        <h2>{{ __('Health scores') }}</h2>
        <div class="scores-grid">
            @foreach ($payload['scores'] as $dimension => $score)
                @continue($dimension === 'overall')
                <div class="score-tile">
                    <div class="value">{{ $score }}</div>
                    <div class="label">{{ str_replace('_', ' ', $dimension) }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2>{{ __('Risks, ranked by impact') }}</h2>
        @foreach (collect($payload['risks'])->sortBy(fn ($r) => array_search($r['impact'], ['high', 'medium', 'low'])) as $risk)
            <div class="risk">
                <div class="risk-head">
                    <span class="badge badge-{{ $risk['impact'] }}">{{ $risk['impact'] }}</span>
                    <span class="risk-title">{{ $risk['title'] }}</span>
                </div>
                @if ($unlocked)
                    <div class="risk-detail">
                        <div><strong>{{ __('Evidence') }}:</strong> {{ $risk['evidence'] }}</div>
                        <div style="margin-top: 4px;"><strong>{{ __('Recommendation') }}:</strong> {{ $risk['recommendation'] }}</div>
                    </div>
                @else
                    <div class="locked-block">
                        <div class="locked-blur">
                            <div><strong>{{ __('Evidence') }}:</strong> {{ str_repeat('█▌ ', 14) }}</div>
                            <div style="margin-top: 4px;"><strong>{{ __('Recommendation') }}:</strong> {{ str_repeat('█▌ ', 18) }}</div>
                        </div>
                        <div class="lock-overlay"><span class="lock-pill">🔒 {{ __('Unlock to read') }}</span></div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($unlocked)
        <div class="card">
            <h2>{{ __('What to fix first') }}</h2>
            <table>
                <tr><th>#</th><th>{{ __('Step') }}</th><th>{{ __('Why') }}</th><th>{{ __('Effort') }}</th></tr>
                @foreach ($payload['fix_first_plan'] as $i => $step)
                    <tr><td>{{ $i + 1 }}</td><td>{{ $step['step'] }}</td><td>{{ $step['why'] }}</td><td>{{ $step['effort'] }}</td></tr>
                @endforeach
            </table>
            @if (! $isSample && $report->pdf_path !== null)
                <p style="margin-top: 16px;"><a href="{{ route('reports.download', ['auditReport' => $report->uuid]) }}">{{ __('Download PDF') }}</a></p>
            @endif
        </div>
    @else
        <div class="card cta-card">
            <h2>{{ __('Unlock full report') }}</h2>
            <p style="color: #d6d3d1;">{{ __('Get every finding\'s evidence and recommendation, the prioritized fix-first plan, and PDF export.') }}</p>
            <a class="btn" href="{{ url('/reports/'.$report->uuid.'/unlock') }}">{{ __('Unlock for $5') }}</a>
            <a class="btn btn-ghost" href="{{ url('/pricing') }}">{{ __('Or subscribe from $10/mo') }}</a>
        </div>
    @endif

    <p class="muted" style="text-align: center;">
        {{ __('Scores and findings are derived from automated static analysis at generation time. Reply to your report email to discuss any finding with an engineer.') }}
    </p>
</div>
</body>
</html>
```

`report-ready.blade.php` — change only the copy around the existing button/link: subject line stays; body text becomes "Your codebase health report is ready — view it online:" with the button label `{{ __('View my report') }}` (keep the existing `$reportUrl`/variable wiring exactly as-is).

`AuditRequestResource` — add the grant-unlock action after `launch`:

```php
                Action::make('grantUnlock')
                    ->label(__('Grant full unlock'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->report()->first()?->unlocked_at === null && $record->report()->exists())
                    ->action(fn (AuditRequest $record) => app(AuditReportService::class)->unlock($record->report()->first())),
```

(import `App\Services\AuditReport\AuditReportService`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditReportPageTest|AuditReportControllerTest|AuditRequestResourceTest' --compact`
Expected: PASS. (If `AuditReportControllerTest` asserted the old PDF blade content on `show`, update those assertions to the new web view.)

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/routes backend/resources backend/tests
git commit -m "feat(backend): web report page with locked sections, sample report, grant-unlock action"
```

---

### Task 10: $5 unlock purchase flow — unlock route + intent + Ordered listener

**Files:**
- Modify: `backend/app/Http/Controllers/AuditReportController.php`
- Modify: `backend/routes/web.php`
- Create: `backend/app/Listeners/Order/HandleAuditUnlockOrder.php`
- Test: `backend/tests/Feature/Listeners/HandleAuditUnlockOrderTest.php`
- Test: add cases to `backend/tests/Feature/Http/Controllers/AuditReportPageTest.php`

**Interfaces:**
- Consumes: `AuditReportService::unlock()` (Task 8); `App\Events\Order\Ordered` (`public Order $order`); `Order::items()` (HasMany `OrderItem`, `one_time_product_id`); `App\Models\OneTimeProduct` (slug); `UserParameter`; config `audit.unlock_product_slug`. Listener auto-discovery: any class in `app/Listeners/**` with a type-hinted `handle()` — **no registration file to edit.**
- Produces:
  - Route `GET /reports/{auditReport:uuid}/unlock`, name `reports.unlock`, middleware `auth`.
  - `AuditReportController::unlock()` — claims unowned reports when emails match; stores intent; redirects to `route('buy.product', ['productSlug' => config('audit.unlock_product_slug')])`.
  - Intent storage: `UserParameter` row `name = 'audit_unlock_intent'`, `value = <report uuid>` (constant `HandleAuditUnlockOrder::INTENT_PARAM`).
  - Listener: on `Ordered`, if the order contains the unlock product → unlock the intended report (fallback: buyer's latest locked report), delete the intent row.
- **Spec deviation (documented):** the spec mentions prefilling the email on registration; we rely on Laravel's standard `auth` redirect-to-intended instead. No prefill in v1.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/Listeners/HandleAuditUnlockOrderTest.php`:

```php
<?php

namespace Tests\Feature\Listeners;

use App\Events\Order\Ordered;
use App\Models\AuditReport;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\User;
use App\Models\UserParameter;
use App\Listeners\Order\HandleAuditUnlockOrder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class HandleAuditUnlockOrderTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function unlockOrderFor(User $user): Order
    {
        $product = OneTimeProduct::factory()->create(['slug' => config('audit.unlock_product_slug')]);
        $order = Order::factory()->create(['user_id' => $user->id]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 500,
            'price_per_unit_after_discount' => 500,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    public function test_unlock_order_unlocks_intended_report(): void
    {
        $user = User::factory()->create();
        $reportA = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $reportB = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM, 'value' => $reportA->uuid]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $this->assertNotNull($reportA->refresh()->unlocked_at);
        $this->assertNull($reportB->refresh()->unlocked_at);
        $this->assertSame($reportA->unlock_order_id, Order::latest('id')->value('id'));
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM]);
    }

    public function test_without_intent_falls_back_to_latest_locked_report(): void
    {
        $user = User::factory()->create();
        AuditReport::factory()->locked()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $latest = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $this->assertNotNull($latest->refresh()->unlocked_at);
    }

    public function test_non_unlock_orders_are_ignored(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $product = OneTimeProduct::factory()->create(['slug' => 'something-else']);
        $order = Order::factory()->create(['user_id' => $user->id]);
        $order->items()->create([
            'one_time_product_id' => $product->id, 'quantity' => 1, 'currency_id' => $order->currency_id,
            'price_per_unit' => 100, 'price_per_unit_after_discount' => 100, 'discount_per_unit' => 0,
        ]);

        Ordered::dispatch($order);

        $this->assertNull($report->refresh()->unlocked_at);
    }
}
```

**Note:** if `OneTimeProduct`/`Order` factories don't exist or require other fields, inspect `database/factories/` for them (SaaSykit ships factories for its core models) and adapt creation minimally — keep the assertions identical.

Add to `AuditReportPageTest.php`:

```php
    public function test_unlock_route_stores_intent_and_redirects_to_checkout(): void
    {
        $user = \App\Models\User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get("/reports/{$report->uuid}/unlock")
            ->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));

        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'name' => \App\Listeners\Order\HandleAuditUnlockOrder::INTENT_PARAM,
            'value' => $report->uuid,
        ]);
    }

    public function test_unlock_route_claims_report_by_matching_email(): void
    {
        $user = \App\Models\User::factory()->create(['email' => 'match@example.com']);
        $report = AuditReport::factory()->locked()->create(['user_id' => null]);
        $report->auditRequest->update(['email' => 'match@example.com']);

        $this->actingAs($user)->get("/reports/{$report->uuid}/unlock")->assertRedirect();

        $this->assertSame($user->id, $report->refresh()->user_id);
    }

    public function test_unlock_route_denies_foreign_reports(): void
    {
        $user = \App\Models\User::factory()->create(['email' => 'other@example.com']);
        $report = AuditReport::factory()->locked()->create(['user_id' => \App\Models\User::factory()->create()->id]);

        $this->actingAs($user)->get("/reports/{$report->uuid}/unlock")->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter='HandleAuditUnlockOrderTest|AuditReportPageTest' --compact`
Expected: FAIL — listener class not found / route not defined.

- [ ] **Step 3: Implement**

`AuditReportController::unlock()`:

```php
    public function unlock(AuditReport $auditReport)
    {
        $user = auth()->user();

        if ($auditReport->user_id === null && $auditReport->auditRequest->email === $user->email) {
            $auditReport->update(['user_id' => $user->id]);
        }

        abort_unless($auditReport->user_id === $user->id, 403);

        if ($auditReport->unlocked_at !== null) {
            return redirect(app(AuditReportService::class)->signedUrl($auditReport));
        }

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM],
            ['value' => $auditReport->uuid],
        );

        return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
    }
```

(imports: `App\Listeners\Order\HandleAuditUnlockOrder`, `App\Models\UserParameter`, `App\Services\AuditReport\AuditReportService`.)

`routes/web.php` — after the `reports.download` route:

```php
Route::get('/reports/{auditReport:uuid}/unlock', [AuditReportController::class, 'unlock'])
    ->name('reports.unlock')
    ->middleware('auth');
```

`backend/app/Listeners/Order/HandleAuditUnlockOrder.php`:

```php
<?php

namespace App\Listeners\Order;

use App\Events\Order\Ordered;
use App\Models\AuditReport;
use App\Models\OneTimeProduct;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditReportService;

class HandleAuditUnlockOrder
{
    public const INTENT_PARAM = 'audit_unlock_intent';

    public function __construct(
        private AuditReportService $reportService,
    ) {}

    public function handle(Ordered $event): void
    {
        $order = $event->order;

        $productIds = $order->items()->pluck('one_time_product_id')->filter();
        $hasUnlockProduct = OneTimeProduct::query()
            ->whereIn('id', $productIds)
            ->where('slug', config('audit.unlock_product_slug'))
            ->exists();

        if (! $hasUnlockProduct || $order->user_id === null) {
            return;
        }

        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        $report = null;
        if ($intent !== null) {
            $report = AuditReport::query()->where('uuid', $intent->value)->first();
        }
        $report ??= AuditReport::query()
            ->where('user_id', $order->user_id)
            ->whereNull('unlocked_at')
            ->latest()
            ->first();

        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }

        $intent?->delete();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='HandleAuditUnlockOrderTest|AuditReportPageTest' --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/routes backend/tests
git commit -m "feat(backend): \$5 report unlock purchase flow via one-time product order listener"
```

---

### Task 11: Benchmark percentile service + report page wiring

**Files:**
- Create: `backend/app/Services/AuditReport/AuditBenchmarkService.php`
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (show)
- Test: `backend/tests/Feature/Services/AuditBenchmarkServiceTest.php`

**Interfaces:**
- Consumes: `audit_reports.payload` (`scores.overall`), config `audit.benchmark_min_sample`, Laravel cache.
- Produces: `percentileFor(int $overallScore): ?int` — null when total reports < min sample; else `(int) round(100 * countBelow / total)`. Cache key `audit-benchmark-overall-scores`, TTL 3600s.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditBenchmarkService;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\FeatureTest;

class AuditBenchmarkServiceTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function seedScores(array $scores): void
    {
        foreach ($scores as $score) {
            $payload = AuditReport::factory()->raw()['payload'];
            $payload['scores']['overall'] = $score;
            AuditReport::factory()->create(['payload' => $payload]);
        }
    }

    public function test_returns_null_below_min_sample(): void
    {
        config(['audit.benchmark_min_sample' => 5]);
        $this->seedScores([10, 20, 30]);

        $this->assertNull(app(AuditBenchmarkService::class)->percentileFor(25));
    }

    public function test_computes_percentile(): void
    {
        config(['audit.benchmark_min_sample' => 4]);
        $this->seedScores([10, 20, 30, 40]);

        // 2 of 4 scores are below 35 → 50th percentile
        $this->assertSame(50, app(AuditBenchmarkService::class)->percentileFor(35));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditBenchmarkServiceTest --compact`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditReport;
use Illuminate\Support\Facades\Cache;

class AuditBenchmarkService
{
    public function percentileFor(int $overallScore): ?int
    {
        $scores = Cache::remember('audit-benchmark-overall-scores', 3600, function (): array {
            return AuditReport::query()
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
}
```

Wire into `AuditReportController::show()`:

```php
    public function show(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => $benchmark->percentileFor((int) data_get($auditReport->payload, 'scores.overall', 0)),
        ]);
    }
```

(import `App\Services\AuditReport\AuditBenchmarkService`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditBenchmarkServiceTest|AuditReportPageTest' --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/tests
git commit -m "feat(backend): benchmark percentile on audit report pages"
```

---

### Task 12: Monetization seeder — unlock product + subscription plans

**Files:**
- Create: `backend/database/seeders/AuditMonetizationSeeder.php`
- Test: `backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php`

**Interfaces:**
- Consumes: `OneTimeProduct` (+`prices()`), `Product`, `Plan`, `PlanPrice`, `Currency` (USD), `Interval` (slug `month` — check `app/Models/Interval.php` / existing seeders for the exact slug; `DemoDatabaseSeeder` references it), `PlanType::FLAT_RATE`.
- Produces (exact slugs/values later tasks + ops rely on):
  - OneTimeProduct slug `audit-report-unlock`, name "Full audit report unlock", price **500** USD, `max_quantity` 1, active + visible.
  - Products: `audit-starter` / `audit-growth` / `audit-scale`, each `metadata => ['audit_analyses_per_month' => 5|20|50]`.
  - Plans (type `flat_rate`, monthly interval, active + visible): `audit-starter-monthly` ($10 → **1000**), `audit-growth-monthly` ($30 → **3000**), `audit-scale-monthly` ($60 → **6000**).
  - Idempotent: `updateOrCreate` on slugs — safe to re-run in production.
- **Ops note (include in final PR description):** run `php artisan db:seed --class=AuditMonetizationSeeder` on deploy, then attach payment-provider data via the Filament admin (provider price IDs are provider-side, not seedable).

- [ ] **Step 1: Write the failing test**

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
    public function test_seeds_unlock_product_and_plans_idempotently(): void
    {
        $this->seed(AuditMonetizationSeeder::class);
        $this->seed(AuditMonetizationSeeder::class); // idempotent

        $unlock = OneTimeProduct::where('slug', 'audit-report-unlock')->firstOrFail();
        $this->assertSame(500, $unlock->prices()->firstOrFail()->price);
        $this->assertSame(1, OneTimeProduct::where('slug', 'audit-report-unlock')->count());

        foreach ([['audit-starter', 5, 1000], ['audit-growth', 20, 3000], ['audit-scale', 50, 6000]] as [$slug, $allowance, $price]) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $this->assertSame($allowance, $product->metadata['audit_analyses_per_month']);

            $plan = Plan::where('slug', $slug.'-monthly')->firstOrFail();
            $this->assertSame($product->id, $plan->product_id);
            $this->assertSame($price, $plan->prices()->firstOrFail()->price);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditMonetizationSeederTest --compact`
Expected: FAIL — seeder class not found.

- [ ] **Step 3: Implement**

`backend/database/seeders/AuditMonetizationSeeder.php` — **before writing, open `database/seeders/Demo/DemoDatabaseSeeder.php` and mirror exactly how it resolves Currency/Interval and creates products, plans, and prices** (method `createOneTimeProduct` and the plan-creation blocks around lines 110–275). Reference implementation:

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

class AuditMonetizationSeeder extends Seeder
{
    public function run(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();

        $unlock = OneTimeProduct::updateOrCreate(['slug' => 'audit-report-unlock'], [
            'name' => 'Full audit report unlock',
            'description' => 'Unlock every finding, recommendation, and the fix-first plan of one codebase audit report, including PDF export.',
            'max_quantity' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $unlock->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => 500]);

        $tiers = [
            ['slug' => 'audit-starter', 'name' => 'Audit Starter', 'allowance' => 5, 'price' => 1000],
            ['slug' => 'audit-growth', 'name' => 'Audit Growth', 'allowance' => 20, 'price' => 3000],
            ['slug' => 'audit-scale', 'name' => 'Audit Scale', 'allowance' => 50, 'price' => 6000],
        ];

        foreach ($tiers as $tier) {
            $product = Product::updateOrCreate(['slug' => $tier['slug']], [
                'name' => $tier['name'],
                'description' => $tier['allowance'].' codebase analyses per month, fully detailed with PDF export.',
                'metadata' => ['audit_analyses_per_month' => $tier['allowance']],
                'is_default' => false,
            ]);

            $plan = Plan::updateOrCreate(['slug' => $tier['slug'].'-monthly'], [
                'name' => $tier['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => $tier['price']]);
        }
    }
}
```

**If any column name mismatches surface (e.g. `Product` has no `is_default` default, `Interval` slug differs), fix by copying the exact usage from `DemoDatabaseSeeder` — that file is the source of truth for these models' required fields.**

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditMonetizationSeederTest --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/database/seeders backend/tests
git commit -m "feat(backend): seeder for audit unlock product and subscription plans"
```

---

### Task 13: Subscription entitlement + dashboard run/re-run + trends

**Files:**
- Modify: `backend/app/Services/AuditReport/AuditEntitlementService.php`
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Test: `backend/tests/Feature/Services/AuditSubscriptionEntitlementTest.php`
- Test: `backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php`

**Interfaces:**
- Consumes: `SubscriptionService::findActiveTenantSubscriptions(?Tenant): Collection` (of `Subscription`, each with `plan->product->metadata`); product metadata key `audit_analyses_per_month` (Task 12); `AuditReportService::create` unlocking `dashboard` source (Task 8); `GenerateAuditReport`.
- Produces:
  - `subscriptionAllowance(Tenant $tenant): int` — max `audit_analyses_per_month` across active subs (0 if none).
  - `dashboardRunsUsedThisMonth(User $user): int` — `audit_requests` where `user_id`, `source = 'dashboard'`, `created_at >= now()->startOfMonth()`.
  - `remainingDashboardRuns(User $user, Tenant $tenant): int` — `max(0, allowance - used)`.
  - Dashboard page: Livewire property `public ?string $repoUrl = null;` + method `launchAudit(?string $repoUrl = null): void` (used by both the new-audit form and per-repo "Re-run" buttons) — validates URL, checks remaining runs, creates a verified `dashboard`-source `AuditRequest` (status QUEUED), dispatches the job, flashes a Filament notification.
  - View data adds: `remainingRuns` (int), `allowance` (int), `repoGroups` — reports grouped by `auditRequest.repo_url`, each with score history for sparklines.
- **Deliberate simplifications (documented):** usage counts per **user**, not per tenant (solo-customer product; multi-user tenants could theoretically stack per-user quotas — acceptable v1); the month window is **calendar month**, not billing anchor.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/Services/AuditSubscriptionEntitlementTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditSubscriptionEntitlementTest extends FeatureTest
{
    public function test_allowance_is_zero_without_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->assertSame(0, app(AuditEntitlementService::class)->subscriptionAllowance($tenant));
        $this->assertSame(0, app(AuditEntitlementService::class)->remainingDashboardRuns($user, $tenant));
    }

    public function test_allowance_reads_product_metadata_of_active_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        $this->assertSame(5, app(AuditEntitlementService::class)->subscriptionAllowance($tenant));
    }

    public function test_dashboard_runs_this_month_reduce_remaining(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        AuditRequest::factory()->count(2)->dashboardSource()->create(['user_id' => $user->id]);
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'created_at' => now()->subMonths(2)]);
        AuditRequest::factory()->create(['user_id' => $user->id]); // web source — doesn't count

        $service = app(AuditEntitlementService::class);
        $this->assertSame(2, $service->dashboardRunsUsedThisMonth($user));
        $this->assertSame(3, $service->remainingDashboardRuns($user, $tenant));
    }
}
```

**Test helpers:** `createTenantFor` / `createActiveSubscriptionFor` don't exist yet — before writing them, grep the existing suite for how it builds tenants and active subscriptions (`grep -rn "Subscription::factory\|SubscriptionStatus::ACTIVE\|Tenant::factory" backend/tests | head -20`) and copy that pattern into private helpers in this test class. An active subscription needs: `tenant_id`, `user_id`, `status = ACTIVE`, `ends_at` in the future, and a `plan` whose `product.metadata` carries the allowance.

`backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class AuditReportsPageTest extends FeatureTest
{
    public function test_launch_audit_creates_verified_dashboard_request_and_dispatches(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Dashboard\Pages\AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $request = AuditRequest::firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertNotNull($request->email_verified_at);
        $this->assertFalse($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_launch_audit_refuses_without_remaining_runs(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $this->createTenantFor($user); // no subscription

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Dashboard\Pages\AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $this->assertSame(0, AuditRequest::count());
        Queue::assertNotPushed(GenerateAuditReport::class);
    }
}
```

(Same tenant/subscription helpers; also note the dashboard panel is tenant-scoped — if `Livewire::test` on the page requires Filament tenant context, mirror how existing dashboard-page tests in the suite set it, e.g. `Filament::setTenant($tenant)`.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter='AuditSubscriptionEntitlementTest|AuditReportsPageTest' --compact`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Implement**

Add to `AuditEntitlementService` (constructor gains `private SubscriptionService $subscriptionService`; imports `App\Models\Tenant`, `App\Services\SubscriptionService`):

```php
    public function subscriptionAllowance(Tenant $tenant): int
    {
        return (int) $this->subscriptionService->findActiveTenantSubscriptions($tenant)
            ->map(fn ($subscription): int => (int) data_get($subscription->plan?->product?->metadata, 'audit_analyses_per_month', 0))
            ->max();
    }

    public function dashboardRunsUsedThisMonth(User $user): int
    {
        return AuditRequest::query()
            ->where('user_id', $user->id)
            ->where('source', 'dashboard')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingDashboardRuns(User $user, Tenant $tenant): int
    {
        return max(0, $this->subscriptionAllowance($tenant) - $this->dashboardRunsUsedThisMonth($user));
    }
```

`AuditReports` page — full new class body:

```php
<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

    public ?string $repoUrl = null;

    public function getHeading(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && auth()->user()->auditReports()->exists();
    }

    public function launchAudit(?string $repoUrl = null): void
    {
        $repoUrl ??= $this->repoUrl;
        $user = auth()->user();
        $entitlements = app(AuditEntitlementService::class);

        if ($repoUrl === null || ! str_starts_with($repoUrl, 'http')) {
            Notification::make()->title(__('Enter a valid repository URL'))->danger()->send();

            return;
        }

        $tenant = Filament::getTenant();
        if ($tenant === null || $entitlements->remainingDashboardRuns($user, $tenant) < 1) {
            Notification::make()->title(__('No analyses left this month'))->body(__('Upgrade your plan to run more audits.'))->warning()->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::QUEUED->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'user_id' => $user->id,
        ]);

        GenerateAuditReport::dispatch($auditRequest);
        $this->repoUrl = null;

        Notification::make()->title(__('Audit started'))->body(__('You\'ll get an email when the report is ready.'))->success()->send();
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $reports = $user->auditReports()->with('auditRequest')->latest()->get();

        return [
            'reports' => $reports,
            'allowance' => $tenant ? $entitlements->subscriptionAllowance($tenant) : 0,
            'remainingRuns' => $tenant ? $entitlements->remainingDashboardRuns($user, $tenant) : 0,
            'repoGroups' => $reports
                ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
                ->map(fn ($group) => [
                    'reports' => $group,
                    'scores' => $group->reverse()->values()->map(fn ($r) => (int) data_get($r->payload, 'scores.overall', 0)),
                ]),
        ];
    }
}
```

Blade `audit-reports.blade.php` — extend the existing view (keep its report-list markup; the exact existing content should be preserved and augmented). Add at the top, inside the page wrapper:

```blade
    @if ($allowance > 0)
        <x-filament::section class="mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow">
                    <label class="text-sm font-medium" for="audit-repo-url">{{ __('Repository URL') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="audit-repo-url" type="url" wire:model="repoUrl" placeholder="https://github.com/you/repo" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button wire:click="launchAudit">{{ __('Run new audit') }}</x-filament::button>
            </div>
            <p class="mt-2 text-sm text-gray-500">
                {{ __(':remaining of :allowance analyses left this month', ['remaining' => $remainingRuns, 'allowance' => $allowance]) }}
            </p>
        </x-filament::section>
    @endif

    @foreach ($repoGroups as $repoUrl => $group)
        @if ($group['scores']->count() > 1)
            <x-filament::section class="mb-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ $repoUrl }}</p>
                        <p class="text-sm text-gray-500">{{ __('Health trend across :n audits', ['n' => $group['scores']->count()]) }}</p>
                    </div>
                    @php
                        $scores = $group['scores'];
                        $max = max(1, $scores->max());
                        $points = $scores->map(fn ($s, $i) => ($i * (120 / max(1, $scores->count() - 1))).','.(36 - ($s / $max) * 32))->implode(' ');
                    @endphp
                    <div class="flex items-center gap-3">
                        <svg width="120" height="40" viewBox="0 0 120 40" fill="none">
                            <polyline points="{{ $points }}" stroke="currentColor" stroke-width="2" class="text-primary-500" />
                        </svg>
                        @if ($allowance > 0)
                            <x-filament::button size="sm" color="gray" wire:click="launchAudit('{{ $repoUrl }}')">{{ __('Re-run') }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endforeach
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter='AuditSubscriptionEntitlementTest|AuditReportsPageTest' --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app backend/resources/views backend/tests
git commit -m "feat(backend): subscriber dashboard audits with monthly allowance and repo trends"
```

---

### Task 14: Referral bonus listener

**Files:**
- Create: `backend/app/Listeners/Referral/GrantAuditBonusOnReferral.php`
- Test: `backend/tests/Feature/Listeners/GrantAuditBonusOnReferralTest.php`

**Interfaces:**
- Consumes: `App\Events\Referral\ReferralSucceeded` (`public User $referrer, public User $referredUser, public Referral $referral`); `AuditEntitlementService::BONUS_PARAM`; `UserParameter`. Auto-discovered — no registration.
- Produces: each `ReferralSucceeded` increments the referrer's `audit_bonus_free_runs` parameter by 1.
- **Config note for the final PR:** `ReferralSucceeded` only fires when `app.referral.reward_type` is `custom_event` — the deployment must set that (env) for the bonus to flow. Document, don't change defaults.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Listeners;

use App\Events\Referral\ReferralSucceeded;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class GrantAuditBonusOnReferralTest extends FeatureTest
{
    public function test_referral_success_grants_bonus_run(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'testcode',
            'status' => 'rewarded',
        ]);

        ReferralSucceeded::dispatch($referrer, $referred, $referral);
        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $value = UserParameter::query()
            ->where('user_id', $referrer->id)
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->value('value');

        $this->assertSame('2', $value);
        $this->assertSame(5, app(AuditEntitlementService::class)->freeRunsLimit($referrer->email));
    }
}
```

(If `Referral::create` needs different required fields, check `app/Models/Referral.php` fillable — `referrer_user_id, referred_user_id, referral_code, status, verified_at, paid_at, rewarded_at` — and the `referrals` migration for NOT NULL columns; adjust minimally.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GrantAuditBonusOnReferralTest --compact`
Expected: FAIL — bonus parameter absent (listener missing).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Listeners\Referral;

use App\Events\Referral\ReferralSucceeded;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;

class GrantAuditBonusOnReferral
{
    public function handle(ReferralSucceeded $event): void
    {
        $parameter = UserParameter::firstOrCreate(
            ['user_id' => $event->referrer->id, 'name' => AuditEntitlementService::BONUS_PARAM],
            ['value' => '0'],
        );

        $parameter->update(['value' => (string) ((int) $parameter->value + 1)]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GrantAuditBonusOnReferralTest --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add backend/app/Listeners/Referral backend/tests/Feature/Listeners
git commit -m "feat(backend): referral success grants a bonus free audit run"
```

---

### Task 15: Frontend — consent checkbox, verification copy, invite hint, sample link

**Files:**
- Modify: `frontend/src/components/widgets/ContactModal.astro`
- Modify: `frontend/src/pages/index.astro`

**Interfaces:**
- Consumes: backend accepts `marketing_consent` boolean (Task 4); `/reports/sample` exists (Task 9); `PRODUCT_APP.url` config.
- Produces: form posts `marketing_consent`; new copy; landing links to the sample report.

- [ ] **Step 1: Implement ContactModal changes**

In the form (after the message field-group, before the error `<p>`), add:

```astro
        <details class="fp-field-group" style="font-size: 12px; color: rgba(232,230,222,0.55);">
          <summary class="fp-mono" style="cursor: pointer; color: #d4a853;">PRIVATE REPO?</summary>
          <p style="margin: 8px 0 0; line-height: 1.5;">
            Invite <strong>flexpick-audit</strong> on GitHub as a read-only collaborator (Settings → Collaborators →
            Add people), then paste the repo URL above. We launch private-repo audits by hand after the invite lands.
          </p>
        </details>
        <label class="fp-field-group" style="display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: rgba(232,230,222,0.55); cursor: pointer;">
          <input type="checkbox" name="consent" style="margin-top: 2px;" />
          <span>Send me occasional tips and product updates. No spam, unsubscribe anytime.</span>
        </label>
```

Change the footnote line under the submit button:

```astro
        <p class="fp-mono fp-modal-footnote">FIRST 3 AUDITS FREE · CONFIRM YOUR EMAIL TO START · HONEST VERDICT</p>
```

In the submit handler's `JSON.stringify` body, add:

```js
            marketing_consent: fd.get('consent') === 'on',
```

Update the sent-state copy:

```astro
      <h3 class="fp-modal-heading" style="margin-bottom: 10px;">Check your inbox</h3>
      <p class="fp-modal-sub" style="max-width: 320px; margin: 0 auto 28px;">
        We sent you a confirmation link — click it to start your free audit. The link expires in 48 hours.
      </p>
```

- [ ] **Step 2: Add the sample-report link to the landing page**

In `frontend/src/pages/index.astro`, locate the hero CTA cluster (the `data-audit-open` button around line 121). Directly after that button, add a secondary link:

```astro
          <a
            class="fp-ghost"
            href={`${PRODUCT_APP.url}/reports/sample`}
            target="_blank"
            rel="noopener"
            style="font-size: 14px; align-self: center;"
          >
            See a sample audit →
          </a>
```

(Import `PRODUCT_APP` from `flexpick:config` in the frontmatter if the page doesn't already; check the top of the file first.)

- [ ] **Step 3: Verify**

Run: `cd frontend && npm run check`
Expected: astro check + eslint + prettier all pass.

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 4: Commit**

```bash
git add frontend/src
git commit -m "feat(frontend): consent checkbox, verification copy, private-repo invite hint, sample report link"
```

---

### Task 16: Full verification sweep

**Files:** none new — verification only (fix anything that surfaces, in place).

- [ ] **Step 1: Full backend test suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: all tests pass (baseline 427 + all new ones). **Read the output; ignore the exit code.** Fix any failures before proceeding.

- [ ] **Step 2: Static analysis**

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no errors. Common fixups: PHPDoc generics on new relations (`BelongsTo<User, $this>` on `AuditRequest::user()`), nullable handling on `pdf_path`.

- [ ] **Step 3: Pint over everything**

Run: `docker compose exec laravel.test vendor/bin/pint`
Expected: clean (or auto-fixed — commit any fixes).

- [ ] **Step 4: Frontend check** (again, in case of rebases)

Run: `cd frontend && npm run check`
Expected: pass.

- [ ] **Step 5: Migration sanity on fresh DB**

Run: `docker compose exec laravel.test php artisan migrate:fresh --seed`
Expected: completes without error.
Then: `docker compose exec laravel.test php artisan db:seed --class=AuditMonetizationSeeder`
Expected: completes; re-run once more to confirm idempotency.

- [ ] **Step 6: Commit any fixes**

```bash
docker compose exec laravel.test vendor/bin/pint --dirty
git add -A backend frontend
git commit -m "chore: verification sweep fixes for audit freemium intake"
```

(Skip the commit if the tree is clean.)

---

## Deployment / ops checklist (include in the PR description)

1. Create the dedicated GitHub account `flexpick-audit`; generate a fine-grained PAT (read-only contents); set `AUDIT_GITHUB_ACCOUNT` / `AUDIT_GITHUB_TOKEN` in production `.env`.
2. Run `php artisan db:seed --class=AuditMonetizationSeeder`, then attach payment-provider price IDs to the new product/plans in the Filament admin.
3. Set `app.referral.reward_type` to `custom_event` (env) if the referral bonus should be active.
4. The scheduler must be running (it already is for existing `app:*` commands) for the unverified-request purge.
5. Existing pending `AuditRequest` rows keep working — new columns default to unverified/web/no-consent; old statuses are untouched.
