# Phase 9A-1 — In-repo Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give FlexPick health checks, worker/scheduler liveness alerting, self-hosted error tracking, and a deploy smoke gate — everything in §20.3 PR4/PR5/PR6 that lives inside the repository.

**Architecture:** `spatie/laravel-health` owns check execution and result storage. Three custom checks answer audit-domain questions the built-ins cannot. A thin controller serves stored results and returns 503 when a paging-band check fails *or when the results themselves go stale* — the stale arm is a dead-man's switch that makes a dead scheduler audible to Ploi's off-box monitor. A separate scheduled command owns alert dispatch, so throttling and recovery detection behave exactly as specified.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 11, `spatie/laravel-health` ^1.40, `sentry/sentry-laravel`, Redis/Horizon, MySQL.

**Spec:** `docs/superpowers/specs/2026-08-02-launch-blocking-operations-design.md`

## Global Constraints

- All work happens in `/var/www/html/flexpick.net/backend`. Never `cd` to the repo root to run PHP tooling.
- **PHPUnit, not Pest.** Create tests with `php artisan make:test --phpunit {Name}`. The Pest snippets in `backend/AGENTS.md` must be translated before use.
- Feature tests extend `Tests\Feature\FeatureTest`. **It does not roll back the database** — no `RefreshDatabase`, no transactions. It runs `migrate:fresh` + seeds once per process. Any test that counts rows must clear its tables in `setUp()`.
- `FeatureTest::setUp()` calls `withoutExceptionHandling()`. To assert a 404/503 *response* rather than catch an exception, call `$this->withExceptionHandling();` first in that test.
- `TestingDatabaseSeeder` seeds no audit data, so clearing `audit_requests` / `audit_email_logs` in tests is safe.
- Freeze the clock with `Carbon::setTestNow()` in every time-windowed test. §18.2 T7 already records one flaky time-sensitive test in this suite.
- Every threshold is an `env()`-backed entry in the `flexpick` block of `config/health.php`. No magic numbers in check classes (§14.5, Appendix A).
- Static analysis is Larastan level 3 over `app/` with no baseline. Do not introduce a new error category.
- Gates before every commit that touches PHP: `vendor/bin/pint --dirty --format agent`, then `php artisan test --compact`.
- Never log, transmit, or store a repository access token. §15.1 `[R]`, guarded by test in Task 7.

---

## File Structure

**Create:**
| Path | Responsibility |
| --- | --- |
| `app/Providers/HealthServiceProvider.php` | Registers the check set with `Health::checks()`. Nothing else. |
| `app/Health/Checks/OldestPendingAuditCheck.php` | Age of oldest `queued` and oldest stranded `analyzing` request |
| `app/Health/Checks/AuditPipelineFailureRateCheck.php` | Pipeline failure share over a window |
| `app/Health/Checks/MailFailureRateCheck.php` | Mail failure share over a window |
| `app/Health/FailureRate.php` | Value object: total, failed, floor → percent / below-floor. Shared by both rate checks. |
| `app/Http/Controllers/HealthResultsController.php` | Serves stored results; band + staleness → status code; token guard |
| `app/Http/Controllers/HealthReadinessController.php` | Request-time DB + cache probe. No external calls, ever. |
| `app/Notifications/OperationsAlert.php` | One alert, three channel renderings |
| `app/Notifications/Channels/TelegramChannel.php` | Single `Http::post` to the Telegram bot API |
| `app/Notifications/Channels/SlackWebhookChannel.php` | Single `Http::post` to a Slack incoming webhook |
| `app/Console/Commands/DispatchHealthAlerts.php` | Transition detection, throttle, dispatch |
| `app/Support/Sentry/TokenScrubber.php` | Strips repo credentials from outbound Sentry events |
| `app/Console/Commands/SmokeCommand.php` | Post-deploy gate; exit code is the contract |

**Modify:** `composer.json`, `config/health.php` (published), `config/sentry.php` (published), `bootstrap/app.php` (health routes), `bootstrap/providers.php`, `routes/console.php`, `.env.example`, `CLAUDE.md`.

**Why alert dispatch is ours, not spatie's:** the spec requires that a throttle-lookup failure **falls through to sending** (because Redis being down is exactly when the alert matters), plus band-aware message content and guaranteed recovery notifications. Spatie's built-in notification config provides none of those three. We use spatie for check execution and storage only.

---

### Task 1: Install laravel-health, configure thresholds, register the built-in check set

**Files:**
- Modify: `backend/composer.json`
- Create: `backend/config/health.php` (published by the package, then edited)
- Create: `backend/app/Providers/HealthServiceProvider.php`
- Modify: `backend/bootstrap/providers.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Health/HealthCheckRegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('health.flexpick.*')` threshold keys used by every later task; `App\Providers\HealthServiceProvider`; a populated `Spatie\Health\ResultStores\ResultStore`.

- [ ] **Step 1: Install the package and publish its config and migration**

```bash
cd /var/www/html/flexpick.net/backend
composer require spatie/laravel-health:^1.40
php artisan vendor:publish --tag=health-config
php artisan vendor:publish --tag=health-migrations
php artisan migrate
```

Expected: `config/health.php` exists, and a `health_check_result_history_items` migration is created and applied.

- [ ] **Step 2: Confirm the vendor API surface this plan depends on**

```bash
cd /var/www/html/flexpick.net/backend
grep -n "public function latestResults\|public Carbon\|storedCheckResults\|finishedAt" vendor/spatie/laravel-health/src/ResultStores/*.php vendor/spatie/laravel-health/src/ResultStores/StoredCheckResults/*.php
grep -rn "case " vendor/spatie/laravel-health/src/Enums/Status.php
```

Expected: `ResultStore::latestResults()` returns a nullable results object exposing a `finishedAt` Carbon and a `storedCheckResults` collection whose items expose `name`, `status`, `notificationMessage`, `shortSummary`. `Status` enum cases include `ok`, `warning`, `failed`, `crashed`, `skipped`.

If any accessor name differs, use the real name from this output throughout Tasks 3 and 6 — this step exists to make that substitution once, up front, rather than discovering it in three places.

- [ ] **Step 3: Add the `flexpick` block to `config/health.php`**

Append this key inside the array returned by `config/health.php`:

```php
    /*
     * FlexPick operational thresholds. Every value here is the single
     * authoritative entry for that limit (spec §14.5, Appendix A).
     * Check names match Spatie's convention: class name minus the "Check" suffix.
     */
    'flexpick' => [
        'result_freshness_minutes' => (int) env('HEALTH_RESULT_FRESHNESS_MINUTES', 15),
        'endpoint_token' => env('HEALTH_ENDPOINT_TOKEN'),
        'queue_heartbeat_minutes' => (int) env('HEALTH_QUEUE_HEARTBEAT_MINUTES', 10),
        'schedule_heartbeat_minutes' => (int) env('HEALTH_SCHEDULE_HEARTBEAT_MINUTES', 10),
        'disk_fail_percent' => (int) env('HEALTH_DISK_FAIL_PERCENT', 85),
        'oldest_queued_minutes' => (int) env('HEALTH_OLDEST_QUEUED_MINUTES', 30),
        'oldest_analyzing_minutes' => (int) env('HEALTH_OLDEST_ANALYZING_MINUTES', 30),

        'pipeline_failure' => [
            'window_hours' => (int) env('HEALTH_PIPELINE_WINDOW_HOURS', 24),
            'min_samples' => (int) env('HEALTH_PIPELINE_MIN_SAMPLES', 5),
            'fail_percent' => (int) env('HEALTH_PIPELINE_FAIL_PERCENT', 40),
        ],

        'mail_failure' => [
            'window_hours' => (int) env('HEALTH_MAIL_WINDOW_HOURS', 24),
            'min_samples' => (int) env('HEALTH_MAIL_MIN_SAMPLES', 5),
            'fail_percent' => (int) env('HEALTH_MAIL_FAIL_PERCENT', 25),
        ],

        /*
         * Severity bands per spec §15.6. Only bands listed in `paging_bands`
         * affect the /health status code; the rest are reported in the body
         * and alert in-app only. A pager that fires on disk warnings stops
         * being trusted, which costs you the critical alerts too.
         */
        'bands' => [
            'Database' => 'critical',
            'Redis' => 'critical',
            'Horizon' => 'critical',
            'Queue' => 'critical',
            'OldestPendingAudit' => 'critical',
            'AuditPipelineFailureRate' => 'high',
            'MailFailureRate' => 'high',
            'Schedule' => 'medium',
            'UsedDiskSpace' => 'medium',
            'Cache' => 'medium',
        ],
        'paging_bands' => ['critical', 'high'],
        'default_band' => 'medium',

        'alert_channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('HEALTH_ALERT_CHANNELS', 'mail'))
        ))),
        'alert_throttle_minutes' => (int) env('HEALTH_ALERT_THROTTLE_MINUTES', 60),
        'channel_timeout_seconds' => (int) env('HEALTH_CHANNEL_TIMEOUT_SECONDS', 5),
        'telegram' => [
            'bot_token' => env('HEALTH_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('HEALTH_TELEGRAM_CHAT_ID'),
        ],
        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL'),
        ],
        'mail' => [
            'to' => env('HEALTH_ALERT_MAIL_TO'),
        ],
    ],
```

Also set spatie's own notification block to disabled, since Task 6 owns dispatch:

```php
    'notifications' => [
        'enabled' => false,
    ],
```

- [ ] **Step 4: Write the failing test**

Create `backend/tests/Feature/Health/HealthCheckRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use Spatie\Health\Facades\Health;
use Tests\Feature\FeatureTest;

class HealthCheckRegistrationTest extends FeatureTest
{
    public function test_every_specified_check_is_registered(): void
    {
        $registered = collect(Health::registeredChecks())
            ->map(fn ($check) => $check->getName())
            ->sort()
            ->values()
            ->all();

        $expected = [
            'AuditPipelineFailureRate',
            'Cache',
            'Database',
            'Horizon',
            'MailFailureRate',
            'OldestPendingAudit',
            'Queue',
            'Redis',
            'Schedule',
            'UsedDiskSpace',
        ];

        sort($expected);

        $this->assertSame($expected, $registered);
    }

    public function test_every_registered_check_has_a_severity_band(): void
    {
        $bands = config('health.flexpick.bands');

        foreach (Health::registeredChecks() as $check) {
            $this->assertArrayHasKey(
                $check->getName(),
                $bands,
                "Check {$check->getName()} has no band in config('health.flexpick.bands')."
            );
        }
    }

    public function test_paging_bands_exclude_medium(): void
    {
        $this->assertSame(['critical', 'high'], config('health.flexpick.paging_bands'));
        $this->assertNotContains('medium', config('health.flexpick.paging_bands'));
    }
}
```

The second test is the one that earns its keep: it makes it impossible to add a check later without deciding whether it pages.

- [ ] **Step 5: Run the test to verify it fails**

Run: `php artisan test --filter=HealthCheckRegistrationTest`
Expected: FAIL — no checks registered yet, so the name list is empty.

- [ ] **Step 6: Create the service provider**

Create `backend/app/Providers/HealthServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Health\Checks\AuditPipelineFailureRateCheck;
use App\Health\Checks\MailFailureRateCheck;
use App\Health\Checks\OldestPendingAuditCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            HorizonCheck::new(),

            QueueCheck::new()
                ->onQueue((string) config('audit.queue'))
                ->failWhenTestJobTakesLongerThanMinutes(
                    (int) config('health.flexpick.queue_heartbeat_minutes')
                ),

            ScheduleCheck::new()
                ->heartbeatMaxAgeInMinutes(
                    (int) config('health.flexpick.schedule_heartbeat_minutes')
                ),

            UsedDiskSpaceCheck::new()
                ->failWhenUsedSpaceIsAbovePercentage(
                    (int) config('health.flexpick.disk_fail_percent')
                ),

            OldestPendingAuditCheck::new(),
            AuditPipelineFailureRateCheck::new(),
            MailFailureRateCheck::new(),
        ]);
    }
}
```

- [ ] **Step 7: Create placeholder check classes so the provider boots**

The three custom checks get their real bodies in Tasks 4 and 5. Create each now returning a passing result, so Task 1 is independently runnable:

`backend/app/Health/Checks/OldestPendingAuditCheck.php`:

```php
<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class OldestPendingAuditCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}
```

`backend/app/Health/Checks/AuditPipelineFailureRateCheck.php`:

```php
<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class AuditPipelineFailureRateCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}
```

`backend/app/Health/Checks/MailFailureRateCheck.php`:

```php
<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class MailFailureRateCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}
```

- [ ] **Step 8: Register the provider**

In `backend/bootstrap/providers.php`, add `HealthServiceProvider::class` after `AppServiceProvider::class`, and add the matching `use App\Providers\HealthServiceProvider;` import in alphabetical position.

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test --filter=HealthCheckRegistrationTest`
Expected: PASS, 3 tests.

- [ ] **Step 10: Schedule the check and heartbeat commands**

Append to `backend/routes/console.php`:

```php
Schedule::command(\Spatie\Health\Commands\RunHealthChecksCommand::class)->everyFiveMinutes();

// Must be last: it records that the scheduler itself ran (spec §18.3 O2).
Schedule::command(\Spatie\Health\Commands\ScheduleCheckHeartbeatCommand::class)->everyMinute();
```

The heartbeat runs every minute against a 10-minute max age, and checks run every 5 minutes against a 15-minute freshness window. No threshold sits at or below the interval feeding it, so ordinary scheduling jitter cannot cause a flap.

- [ ] **Step 11: Verify checks actually execute and store a result**

Run: `php artisan health:check`
Expected: a table of check results prints. `Database`, `Cache`, and the three custom checks report ok. `Horizon` and `Queue` may report failed locally if Horizon is not running — that is correct behavior, not a defect.

- [ ] **Step 12: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add composer.json composer.lock config/health.php app/Providers/HealthServiceProvider.php app/Health bootstrap/providers.php routes/console.php database/migrations
git commit -m "feat(health): register check set and operational thresholds"
```

---

### Task 2: Readiness endpoint

**Files:**
- Create: `backend/app/Http/Controllers/HealthReadinessController.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/Health/HealthReadinessTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: route named `health.ready` at `GET /health/ready`.

**Why its own controller:** readiness answers "can *this process, right now* serve traffic?" It cannot be answered from stored results a scheduler wrote up to five minutes ago, so this is deliberately the only endpoint that computes anything inline.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Health/HealthReadinessTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class HealthReadinessTest extends FeatureTest
{
    public function test_reports_ready_when_database_and_cache_are_reachable(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJson([
            'ready' => true,
            'checks' => ['database' => true, 'cache' => true],
        ]);
    }

    /**
     * Spec §15.5 [R]: a dependency health check must never gate readiness.
     * This test turns that rule into a guard — it fails if anyone ever adds
     * an outbound call to the readiness path.
     */
    public function test_makes_no_external_http_call(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/health/ready')->assertOk();
    }

    public function test_response_is_not_cacheable(): void
    {
        $this->getJson('/health/ready')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=HealthReadinessTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Create the controller**

Create `backend/app/Http/Controllers/HealthReadinessController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReachable(),
            'cache' => $this->cacheIsWritable(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()
            ->json(['ready' => $ready, 'checks' => $checks],
                $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Cache-Control', 'no-store, private');
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsWritable(): bool
    {
        try {
            $key = 'health:ready:'.Str::random(12);
            Cache::put($key, '1', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === '1';
        } catch (Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Register the health routes without the web middleware group**

Health endpoints are polled once a minute by an external monitor. Putting them in the `web` group would start a session on every poll and accumulate junk session records. Register them in `backend/bootstrap/app.php` instead.

Add these imports at the top of `bootstrap/app.php`:

```php
use App\Http\Controllers\HealthReadinessController;
use Illuminate\Support\Facades\Route;
```

Then add a `then:` closure to the existing `->withRouting(...)` call, after the `health: '/up',` line:

```php
        health: '/up',
        then: function () {
            Route::get('/health/ready', HealthReadinessController::class)
                ->name('health.ready');
        },
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=HealthReadinessTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Http/Controllers/HealthReadinessController.php bootstrap/app.php tests/Feature/Health/HealthReadinessTest.php
git commit -m "feat(health): add readiness endpoint guarded against external calls"
```

---

### Task 3: Monitoring endpoint with band mapping and staleness

**Files:**
- Create: `backend/app/Http/Controllers/HealthResultsController.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/Health/HealthResultsEndpointTest.php`

**Interfaces:**
- Consumes: `config('health.flexpick.bands')`, `paging_bands`, `default_band`, `result_freshness_minutes`, `endpoint_token` from Task 1.
- Produces: route named `health.results` at `GET /health`.

**This is the dead-man's switch.** The staleness arm is the only mechanism by which a dead scheduler becomes audible — the in-app path cannot report its own absence.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Health/HealthResultsEndpointTest.php`. Note the `withExceptionHandling()` calls in the token tests — `FeatureTest::setUp()` disables handling, so `abort(404)` would otherwise throw rather than produce a response.

```php
<?php

namespace Tests\Feature\Health;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Tests\Feature\FeatureTest;

class HealthResultsEndpointTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.endpoint_token', 'secret-token');
        config()->set('health.flexpick.result_freshness_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeResults(string $finishedAt, array $checks): void
    {
        $stored = new StoredCheckResults(
            finishedAt: Carbon::parse($finishedAt),
            checkResults: new Collection(array_map(
                fn (array $c) => new StoredCheckResult(
                    name: $c['name'],
                    label: $c['name'],
                    notificationMessage: $c['message'] ?? '',
                    shortSummary: $c['message'] ?? '',
                    status: $c['status'],
                    meta: [],
                ),
                $checks
            )),
        );

        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn($stored);
    }

    public function test_returns_200_when_fresh_and_all_ok(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Database', 'status' => 'ok'],
            ['name' => 'Cache', 'status' => 'ok'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertOk()
            ->assertJson(['stale' => false]);
    }

    public function test_returns_503_when_a_critical_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);
    }

    public function test_returns_503_when_a_high_band_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'MailFailureRate', 'status' => 'failed', 'message' => '60%'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);
    }

    public function test_returns_200_when_only_a_medium_band_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'UsedDiskSpace', 'status' => 'failed', 'message' => '90%'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertOk()
            ->assertJsonFragment(['name' => 'UsedDiskSpace', 'status' => 'failed']);
    }

    public function test_a_crashed_check_behaves_as_a_failure_of_the_same_band(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Redis', 'status' => 'crashed'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);

        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Cache', 'status' => 'crashed'],
        ]);

        $this->getJson('/health?token=secret-token')->assertOk();
    }

    public function test_returns_503_when_results_are_stale(): void
    {
        $this->fakeResults('2026-08-02 11:40:00', [
            ['name' => 'Database', 'status' => 'ok'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertStatus(503)
            ->assertJson(['stale' => true]);
    }

    public function test_returns_503_when_no_results_exist(): void
    {
        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn(null);

        $this->getJson('/health?token=secret-token')
            ->assertStatus(503)
            ->assertJson(['status' => 'no_results']);
    }

    public function test_wrong_token_returns_404(): void
    {
        $this->withExceptionHandling();

        $this->getJson('/health?token=wrong')->assertNotFound();
    }

    public function test_absent_token_returns_404(): void
    {
        $this->withExceptionHandling();

        $this->getJson('/health')->assertNotFound();
    }

    public function test_unconfigured_token_returns_404(): void
    {
        $this->withExceptionHandling();
        config()->set('health.flexpick.endpoint_token', null);

        $this->getJson('/health?token=')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=HealthResultsEndpointTest`
Expected: FAIL — 404 on every case, the route does not exist.

If the `StoredCheckResults` / `StoredCheckResult` constructor signatures differ from Task 1 Step 2's output, correct the `fakeResults` helper to match the real ones before continuing.

- [ ] **Step 3: Create the controller**

Create `backend/app/Http/Controllers/HealthResultsController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Symfony\Component\HttpFoundation\Response;

class HealthResultsController extends Controller
{
    /** Statuses that mean "this check is not passing". */
    private const FAILING = ['failed', 'crashed'];

    public function __invoke(Request $request, ResultStore $resultStore): JsonResponse
    {
        $this->assertTokenIsValid($request);

        $results = $resultStore->latestResults();

        if ($results === null) {
            return $this->respond(['status' => 'no_results'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $stale = $results->finishedAt->lt(
            now()->subMinutes((int) config('health.flexpick.result_freshness_minutes'))
        );

        $checks = collect($results->storedCheckResults);

        $paging = $checks->contains(fn (StoredCheckResult $result) => $this->isPagingFailure($result));

        return $this->respond([
            'finishedAt' => $results->finishedAt->toIso8601String(),
            'stale' => $stale,
            'checkResults' => $checks->map(fn (StoredCheckResult $result) => [
                'name' => $result->name,
                'status' => $result->status,
                'band' => $this->bandFor($result->name),
                'message' => $result->notificationMessage,
            ])->values()->all(),
        ], ($stale || $paging) ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }

    private function assertTokenIsValid(Request $request): void
    {
        $expected = (string) config('health.flexpick.endpoint_token');

        // 404 rather than 401: the endpoint should not advertise its existence.
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('token'))) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function isPagingFailure(StoredCheckResult $result): bool
    {
        if (! in_array($result->status, self::FAILING, true)) {
            return false;
        }

        return in_array(
            $this->bandFor($result->name),
            (array) config('health.flexpick.paging_bands'),
            true
        );
    }

    private function bandFor(string $name): string
    {
        $bands = (array) config('health.flexpick.bands');

        return (string) ($bands[$name] ?? config('health.flexpick.default_band'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload, int $status): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
```

- [ ] **Step 4: Register the route**

In `backend/bootstrap/app.php`, add the import:

```php
use App\Http\Controllers\HealthResultsController;
```

and add to the existing `then:` closure from Task 2:

```php
            Route::get('/health', HealthResultsController::class)
                ->name('health.results');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=HealthResultsEndpointTest`
Expected: PASS, 10 tests.

- [ ] **Step 6: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Http/Controllers/HealthResultsController.php bootstrap/app.php tests/Feature/Health/HealthResultsEndpointTest.php
git commit -m "feat(health): serve results with band-aware status and staleness switch"
```

---

### Task 4: OldestPendingAuditCheck

**Files:**
- Modify: `backend/app/Health/Checks/OldestPendingAuditCheck.php`
- Test: `backend/tests/Feature/Health/OldestPendingAuditCheckTest.php`

**Interfaces:**
- Consumes: `config('health.flexpick.oldest_queued_minutes')`, `oldest_analyzing_minutes`.
- Produces: a check named `OldestPendingAudit` returning `Result` with meta keys `queued_age_minutes` and `analyzing_age_minutes`.

**Why this exists alongside `QueueCheck`:** `QueueCheck` proves *a* worker round-trips *its own* heartbeat job. It stays green while real audit jobs pile up behind a poison message or a wedged clone. This check watches the age of the oldest genuinely pending request — §18.5 SC1's requirement to alert on oldest-pending age, because depth alone hides a stalled queue.

The `analyzing` arm covers the other half of §18.3 O1: a worker that dies mid-run strands its request in `analyzing` permanently and nothing currently notices. It measures from `analysis_started_at`, a dedicated column. The `queued` arm has no transition timestamp and uses `updated_at` as a documented proxy — a request sitting in `queued` receives no writes, so `updated_at` is when it entered the state.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Health/OldestPendingAuditCheckTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Constants\AuditRequestStatus;
use App\Health\Checks\OldestPendingAuditCheck;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class OldestPendingAuditCheckTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest does not roll back. Other tests leave audit rows behind
        // and this check reads the whole table, so start from a clean slate.
        AuditRequest::query()->forceDelete();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function run(): \Spatie\Health\Checks\Result
    {
        return (new OldestPendingAuditCheck)->run();
    }

    public function test_ok_when_there_are_no_requests(): void
    {
        $this->assertSame('ok', $this->run()->status->value);
    }

    public function test_ok_when_queued_request_is_within_the_window(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        $request->forceFill(['updated_at' => now()->subMinutes(29)])->saveQuietly();

        $this->assertSame('ok', $this->run()->status->value);
    }

    public function test_fails_when_queued_request_exceeds_the_window(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        $request->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();

        $result = $this->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(31, $result->meta['queued_age_minutes']);
    }

    public function test_fails_when_analyzing_request_is_stranded(): void
    {
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now()->subMinutes(45),
        ]);

        $result = $this->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(45, $result->meta['analyzing_age_minutes']);
    }

    /**
     * Without this, the check fires forever on historical data — every audit
     * ever completed is "old".
     */
    public function test_ignores_old_requests_in_terminal_states(): void
    {
        foreach ([
            AuditRequestStatus::SENT,
            AuditRequestStatus::HANDLED,
            AuditRequestStatus::FAILED,
            AuditRequestStatus::REPORT_READY,
        ] as $status) {
            $request = AuditRequest::factory()->create([
                'status' => $status->value,
                'analysis_started_at' => now()->subDays(30),
            ]);
            $request->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
        }

        $this->assertSame('ok', $this->run()->status->value);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OldestPendingAuditCheckTest`
Expected: FAIL — the placeholder from Task 1 always returns ok, so the two failure cases fail.

- [ ] **Step 3: Implement the check**

Replace the body of `backend/app/Health/Checks/OldestPendingAuditCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Spec §18.5 SC1 and §18.3 O1.
 *
 * QueueCheck proves a worker round-trips its own heartbeat job; it stays green
 * while real audit jobs pile up behind a poison message. This watches the age
 * of the oldest genuinely pending request instead.
 */
class OldestPendingAuditCheck extends Check
{
    public function run(): Result
    {
        $queuedAge = $this->ageInMinutes(
            AuditRequest::query()
                ->where('status', AuditRequestStatus::QUEUED->value)
                ->min('updated_at')
        );

        $analyzingAge = $this->ageInMinutes(
            AuditRequest::query()
                ->where('status', AuditRequestStatus::ANALYZING->value)
                ->min('analysis_started_at')
        );

        $queuedLimit = (int) config('health.flexpick.oldest_queued_minutes');
        $analyzingLimit = (int) config('health.flexpick.oldest_analyzing_minutes');

        $result = Result::make()
            ->meta([
                'queued_age_minutes' => $queuedAge,
                'analyzing_age_minutes' => $analyzingAge,
            ])
            ->shortSummary("queued {$queuedAge}m / analyzing {$analyzingAge}m");

        if ($queuedAge > $queuedLimit) {
            return $result->failed(
                "Oldest queued audit has been waiting {$queuedAge} minutes (limit {$queuedLimit})."
            );
        }

        if ($analyzingAge > $analyzingLimit) {
            return $result->failed(
                "An audit has been analyzing for {$analyzingAge} minutes (limit {$analyzingLimit}); a worker likely died mid-run."
            );
        }

        return $result->ok();
    }

    private function ageInMinutes(mixed $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        // Carbon 3 returns a signed diff by default; force absolute.
        return (int) Carbon::parse($timestamp)->diffInMinutes(now(), true);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=OldestPendingAuditCheckTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Health/Checks/OldestPendingAuditCheck.php tests/Feature/Health/OldestPendingAuditCheckTest.php
git commit -m "feat(health): alert on oldest pending and stranded analyzing audits"
```

---

### Task 5: Failure-rate checks

**Files:**
- Create: `backend/app/Health/FailureRate.php`
- Modify: `backend/app/Health/Checks/AuditPipelineFailureRateCheck.php`
- Modify: `backend/app/Health/Checks/MailFailureRateCheck.php`
- Test: `backend/tests/Unit/FailureRateTest.php`
- Test: `backend/tests/Feature/Health/FailureRateChecksTest.php`

**Interfaces:**
- Consumes: `config('health.flexpick.pipeline_failure.*')` and `mail_failure.*` from Task 1.
- Produces: `App\Health\FailureRate` with constructor `(int $total, int $failed, int $minSamples)` and methods `belowFloor(): bool`, `percent(): int`. Checks named `AuditPipelineFailureRate` and `MailFailureRate`, each with meta keys `total`, `failed`, `percent`.

**The minimum-sample floor is the point of the value object.** Without it, one failure on a quiet day reads as a 100% failure rate and the alert is noise. This mirrors the `benchmark_min_sample` rule already in `config/audit.php`.

- [ ] **Step 1: Write the failing unit test**

Create `backend/tests/Unit/FailureRateTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Health\FailureRate;
use PHPUnit\Framework\TestCase;

class FailureRateTest extends TestCase
{
    public function test_zero_samples_is_below_the_floor(): void
    {
        $rate = new FailureRate(total: 0, failed: 0, minSamples: 5);

        $this->assertTrue($rate->belowFloor());
        $this->assertSame(0, $rate->percent());
    }

    public function test_just_under_the_floor_is_below_it(): void
    {
        $this->assertTrue((new FailureRate(4, 4, 5))->belowFloor());
    }

    public function test_exactly_at_the_floor_is_not_below_it(): void
    {
        $this->assertFalse((new FailureRate(5, 3, 5))->belowFloor());
    }

    public function test_percent_rounds_to_the_nearest_integer(): void
    {
        $this->assertSame(60, (new FailureRate(5, 3, 5))->percent());
        $this->assertSame(33, (new FailureRate(3, 1, 1))->percent());
        $this->assertSame(100, (new FailureRate(7, 7, 5))->percent());
    }
}
```

- [ ] **Step 2: Run the unit test to verify it fails**

Run: `php artisan test --filter=FailureRateTest`
Expected: FAIL with "Class App\Health\FailureRate not found".

- [ ] **Step 3: Create the value object**

Create `backend/app/Health/FailureRate.php`:

```php
<?php

namespace App\Health;

/**
 * A failure share over a window, with a minimum-sample floor.
 *
 * Below the floor the rate is not meaningful: one failure on a quiet day
 * would otherwise read as 100% and page someone. Mirrors the
 * benchmark_min_sample rule in config/audit.php.
 */
final readonly class FailureRate
{
    public function __construct(
        public int $total,
        public int $failed,
        public int $minSamples,
    ) {}

    public function belowFloor(): bool
    {
        return $this->total < $this->minSamples;
    }

    public function percent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round($this->failed / $this->total * 100);
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `php artisan test --filter=FailureRateTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Write the failing feature test**

Create `backend/tests/Feature/Health/FailureRateChecksTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Constants\AuditRequestStatus;
use App\Health\Checks\AuditPipelineFailureRateCheck;
use App\Health\Checks\MailFailureRateCheck;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class FailureRateChecksTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest does not roll back; these checks count whole tables.
        AuditEmailLog::query()->forceDelete();
        AuditRequest::query()->forceDelete();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.pipeline_failure', [
            'window_hours' => 24, 'min_samples' => 5, 'fail_percent' => 40,
        ]);
        config()->set('health.flexpick.mail_failure', [
            'window_hours' => 24, 'min_samples' => 5, 'fail_percent' => 25,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function auditWith(string $status, int $hoursAgo = 1): void
    {
        $request = AuditRequest::factory()->create(['status' => $status]);
        $request->forceFill([
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ])->saveQuietly();
    }

    private function emailWith(string $status, int $hoursAgo = 1): void
    {
        $log = AuditEmailLog::create([
            'mailable' => 'TestMailable',
            'recipient' => 'ops@example.com',
            'subject' => 'x',
            'body' => 'x',
            'status' => $status,
            'attempts' => 1,
        ]);
        $log->forceFill([
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ])->saveQuietly();
    }

    public function test_pipeline_check_is_ok_with_no_runs(): void
    {
        $this->assertSame('ok', (new AuditPipelineFailureRateCheck)->run()->status->value);
    }

    public function test_pipeline_check_is_ok_below_the_sample_floor(): void
    {
        // 4 runs, all failed — 100%, but below the floor of 5.
        for ($i = 0; $i < 4; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame(4, $result->meta['total']);
    }

    public function test_pipeline_check_fails_above_the_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value);
        }
        $this->auditWith(AuditRequestStatus::NEEDS_FOLLOWUP->value);
        $this->auditWith(AuditRequestStatus::SENT->value);

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(80, $result->meta['percent']);
    }

    public function test_pipeline_check_is_ok_at_or_below_the_threshold(): void
    {
        // 2 failed of 5 = 40%, which is not *above* 40.
        $this->auditWith(AuditRequestStatus::FAILED->value);
        $this->auditWith(AuditRequestStatus::FAILED->value);
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::SENT->value);
        }

        $this->assertSame('ok', (new AuditPipelineFailureRateCheck)->run()->status->value);
    }

    public function test_pipeline_check_ignores_runs_outside_the_window(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value, hoursAgo: 30);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame(0, $result->meta['total']);
    }

    public function test_mail_check_fails_above_the_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->emailWith(AuditEmailLog::STATUS_FAILED);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->emailWith(AuditEmailLog::STATUS_SENT);
        }

        $result = (new MailFailureRateCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(50, $result->meta['percent']);
    }

    public function test_mail_check_is_ok_below_the_sample_floor(): void
    {
        $this->emailWith(AuditEmailLog::STATUS_FAILED);

        $this->assertSame('ok', (new MailFailureRateCheck)->run()->status->value);
    }
}
```

- [ ] **Step 6: Run the feature test to verify it fails**

Run: `php artisan test --filter=FailureRateChecksTest`
Expected: FAIL — placeholders always return ok, so the two "fails above threshold" cases fail.

- [ ] **Step 7: Implement the pipeline check**

Replace `backend/app/Health/Checks/AuditPipelineFailureRateCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Constants\AuditRequestStatus;
use App\Health\FailureRate;
use App\Models\AuditRequest;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class AuditPipelineFailureRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.pipeline_failure');
        $since = now()->subHours((int) $config['window_hours']);

        $total = AuditRequest::query()->where('created_at', '>=', $since)->count();

        $failed = AuditRequest::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', [
                AuditRequestStatus::FAILED->value,
                AuditRequestStatus::NEEDS_FOLLOWUP->value,
            ])
            ->count();

        $rate = new FailureRate($total, $failed, (int) $config['min_samples']);

        $result = Result::make()
            ->meta([
                'total' => $rate->total,
                'failed' => $rate->failed,
                'percent' => $rate->percent(),
            ])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if ($rate->belowFloor()) {
            return $result->ok();
        }

        if ($rate->percent() > (int) $config['fail_percent']) {
            return $result->failed(
                "Audit pipeline failure rate is {$rate->percent()}% over the last {$config['window_hours']}h ({$rate->failed}/{$rate->total})."
            );
        }

        return $result->ok();
    }
}
```

- [ ] **Step 8: Implement the mail check**

Replace `backend/app/Health/Checks/MailFailureRateCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Health\FailureRate;
use App\Models\AuditEmailLog;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class MailFailureRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.mail_failure');
        $since = now()->subHours((int) $config['window_hours']);

        $total = AuditEmailLog::query()->where('created_at', '>=', $since)->count();

        $failed = AuditEmailLog::query()
            ->where('created_at', '>=', $since)
            ->where('status', AuditEmailLog::STATUS_FAILED)
            ->count();

        $rate = new FailureRate($total, $failed, (int) $config['min_samples']);

        $result = Result::make()
            ->meta([
                'total' => $rate->total,
                'failed' => $rate->failed,
                'percent' => $rate->percent(),
            ])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if ($rate->belowFloor()) {
            return $result->ok();
        }

        if ($rate->percent() > (int) $config['fail_percent']) {
            return $result->failed(
                "Audit email failure rate is {$rate->percent()}% over the last {$config['window_hours']}h ({$rate->failed}/{$rate->total})."
            );
        }

        return $result->ok();
    }
}
```

- [ ] **Step 9: Run both tests to verify they pass**

Run: `php artisan test --filter="FailureRateTest|FailureRateChecksTest"`
Expected: PASS, 11 tests.

- [ ] **Step 10: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Health tests/Unit/FailureRateTest.php tests/Feature/Health/FailureRateChecksTest.php
git commit -m "feat(health): add pipeline and mail failure-rate checks with sample floor"
```

---

### Task 6: Alert channels, notification, and dispatcher

**Files:**
- Create: `backend/app/Notifications/Channels/TelegramChannel.php`
- Create: `backend/app/Notifications/Channels/SlackWebhookChannel.php`
- Create: `backend/app/Notifications/OperationsAlert.php`
- Create: `backend/app/Console/Commands/DispatchHealthAlerts.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Health/OperationsAlertTest.php`
- Test: `backend/tests/Feature/Health/DispatchHealthAlertsTest.php`

**Interfaces:**
- Consumes: `config('health.flexpick.alert_channels')`, `alert_throttle_minutes`, `channel_timeout_seconds`, `telegram.*`, `slack.*`, `mail.to`, `bands`.
- Produces: `App\Notifications\OperationsAlert` with constructor `(string $checkName, string $band, string $status, string $message)`; console command signature `app:health-alerts`.

**Three spec requirements drive this being ours rather than spatie's notification config:** a throttle-lookup failure must fall through to *sending* (Redis being down is exactly when the alert matters most), messages must carry the severity band, and recovery notifications must be guaranteed.

- [ ] **Step 1: Write the failing channel and notification test**

Create `backend/tests/Feature/Health/OperationsAlertTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\OperationsAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\FeatureTest;

class OperationsAlertTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('health.flexpick.telegram', [
            'bot_token' => 'bot-token',
            'chat_id' => '12345',
        ]);
        config()->set('health.flexpick.slack.webhook_url', 'https://hooks.slack.test/abc');
        config()->set('health.flexpick.mail.to', 'ops@example.com');
        config()->set('health.flexpick.channel_timeout_seconds', 5);
    }

    private function alert(): OperationsAlert
    {
        return new OperationsAlert(
            checkName: 'OldestPendingAudit',
            band: 'critical',
            status: 'failed',
            message: 'Oldest queued audit has been waiting 45 minutes (limit 30).',
        );
    }

    public function test_via_returns_only_the_configured_channels(): void
    {
        config()->set('health.flexpick.alert_channels', ['mail', 'telegram']);

        $via = $this->alert()->via(Notification::route('mail', 'ops@example.com'));

        $this->assertContains('mail', $via);
        $this->assertContains(TelegramChannel::class, $via);
        $this->assertNotContains(SlackWebhookChannel::class, $via);
    }

    public function test_telegram_channel_posts_the_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botbot-token/sendMessage')
                && $request['chat_id'] === '12345'
                && str_contains($request['text'], 'CRITICAL')
                && str_contains($request['text'], 'OldestPendingAudit')
                && str_contains($request['text'], '45 minutes');
        });
    }

    public function test_slack_channel_posts_the_message(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        (new SlackWebhookChannel)->send(
            Notification::route(SlackWebhookChannel::class, null),
            $this->alert()
        );

        Http::assertSent(fn ($request) => str_contains($request['text'], 'OldestPendingAudit'));
    }

    /**
     * An alerting path that throws converts a degraded system into a silent
     * one — the exact failure this phase exists to prevent.
     */
    public function test_channel_without_credentials_is_skipped_and_logged_not_thrown(): void
    {
        Http::preventStrayRequests();
        config()->set('health.flexpick.telegram.bot_token', null);

        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Telegram')
        );

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );
    }

    public function test_channel_transport_failure_is_swallowed(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));
        Log::shouldReceive('warning')->once();

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OperationsAlertTest`
Expected: FAIL with "Class App\Notifications\OperationsAlert not found".

- [ ] **Step 3: Create the notification**

Create `backend/app/Notifications/OperationsAlert.php`:

```php
<?php

namespace App\Notifications;

use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationsAlert extends Notification
{
    public function __construct(
        public readonly string $checkName,
        public readonly string $band,
        public readonly string $status,
        public readonly string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $map = [
            'mail' => 'mail',
            'telegram' => TelegramChannel::class,
            'slack' => SlackWebhookChannel::class,
        ];

        $configured = (array) config('health.flexpick.alert_channels');

        return array_values(array_filter(array_map(
            fn (string $name) => $map[$name] ?? null,
            $configured
        )));
    }

    public function subject(): string
    {
        $verb = $this->status === 'ok' ? 'RECOVERED' : 'FAILING';

        return sprintf('[%s] %s %s', strtoupper($this->band), $this->checkName, $verb);
    }

    public function toAlertText(): string
    {
        return $this->subject()."\n\n".$this->message."\n\n".config('app.url');
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->message)
            ->line('Check: '.$this->checkName)
            ->line('Severity: '.strtoupper($this->band));
    }
}
```

- [ ] **Step 4: Create the Telegram channel**

Create `backend/app/Notifications/Channels/TelegramChannel.php`:

```php
<?php

namespace App\Notifications\Channels;

use App\Notifications\OperationsAlert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OperationsAlert) {
            return;
        }

        $token = (string) config('health.flexpick.telegram.bot_token');
        $chatId = (string) config('health.flexpick.telegram.chat_id');

        if ($token === '' || $chatId === '') {
            Log::warning('Health alert channel Telegram is enabled but not configured; skipping.');

            return;
        }

        try {
            Http::timeout((int) config('health.flexpick.channel_timeout_seconds'))
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $notification->toAlertText(),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable $e) {
            // Never rethrow: one dead channel must not suppress the others,
            // nor crash the health run.
            Log::warning('Health alert channel Telegram failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 5: Create the Slack channel**

Create `backend/app/Notifications/Channels/SlackWebhookChannel.php`:

```php
<?php

namespace App\Notifications\Channels;

use App\Notifications\OperationsAlert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackWebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OperationsAlert) {
            return;
        }

        $webhook = (string) config('health.flexpick.slack.webhook_url');

        if ($webhook === '') {
            Log::warning('Health alert channel Slack is enabled but not configured; skipping.');

            return;
        }

        try {
            Http::timeout((int) config('health.flexpick.channel_timeout_seconds'))
                ->post($webhook, ['text' => $notification->toAlertText()]);
        } catch (Throwable $e) {
            Log::warning('Health alert channel Slack failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=OperationsAlertTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Write the failing dispatcher test**

Create `backend/tests/Feature/Health/DispatchHealthAlertsTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Notifications\OperationsAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Tests\Feature\FeatureTest;

class DispatchHealthAlertsTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-02 12:00:00');
        Cache::flush();
        config()->set('health.flexpick.alert_channels', ['mail']);
        config()->set('health.flexpick.mail.to', 'ops@example.com');
        config()->set('health.flexpick.alert_throttle_minutes', 60);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function results(array $checks): void
    {
        $stored = new StoredCheckResults(
            finishedAt: now(),
            checkResults: new Collection(array_map(
                fn (array $c) => new StoredCheckResult(
                    name: $c['name'],
                    label: $c['name'],
                    notificationMessage: $c['message'] ?? '',
                    shortSummary: $c['message'] ?? '',
                    status: $c['status'],
                    meta: [],
                ),
                $checks
            )),
        );

        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn($stored);
    }

    public function test_sends_an_alert_when_a_check_starts_failing(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(
            OperationsAlert::class,
            fn (OperationsAlert $alert) => $alert->checkName === 'Database'
                && $alert->band === 'critical'
                && $alert->status === 'failed'
        );
    }

    public function test_sends_nothing_when_everything_is_ok(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'ok']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_does_not_repeat_within_the_throttle_window(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts');
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 1);
    }

    public function test_repeats_after_the_throttle_window_expires(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts');
        Carbon::setTestNow(now()->addMinutes(61));
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 2);
    }

    public function test_sends_a_recovery_alert_when_a_failing_check_returns_to_ok(): void
    {
        Notification::fake();

        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);
        $this->artisan('app:health-alerts');

        $this->results([['name' => 'Database', 'status' => 'ok']]);
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemand(
            OperationsAlert::class,
            fn (OperationsAlert $alert) => $alert->status === 'ok'
        );
    }

    public function test_a_crashed_check_alerts_like_a_failure(): void
    {
        Notification::fake();
        $this->results([['name' => 'Redis', 'status' => 'crashed']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    /**
     * Spec: a throttle lookup failure must fall through to SENDING. The cache
     * being unavailable is exactly when the alert matters most.
     */
    public function test_sends_when_the_throttle_store_is_unavailable(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis down'));

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(OperationsAlert::class);
    }
}
```

- [ ] **Step 8: Run the test to verify it fails**

Run: `php artisan test --filter=DispatchHealthAlertsTest`
Expected: FAIL — the `app:health-alerts` command does not exist.

- [ ] **Step 9: Create the dispatcher command**

Create `backend/app/Console/Commands/DispatchHealthAlerts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Notifications\OperationsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Throwable;

/**
 * Owns alert dispatch so three spec requirements hold that Spatie's built-in
 * notification config cannot satisfy: band-aware messages, guaranteed recovery
 * notifications, and a throttle whose failure mode is to SEND, not to suppress.
 */
class DispatchHealthAlerts extends Command
{
    protected $signature = 'app:health-alerts';

    protected $description = 'Dispatch operational alerts for health check state transitions';

    private const FAILING = ['failed', 'crashed'];

    public function handle(ResultStore $resultStore): int
    {
        $results = $resultStore->latestResults();

        if ($results === null) {
            $this->warn('No stored health results; nothing to dispatch.');

            return self::SUCCESS;
        }

        foreach ($results->storedCheckResults as $result) {
            $this->dispatchFor($result);
        }

        return self::SUCCESS;
    }

    private function dispatchFor(StoredCheckResult $result): void
    {
        $isFailing = in_array($result->status, self::FAILING, true);
        $key = "health:alert:{$result->name}";
        $lastAlertedAt = $this->remember($key);

        if (! $isFailing) {
            // Recovery: only interesting if we previously alerted.
            if ($lastAlertedAt !== null) {
                $this->send($result, 'ok');
                $this->forget($key);
            }

            return;
        }

        $throttleMinutes = (int) config('health.flexpick.alert_throttle_minutes');

        if ($lastAlertedAt !== null && now()->diffInMinutes($lastAlertedAt, true) < $throttleMinutes) {
            return;
        }

        $this->send($result, $result->status);
        $this->store($key);
    }

    private function send(StoredCheckResult $result, string $status): void
    {
        $bands = (array) config('health.flexpick.bands');
        $band = (string) ($bands[$result->name] ?? config('health.flexpick.default_band'));

        $message = $status === 'ok'
            ? "{$result->name} is healthy again."
            : ($result->notificationMessage ?: "{$result->name} reported {$status}.");

        Notification::route('mail', (string) config('health.flexpick.mail.to'))
            ->notify(new OperationsAlert($result->name, $band, $status, $message));

        $this->line("Alerted: {$result->name} ({$status})");
    }

    /**
     * Throttle state reads must never suppress an alert. If the cache is
     * unreachable, return null so the caller treats this as "not yet alerted"
     * and sends.
     */
    private function remember(string $key): ?\Illuminate\Support\Carbon
    {
        try {
            $value = Cache::get($key);

            return $value === null ? null : \Illuminate\Support\Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function store(string $key): void
    {
        try {
            Cache::put($key, now()->toIso8601String(), now()->addDay());
        } catch (Throwable) {
            // Losing throttle state means a repeat alert, which is the safe direction.
        }
    }

    private function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // Non-fatal.
        }
    }
}
```

Note that `Notification::route('mail', ...)` produces an on-demand notifiable; `OperationsAlert::via()` then selects the configured channel classes, so Telegram and Slack are reached through the same call.

- [ ] **Step 10: Run the test to verify it passes**

Run: `php artisan test --filter=DispatchHealthAlertsTest`
Expected: PASS, 7 tests.

- [ ] **Step 11: Schedule the dispatcher**

In `backend/routes/console.php`, immediately after the `RunHealthChecksCommand` line added in Task 1:

```php
Schedule::command('app:health-alerts')->everyFiveMinutes();
```

- [ ] **Step 12: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Notifications app/Console/Commands/DispatchHealthAlerts.php routes/console.php tests/Feature/Health/OperationsAlertTest.php tests/Feature/Health/DispatchHealthAlertsTest.php
git commit -m "feat(health): dispatch band-aware alerts to telegram, slack, and mail"
```

---

### Task 7: Error tracking with mandatory token scrubbing

**Files:**
- Modify: `backend/composer.json`
- Create: `backend/config/sentry.php` (published, then edited)
- Create: `backend/app/Support/Sentry/TokenScrubber.php`
- Test: `backend/tests/Unit/TokenScrubberTest.php`

**Interfaces:**
- Consumes: `config('audit.github_token')`.
- Produces: `App\Support\Sentry\TokenScrubber` with `__invoke(\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event`.

**Why the scrubber is a component, not a setting.** `RepositoryCloner:81` builds `https://x-access-token:{token}@github.com/…` and redacts it only in the two messages it throws itself (`:19`, `:36`). Anything Sentry captures beyond those messages is unredacted. §15.1 makes "no repository access tokens in any log line" an `[R]`, and §18.4 names token redaction as one of four rules that must be guarded by a test because a well-meaning future change can quietly break it.

- [ ] **Step 1: Install the SDK and publish its config**

```bash
cd /var/www/html/flexpick.net/backend
composer require sentry/sentry-laravel
php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"
```

Expected: `config/sentry.php` exists.

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Unit/TokenScrubberTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Sentry\TokenScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;

class TokenScrubberTest extends TestCase
{
    private const TOKEN = 'ghp_AbCdEf0123456789AbCdEf0123456789AbCd';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.github_token', self::TOKEN);
    }

    public function test_strips_an_embedded_credential_pair_from_the_message(): void
    {
        $event = Event::createEvent();
        $event->setMessage(
            'Failed cloning https://x-access-token:'.self::TOKEN.'@github.com/acme/app.git'
        );

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertNotNull($scrubbed);
        $this->assertStringNotContainsString(self::TOKEN, $scrubbed->getMessage());
        $this->assertStringNotContainsString('x-access-token:', $scrubbed->getMessage());
        $this->assertStringContainsString('[REDACTED]', $scrubbed->getMessage());
        $this->assertStringContainsString('github.com/acme/app.git', $scrubbed->getMessage());
    }

    public function test_strips_a_bare_token_occurrence(): void
    {
        $event = Event::createEvent();
        $event->setMessage('git exited 128; token was '.self::TOKEN);

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString(self::TOKEN, $scrubbed->getMessage());
    }

    public function test_strips_credentials_from_extra_context(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'command' => 'git clone https://x-access-token:'.self::TOKEN.'@github.com/acme/app.git /tmp/x',
        ]);

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString(self::TOKEN, json_encode($scrubbed->getExtra()));
    }

    public function test_strips_any_credential_pair_even_when_no_token_is_configured(): void
    {
        config()->set('audit.github_token', null);

        $event = Event::createEvent();
        $event->setMessage('https://x-access-token:some-other-secret@github.com/acme/app.git');

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString('some-other-secret', $scrubbed->getMessage());
    }

    public function test_leaves_clean_events_untouched(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Repository could not be cloned: https://github.com/acme/app.git');

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertSame(
            'Repository could not be cloned: https://github.com/acme/app.git',
            $scrubbed->getMessage()
        );
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=TokenScrubberTest`
Expected: FAIL with "Class App\Support\Sentry\TokenScrubber not found".

- [ ] **Step 4: Implement the scrubber**

Create `backend/app/Support/Sentry/TokenScrubber.php`:

```php
<?php

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Spec §15.1 [R] and §18.4: no repository access token may leave the
 * application. RepositoryCloner builds authenticated clone URLs, so any
 * exception raised near it can carry credentials that its own redaction
 * does not cover.
 */
class TokenScrubber
{
    private const REPLACEMENT = '[REDACTED]';

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage($this->scrub($message));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra($this->scrubArray($extra));
        }

        $tags = $event->getTags();

        if ($tags !== []) {
            /** @var array<string, string> $scrubbedTags */
            $scrubbedTags = $this->scrubArray($tags);
            $event->setTags($scrubbedTags);
        }

        return $event;
    }

    private function scrub(string $value): string
    {
        // Any embedded credential pair, whether or not it is our token.
        $value = (string) preg_replace(
            '#https://[^:/@\s]+:[^@\s]+@#i',
            'https://'.self::REPLACEMENT.'@',
            $value
        );

        $token = (string) config('audit.github_token');

        if ($token !== '') {
            $value = str_replace($token, self::REPLACEMENT, $value);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $this->scrub($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->scrubArray($value);
            }
        }

        return $values;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=TokenScrubberTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Wire the scrubber and release context into `config/sentry.php`**

Edit `backend/config/sentry.php` so these keys hold these values:

```php
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Never send PII, and never send performance traces: the collector is
    // self-hosted on the same box and storage is the constraint.
    'send_default_pii' => false,
    'traces_sample_rate' => 0.0,

    'before_send' => [\App\Support\Sentry\TokenScrubber::class, '__invoke'],
```

If the published file expresses `before_send` as a closure, replace it with:

```php
    'before_send' => fn (\Sentry\Event $event, ?\Sentry\EventHint $hint) => (new \App\Support\Sentry\TokenScrubber)($event, $hint),
```

- [ ] **Step 7: Tag pipeline exceptions with the audit request identifier**

§15.3 makes the audit request's public identifier the deliberate substitute for distributed tracing — the one key spanning HTTP, queue, and mail. In `backend/app/Services/AuditReport/AuditPipeline.php`, inside `run()`, immediately after the audit request is available and before the first stage executes, add:

```php
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($auditRequest): void {
            $scope->setTag('audit_request', (string) $auditRequest->uuid);
        });
```

Adjust the variable name to match the one already in scope in that method.

- [ ] **Step 8: Verify the suite is unaffected**

Run: `php artisan test --compact`
Expected: PASS. With no `SENTRY_DSN` set in `.env.testing`, the SDK is inert and no events are transmitted during tests.

- [ ] **Step 9: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
vendor/bin/phpstan analyse
git add composer.json composer.lock config/sentry.php app/Support/Sentry app/Services/AuditReport/AuditPipeline.php tests/Unit/TokenScrubberTest.php
git commit -m "feat(observability): add error tracking with mandatory token scrubbing"
```

---

### Task 8: Smoke command

**Files:**
- Create: `backend/app/Console/Commands/SmokeCommand.php`
- Test: `backend/tests/Feature/Health/SmokeCommandTest.php`

**Interfaces:**
- Consumes: route `health.ready` from Task 2.
- Produces: console command `app:smoke`. Exit 0 = release good; non-zero = roll back.

Read-only, side-effect-free, safe to re-run against production. Sends no email and runs no audit.

**Production-gated assertions.** Four of the eight only make sense on a real release: cached config/routes, an active Horizon worker, a built Vite manifest, and a real mail transport. Locally you deliberately do not cache config and may not run Horizon. Those four therefore assert `true` outside production, and the test covers both branches. This is not a loophole — running `app:smoke` locally still exercises the other three, and on the box that matters all seven bind.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Health/SmokeCommandTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Mockery;
use Tests\Feature\FeatureTest;

class SmokeCommandTest extends FeatureTest
{
    public function test_succeeds_when_the_application_is_healthy(): void
    {
        config()->set('app.env', 'testing');

        $this->artisan('app:smoke')->assertSuccessful();
    }

    public function test_reports_every_specified_assertion(): void
    {
        config()->set('app.env', 'testing');

        $this->artisan('app:smoke')
            ->expectsOutputToContain('liveness endpoint')
            ->expectsOutputToContain('readiness endpoint')
            ->expectsOutputToContain('database reachable')
            ->expectsOutputToContain('no pending migrations')
            ->expectsOutputToContain('configuration and routes cached')
            ->expectsOutputToContain('horizon worker on the audit queue')
            ->expectsOutputToContain('mail transport')
            ->expectsOutputToContain('vite manifest')
            ->assertSuccessful();
    }

    public function test_fails_when_migrations_are_pending(): void
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->andReturn([]);

        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('paths')->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')
            ->andReturn(['2026_01_01_000000_never_ran' => '/tmp/never_ran.php']);
        $migrator->shouldReceive('getRepository')->andReturn($repository);

        $this->app->instance('migrator', $migrator);

        $this->artisan('app:smoke')
            ->expectsOutputToContain('no pending migrations')
            ->assertFailed();
    }

    public function test_fails_when_mail_transport_is_log_in_production(): void
    {
        config()->set('app.env', 'production');
        config()->set('mail.default', 'log');

        $this->artisan('app:smoke')
            ->expectsOutputToContain('mail transport')
            ->assertFailed();
    }

    public function test_allows_log_mail_transport_outside_production(): void
    {
        config()->set('app.env', 'testing');
        config()->set('mail.default', 'log');

        $this->artisan('app:smoke')->assertSuccessful();
    }

    public function test_fails_in_production_when_configuration_is_not_cached(): void
    {
        config()->set('app.env', 'production');
        config()->set('mail.default', 'smtp');

        // The testing environment never has a cached config file, so asserting
        // production here proves the gate binds where it matters.
        $this->artisan('app:smoke')
            ->expectsOutputToContain('configuration and routes cached')
            ->assertFailed();
    }
}
```



- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SmokeCommandTest`
Expected: FAIL — the `app:smoke` command does not exist.

- [ ] **Step 3: Implement the command**

Create `backend/app/Console/Commands/SmokeCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Post-deploy gate (spec §14.4, PR8). Read-only and safe to re-run against
 * production: sends no email, runs no audit, writes nothing.
 */
class SmokeCommand extends Command
{
    protected $signature = 'app:smoke';

    protected $description = 'Verify a freshly deployed release is serviceable';

    public function handle(): int
    {
        $failures = [];

        foreach ($this->assertions() as $label => $assertion) {
            try {
                $ok = $assertion();
            } catch (Throwable $e) {
                $ok = false;
                $label .= ' ('.$e->getMessage().')';
            }

            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').'  '.$label);

            if (! $ok) {
                $failures[] = $label;
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error(count($failures).' smoke assertion(s) failed. Roll back this release.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All smoke assertions passed.');

        return self::SUCCESS;
    }

    /**
     * The seven assertions of spec §7, plus a direct database probe so a
     * connection failure is named as such rather than surfacing as a
     * confusing readiness 503.
     *
     * @return array<string, callable(): bool>
     */
    private function assertions(): array
    {
        return [
            'liveness endpoint returns 200' => fn (): bool => $this->getReturnsOk('/up'),
            'readiness endpoint returns 200' => fn (): bool => $this->getReturnsOk('/health/ready'),
            'database reachable' => fn (): bool => $this->databaseIsReachable(),
            'no pending migrations' => fn (): bool => $this->migrationsAreCurrent(),
            'configuration and routes cached' => fn (): bool => $this->cachesAreWarm(),
            'horizon worker on the audit queue' => fn (): bool => $this->horizonIsRunning(),
            'mail transport is a real transport' => fn (): bool => $this->mailTransportIsUsable(),
            'vite manifest present and a public page renders' => fn (): bool => $this->publicPageRenders(),
        ];
    }

    private function inProduction(): bool
    {
        return config('app.env') === 'production';
    }

    private function getReturnsOk(string $uri): bool
    {
        $response = app()->handle(Request::create($uri, 'GET'));

        return $response->getStatusCode() === 200;
    }

    private function databaseIsReachable(): bool
    {
        DB::select('select 1');

        return true;
    }

    private function migrationsAreCurrent(): bool
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->getRepository()->getRan();

        return array_diff($files, $ran) === [];
    }

    private function cachesAreWarm(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return file_exists(app()->getCachedConfigPath())
            && file_exists(app()->getCachedRoutesPath());
    }

    private function horizonIsRunning(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        $masters = app(MasterSupervisorRepository::class)->all();

        return $masters !== [];
    }

    private function mailTransportIsUsable(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Guards the documented "Vite manifest not found" 500 that takes out
     * /pricing — precisely the class of breakage a smoke gate exists to catch.
     */
    private function publicPageRenders(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return file_exists(public_path('build/manifest.json'))
            && $this->getReturnsOk('/pricing');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SmokeCommandTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Verify it runs for real**

Run: `php artisan app:smoke`
Expected: every assertion prints PASS and the command exits 0.

- [ ] **Step 6: Run the gates and commit**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add app/Console/Commands/SmokeCommand.php tests/Feature/Health/SmokeCommandTest.php
git commit -m "feat(ops): add app:smoke post-deploy gate"
```

---

### Task 9: Environment documentation and final gates

**Files:**
- Modify: `backend/.env.example`
- Modify: `CLAUDE.md`
- Modify: `docs/2026-08-01-remaining-phases.md`

- [ ] **Step 1: Document every new variable in `.env.example`**

§14.5 `[R]` requires the example environment file to document every required variable using obviously-placeholder values. Append:

```dotenv
# Observability — health checks, alerting, error tracking (Phase 9A-1)
HEALTH_ENDPOINT_TOKEN=change-me-to-a-long-random-string
HEALTH_RESULT_FRESHNESS_MINUTES=15
HEALTH_QUEUE_HEARTBEAT_MINUTES=10
HEALTH_SCHEDULE_HEARTBEAT_MINUTES=10
HEALTH_DISK_FAIL_PERCENT=85
HEALTH_OLDEST_QUEUED_MINUTES=30
HEALTH_OLDEST_ANALYZING_MINUTES=30
HEALTH_PIPELINE_WINDOW_HOURS=24
HEALTH_PIPELINE_MIN_SAMPLES=5
HEALTH_PIPELINE_FAIL_PERCENT=40
HEALTH_MAIL_WINDOW_HOURS=24
HEALTH_MAIL_MIN_SAMPLES=5
HEALTH_MAIL_FAIL_PERCENT=25

# Comma-separated subset of: mail,telegram,slack
HEALTH_ALERT_CHANNELS=mail
HEALTH_ALERT_THROTTLE_MINUTES=60
HEALTH_CHANNEL_TIMEOUT_SECONDS=5
HEALTH_ALERT_MAIL_TO=ops@example.com
HEALTH_TELEGRAM_BOT_TOKEN=
HEALTH_TELEGRAM_CHAT_ID=
HEALTH_SLACK_WEBHOOK_URL=

# Self-hosted Bugsink. SENTRY_RELEASE is injected by the Ploi deploy script.
SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=local
SENTRY_RELEASE=
```

- [ ] **Step 2: Add an observability section to `CLAUDE.md`**

Under the `backend/` quick reference, after the audit-pipeline section, add:

```markdown
### Observability (Phase 9A-1)

`spatie/laravel-health` owns check execution and result storage; alert dispatch is ours
(`app:health-alerts`) because the spec requires a throttle that fails open, band-aware
messages, and guaranteed recovery notifications.

- Checks: `app/Health/Checks/` (three custom) plus Spatie built-ins, registered in
  `app/Providers/HealthServiceProvider.php`.
- Thresholds and severity bands: the `flexpick` block of `config/health.php`. Every check
  must have a band — a test enforces this.
- Endpoints: `/up` liveness, `/health/ready` readiness (never calls an external dependency),
  `/health` token-guarded monitoring. `/health` returns 503 on a critical/high failure **or on
  stale results** — the stale arm is the dead-man's switch for a dead scheduler.
- `php artisan app:smoke` is the post-deploy gate; its exit code is the contract.
- Error tracking is self-hosted Bugsink via the Sentry SDK. `app/Support/Sentry/TokenScrubber.php`
  is mandatory, not optional — see §15.1/§18.4.
```

- [ ] **Step 3: Mark Phase 9A-1 items complete in the remaining-phases checklist**

In `docs/2026-08-01-remaining-phases.md`, under "Phase 9A — Launch-blocking operations", insert this line directly beneath the `**Blocked on:**` line:

```markdown
**Split (2026-08-02):** 9A-1 (in-repo observability) is specified in
`docs/superpowers/specs/2026-08-02-launch-blocking-operations-design.md` and implemented per
`docs/superpowers/plans/2026-08-02-launch-blocking-operations.md`. 9A-2 (staging, CI, first
deploy, rollback rehearsal, ESP + DNS, support ownership) remains outstanding as a runbook.
```

Then replace the first five bullets of that section with:

```markdown
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
```

Leave the deploy-automation, staging, mail-transport, and support-ownership bullets unchecked — they are 9A-2.

- [ ] **Step 4: Run the full gate set**

```bash
cd /var/www/html/flexpick.net/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact
vendor/bin/phpstan analyse
```

Expected: suite green with the new tests included; Pint reports no changes; PHPStan introduces no new error category against the pre-existing ~416 accepted errors.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add backend/.env.example CLAUDE.md docs/2026-08-01-remaining-phases.md
git commit -m "docs: document observability configuration and close out Phase 9A-1"
```

---

## Not delivered by this plan

Stated explicitly because PR18 requires that gaps be named rather than assumed. None of the following is provable in the test suite, and all are 9A-2 runbook items:

- Ploi's uptime monitor actually polling `/health` with the token
- Telegram and Slack actually delivering
- Bugsink actually receiving an event
- `SENTRY_RELEASE` actually carrying the deployed git SHA

Until each is observed, the correct report for PR4/PR5/PR6 is **"partially satisfied — in-repo half verified, infrastructure half not verified"**, never "done".
