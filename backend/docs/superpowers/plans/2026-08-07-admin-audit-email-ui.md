# Admin Audit & Email UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the `/admin` panel's audit and email surfaces around shared query scopes so operators can see what is broken and click straight to it.

**Architecture:** Triage conditions ("stuck", "needs manual action", "breaching SLA") become Eloquent query scopes on `AuditRequest` and `AuditEmailLog`, reading thresholds from existing config. A dashboard `StatsOverviewWidget` counts those scopes; resource list-page tabs hand the same scopes to `Tab::modifyQueryUsing()`. One definition serves both, so a tile and its drill-down target cannot disagree.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Larastan 3, Tailwind CSS.

**Spec:** `backend/docs/superpowers/specs/2026-08-07-admin-audit-email-ui-design.md`

## Global Constraints

- All paths below are relative to the repo root. The Laravel app lives in `backend/`.
- **All PHP commands run inside Docker.** Prefix every command with `docker compose exec laravel.test`. Working directory inside the container is the backend app root.
- **Never run two test commands concurrently.** The suite shares one database.
- **The full suite exceeds agent timeouts.** Always use `--filter` during tasks. The full run happens once, at the end.
- Tests are **PHPUnit**, not Pest. Scaffold with `php artisan make:test --phpunit {name}`. Feature tests extend `Tests\Feature\FeatureTest`; pure unit tests extend `Tests\TestCase`.
- `FeatureTest::createAdminUser()` creates an admin; `FeatureTest` seeds the DB once per process.
- Run plain `vendor/bin/pint` before finalizing — **never `pint --dirty`**, which reports a vacuous pass inside the dev container because the bind-mount excludes `.git`.
- `vendor/bin/phpstan analyse` must stay clean. `AuditRequest`'s scopes are invisible to Larastan through the parent's generic `Builder<Model>` return type; the existing code handles this with `@phpstan-ignore-next-line method.notFound` and an explanatory comment. Follow that precedent rather than weakening types.
- Every user-facing string goes through `__()`.
- Thresholds come from config, never as literals: `health.flexpick.oldest_queued_minutes` (30), `health.flexpick.oldest_analyzing_minutes` (30), `health.flexpick.mail_failure.window_hours` (24), and the new `audit.expert_review_sla_hours` (24).
- Time-boundary tests **must** call `$this->freezeTime()` first. Threshold comparisons are strict `<`, and two separate `now()` calls differ by microseconds — without frozen time, an "exactly at the threshold" test flakes.

---

### Task 1: `AuditRequest` triage scopes, relation, and SLA config

**Files:**
- Modify: `backend/config/audit.php`
- Modify: `backend/app/Models/AuditRequest.php`
- Test: `backend/tests/Feature/Models/AuditRequestScopesTest.php` (create)

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `AuditRequest::scopeStuck(Builder $query): Builder` — called as `AuditRequest::query()->stuck()`
  - `AuditRequest::scopeNeedsManualAction(Builder $query): Builder` — called as `->needsManualAction()`
  - `AuditRequest::scopeBreachingExpertReviewSla(Builder $query): Builder` — called as `->breachingExpertReviewSla()`
  - `AuditRequest::emailLogs(): HasMany` — relation name `emailLogs`, used by `->counts('emailLogs')` and `RepeatableEntry::make('emailLogs')`
  - config key `audit.expert_review_sla_hours` (int, default 24, env `AUDIT_EXPERT_REVIEW_SLA_HOURS`)

- [ ] **Step 1: Add the SLA config key**

In `backend/config/audit.php`, add after the `'free_reports_limit' => 3,` line:

```php
    // A delivery promise to the customer, not a system-health threshold —
    // which is why it lives here and not in config/health.php beside the
    // pipeline and mail windows.
    'expert_review_sla_hours' => (int) env('AUDIT_EXPERT_REVIEW_SLA_HOURS', 24),
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/Models/AuditRequestScopesTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestScopesTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
        config()->set('audit.expert_review_sla_hours', 24);
    }

    public function test_stuck_finds_a_queued_request_past_the_threshold(): void
    {
        $stuck = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subMinutes(31),
        ]);

        $this->assertSame([$stuck->id], AuditRequest::query()->stuck()->pluck('id')->all());
    }

    public function test_stuck_excludes_a_request_exactly_at_the_threshold(): void
    {
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subMinutes(30),
        ]);

        $this->assertSame(0, AuditRequest::query()->stuck()->count());
    }

    public function test_stuck_ages_an_analyzing_request_off_updated_at_when_the_start_was_never_stamped(): void
    {
        $neverStamped = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => null,
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);

        // A pipeline that died before stamping its start must still age into
        // the bucket rather than hiding there forever.
        $this->assertSame([$neverStamped->id], AuditRequest::query()->stuck()->pluck('id')->all());
    }

    public function test_stuck_prefers_analysis_started_at_over_updated_at(): void
    {
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);

        $this->assertSame(0, AuditRequest::query()->stuck()->count());
    }

    public function test_stuck_ignores_terminal_statuses(): void
    {
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'created_at' => now()->subDays(5),
        ]);

        $this->assertSame(0, AuditRequest::query()->stuck()->count());
    }

    public function test_needs_manual_action_covers_exactly_the_three_operator_statuses(): void
    {
        foreach ([
            AuditRequestStatus::NEEDS_FOLLOWUP,
            AuditRequestStatus::AWAITING_ACCESS,
            AuditRequestStatus::AWAITING_PAYMENT,
        ] as $status) {
            AuditRequest::factory()->create(['status' => $status->value]);
        }

        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        $this->assertSame(3, AuditRequest::query()->needsManualAction()->count());
    }

    public function test_breaching_expert_review_sla_finds_an_overdue_expert_review(): void
    {
        $overdue = AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(25),
        ]);

        $this->assertSame([$overdue->id], AuditRequest::query()->breachingExpertReviewSla()->pluck('id')->all());
    }

    public function test_breaching_expert_review_sla_excludes_a_review_within_the_window(): void
    {
        AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(23),
        ]);

        $this->assertSame(0, AuditRequest::query()->breachingExpertReviewSla()->count());
    }

    public function test_breaching_expert_review_sla_ignores_non_expert_tiers(): void
    {
        AuditRequest::factory()->create([
            'tier' => AuditTier::AUTOMATED->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(48),
        ]);

        $this->assertSame(0, AuditRequest::query()->breachingExpertReviewSla()->count());
    }

    public function test_email_logs_relation_returns_this_requests_messages(): void
    {
        $request = AuditRequest::factory()->create();
        AuditEmailLog::factory()->create(['audit_request_id' => $request->id]);
        AuditEmailLog::factory()->create(['audit_request_id' => null]);

        $this->assertCount(1, $request->emailLogs);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestScopesTest`

Expected: FAIL — `Call to undefined method App\Models\AuditRequest::stuck()`.

- [ ] **Step 4: Implement the scopes and relation**

In `backend/app/Models/AuditRequest.php`, add `use App\Constants\AuditRequestStatus;` to the imports (`AuditTier` and `HasMany` are already imported), then add these methods after the existing `scopeForUser`:

```php
    /**
     * Queued past the queue threshold, or analyzing past the analyzing one.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeStuck(Builder $query): Builder
    {
        $queuedCutoff = now()->subMinutes((int) config('health.flexpick.oldest_queued_minutes'));
        $analyzingCutoff = now()->subMinutes((int) config('health.flexpick.oldest_analyzing_minutes'));

        return $query->where(function (Builder $query) use ($queuedCutoff, $analyzingCutoff): void {
            $query
                ->where(function (Builder $query) use ($queuedCutoff): void {
                    $query
                        ->whereIn('status', [AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value])
                        ->where('created_at', '<', $queuedCutoff);
                })
                ->orWhere(function (Builder $query) use ($analyzingCutoff): void {
                    // COALESCE, not a plain column compare: a pipeline that died
                    // before stamping analysis_started_at leaves it null, and a
                    // null would drop the row out of the comparison entirely --
                    // hiding the very records most likely to be wedged.
                    $query
                        ->where('status', AuditRequestStatus::ANALYZING->value)
                        ->whereRaw('COALESCE(analysis_started_at, updated_at) < ?', [$analyzingCutoff]);
                });
        });
    }

    /**
     * Waiting on a human, not on the pipeline.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeNeedsManualAction(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AuditRequestStatus::NEEDS_FOLLOWUP->value,
            AuditRequestStatus::AWAITING_ACCESS->value,
            AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);
    }

    /**
     * Expert-tier reports held past the delivery promise.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeBreachingExpertReviewSla(Builder $query): Builder
    {
        return $query
            ->where('tier', AuditTier::EXPERT->value)
            ->where('status', AuditRequestStatus::EXPERT_REVIEW->value)
            ->where('analysis_completed_at', '<', now()->subHours((int) config('audit.expert_review_sla_hours')));
    }

    /**
     * @return HasMany<AuditEmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(AuditEmailLog::class)->latest('sent_at');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestScopesTest`

Expected: PASS, 10 tests.

- [ ] **Step 6: Verify static analysis and formatting**

Run: `docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse`

Expected: Pint reports the changed files formatted; PHPStan reports no errors. If PHPStan flags the scope calls in the test as `method.notFound`, add `@phpstan-ignore-next-line method.notFound` with a one-line comment, matching the precedent in `AuditRequestResource::getEloquentQuery()`.

- [ ] **Step 7: Commit**

```bash
git add backend/config/audit.php backend/app/Models/AuditRequest.php backend/tests/Feature/Models/AuditRequestScopesTest.php
git commit -m "feat(audit): add triage scopes and emailLogs relation to AuditRequest"
```

---

### Task 2: `AuditEmailLog` window scopes

**Files:**
- Modify: `backend/app/Models/AuditEmailLog.php`
- Test: `backend/tests/Feature/Models/AuditEmailLogScopesTest.php` (create)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `AuditEmailLog::scopeFailedWithin(Builder $query, ?int $hours = null): Builder` — called as `AuditEmailLog::query()->failedWithin()` or `->failedWithin(168)`
  - `AuditEmailLog::scopeAttemptedWithin(Builder $query, ?int $hours = null): Builder` — called as `->attemptedWithin(168)`
  - Both default `$hours` to `config('health.flexpick.mail_failure.window_hours')`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Models/AuditEmailLogScopesTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditEmailLog;
use Tests\Feature\FeatureTest;

class AuditEmailLogScopesTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        config()->set('health.flexpick.mail_failure.window_hours', 24);
    }

    public function test_failed_within_counts_failed_and_bounced_inside_the_default_window(): void
    {
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(2)]);
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_BOUNCED, 'sent_at' => now()->subHours(2)]);
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_DELIVERED, 'sent_at' => now()->subHours(2)]);

        $this->assertSame(2, AuditEmailLog::query()->failedWithin()->count());
    }

    public function test_failed_within_excludes_a_failure_older_than_the_window(): void
    {
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(25)]);

        $this->assertSame(0, AuditEmailLog::query()->failedWithin()->count());
    }

    public function test_failed_within_accepts_an_explicit_window(): void
    {
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(25)]);

        $this->assertSame(1, AuditEmailLog::query()->failedWithin(168)->count());
    }

    public function test_a_pending_message_with_no_sent_at_is_in_neither_scope(): void
    {
        // Not attempted is not the same as failed. Counting a queued message as
        // a delivery failure would make the rate lie in both directions.
        AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_PENDING,
            'sent_at' => null,
        ]);

        $this->assertSame(0, AuditEmailLog::query()->failedWithin()->count());
        $this->assertSame(0, AuditEmailLog::query()->attemptedWithin()->count());
    }

    public function test_attempted_within_counts_every_status_that_was_actually_sent(): void
    {
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_DELIVERED, 'sent_at' => now()->subHours(1)]);
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(1)]);
        AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_SENT, 'sent_at' => now()->subHours(30)]);

        $this->assertSame(2, AuditEmailLog::query()->attemptedWithin()->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEmailLogScopesTest`

Expected: FAIL — `Call to undefined method App\Models\AuditEmailLog::failedWithin()`.

- [ ] **Step 3: Implement the scopes**

In `backend/app/Models/AuditEmailLog.php`, add `use Illuminate\Database\Eloquent\Builder;` to the imports, then add after the `auditRequest()` relation:

```php
    /**
     * Messages that were attempted and did not land, within the window.
     *
     * @param  Builder<AuditEmailLog>  $query
     * @return Builder<AuditEmailLog>
     */
    public function scopeFailedWithin(Builder $query, ?int $hours = null): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_FAILED, self::STATUS_BOUNCED])
            ->attemptedWithin($hours);
    }

    /**
     * The delivery-rate denominator: everything actually sent in the window.
     * A row with a null sent_at has not been attempted, so it belongs to
     * neither the numerator nor the denominator.
     *
     * @param  Builder<AuditEmailLog>  $query
     * @return Builder<AuditEmailLog>
     */
    public function scopeAttemptedWithin(Builder $query, ?int $hours = null): Builder
    {
        $hours ??= (int) config('health.flexpick.mail_failure.window_hours');

        return $query
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subHours($hours));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEmailLogScopesTest`

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint
git add backend/app/Models/AuditEmailLog.php backend/tests/Feature/Models/AuditEmailLogScopesTest.php
git commit -m "feat(audit): add windowed failure and attempt scopes to AuditEmailLog"
```

---

### Task 3: Move the status-exhaustiveness invariant onto `AuditRequest`

The current `AuditAdminStatsWidget::statusBuckets()` exists only to power tiles that Task 4 deletes, but its unit test guards something worth keeping: every `AuditRequestStatus` case must be classified, so a newly added status cannot be silently ignored. This task moves the guarantee before Task 4 removes its old home. `statusBuckets()` has exactly two callers — the widget itself and that unit test — so nothing else breaks.

**Files:**
- Modify: `backend/app/Models/AuditRequest.php`
- Delete: `backend/tests/Unit/AuditAdminStatsWidgetTest.php`
- Test: `backend/tests/Unit/AuditRequestTriageTest.php` (create)

**Interfaces:**
- Consumes: `AuditRequest` from Task 1.
- Produces:
  - `AuditRequest::TRIAGE_IN_FLIGHT` / `TRIAGE_NEEDS_MANUAL_ACTION` / `TRIAGE_FAILED` / `TRIAGE_EXPERT_REVIEW` / `TRIAGE_TERMINAL` (string constants)
  - `AuditRequest::statusTriage(): array` — map of status value => triage constant

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/AuditRequestTriageTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Tests\TestCase;

class AuditRequestTriageTest extends TestCase
{
    public function test_every_status_is_classified_exactly_once(): void
    {
        $triage = AuditRequest::statusTriage();

        $allStatuses = collect(AuditRequestStatus::cases())
            ->map(fn (AuditRequestStatus $case): string => $case->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $allStatuses,
            collect(array_keys($triage))->sort()->values()->all(),
            'a new AuditRequestStatus must be given a triage class, not silently ignored',
        );
    }

    public function test_every_classification_is_a_known_triage_constant(): void
    {
        $known = [
            AuditRequest::TRIAGE_IN_FLIGHT,
            AuditRequest::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequest::TRIAGE_FAILED,
            AuditRequest::TRIAGE_EXPERT_REVIEW,
            AuditRequest::TRIAGE_TERMINAL,
        ];

        foreach (AuditRequest::statusTriage() as $status => $class) {
            $this->assertContains($class, $known, "status {$status} has an unknown triage class");
        }
    }

    public function test_the_operator_facing_classes_match_the_scopes(): void
    {
        $triage = AuditRequest::statusTriage();

        $this->assertSame(AuditRequest::TRIAGE_FAILED, $triage[AuditRequestStatus::FAILED->value]);
        $this->assertSame(AuditRequest::TRIAGE_EXPERT_REVIEW, $triage[AuditRequestStatus::EXPERT_REVIEW->value]);

        foreach ([
            AuditRequestStatus::NEEDS_FOLLOWUP,
            AuditRequestStatus::AWAITING_ACCESS,
            AuditRequestStatus::AWAITING_PAYMENT,
        ] as $status) {
            $this->assertSame(AuditRequest::TRIAGE_NEEDS_MANUAL_ACTION, $triage[$status->value]);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestTriageTest`

Expected: FAIL — `Undefined constant App\Models\AuditRequest::TRIAGE_IN_FLIGHT`.

- [ ] **Step 3: Implement the classification**

In `backend/app/Models/AuditRequest.php`, add the constants directly above `protected $fillable`:

```php
    public const TRIAGE_IN_FLIGHT = 'in_flight';

    public const TRIAGE_NEEDS_MANUAL_ACTION = 'needs_manual_action';

    public const TRIAGE_FAILED = 'failed';

    public const TRIAGE_EXPERT_REVIEW = 'expert_review';

    public const TRIAGE_TERMINAL = 'terminal';
```

And add this static method after `scopeBreachingExpertReviewSla()`:

```php
    /**
     * Every status, classified for operator triage. A test asserts this map is
     * exhaustive, so adding an AuditRequestStatus case without classifying it
     * fails the suite rather than quietly vanishing from the admin panel.
     *
     * @return array<string, string>
     */
    public static function statusTriage(): array
    {
        return [
            AuditRequestStatus::NEW->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::QUEUED->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::ANALYZING->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::PENDING_VERIFICATION->value => self::TRIAGE_IN_FLIGHT,

            AuditRequestStatus::NEEDS_FOLLOWUP->value => self::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequestStatus::AWAITING_ACCESS->value => self::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequestStatus::AWAITING_PAYMENT->value => self::TRIAGE_NEEDS_MANUAL_ACTION,

            AuditRequestStatus::FAILED->value => self::TRIAGE_FAILED,
            AuditRequestStatus::EXPERT_REVIEW->value => self::TRIAGE_EXPERT_REVIEW,

            AuditRequestStatus::REPORT_READY->value => self::TRIAGE_TERMINAL,
            AuditRequestStatus::SENT->value => self::TRIAGE_TERMINAL,
            AuditRequestStatus::HANDLED->value => self::TRIAGE_TERMINAL,
        ];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestTriageTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Delete the superseded unit test**

`statusBuckets()` itself stays for now — Task 4 removes it along with the tiles it feeds. Only its test moves here.

```bash
git rm backend/tests/Unit/AuditAdminStatsWidgetTest.php
```

- [ ] **Step 6: Verify nothing else referenced the deleted test**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestTriageTest`

Expected: PASS. (The old file is gone; no other test references it.)

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint
git add backend/app/Models/AuditRequest.php backend/tests/Unit/AuditRequestTriageTest.php
git commit -m "refactor(audit): move status-exhaustiveness invariant onto AuditRequest"
```

---

### Task 4: Rebuild `AuditAdminStatsWidget` as a problem-first ops block

**Files:**
- Modify: `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php` (full rewrite)
- Test: `backend/tests/Feature/Filament/Admin/AuditAdminStatsWidgetTest.php` (create)
- Modify: `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php` (remove the stats test — it asserts on `Total audits`, `Analyzing`, and `4m`, all of which this task deletes; the by-plan test stays for Task 5)

**Interfaces:**
- Consumes: `AuditRequest::stuck()`, `needsManualAction()`, `breachingExpertReviewSla()` (Task 1); `AuditEmailLog::failedWithin()`, `attemptedWithin()` (Task 2).
- Produces: nothing consumed by later tasks. Tile drill-down URLs reference tab keys `failed`, `stuck`, `needs-action` on `AuditRequestResource` and `failed-24h` on `AuditEmailLogResource` — Tasks 6 and 8 must create tabs under exactly those keys.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Admin/AuditAdminStatsWidgetTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminStatsWidgetTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
        config()->set('health.flexpick.mail_failure.window_hours', 24);
        config()->set('audit.expert_review_sla_hours', 24);
    }

    public function test_a_clean_system_shows_every_problem_tile_quiet_and_unlinked(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Audit operations'))
            ->assertSee(__('Failed audits'))
            ->assertSee(__('All clear'))
            // Nothing to click means nothing to chase.
            ->assertDontSee('activeTab=failed')
            ->assertDontSee('activeTab=stuck')
            ->assertDontSee('activeTab=needs-action');
    }

    public function test_a_failed_audit_lights_its_tile_and_links_to_the_failed_tab(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::FAILED->value,
            'created_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Failed audits'))
            ->assertSee('activeTab=failed');
    }

    public function test_the_failed_tile_is_windowed_to_the_last_day(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::FAILED->value,
            'created_at' => now()->subDays(3),
        ]);

        // An all-time failure count never returns to zero, so it could never go
        // quiet -- which is the property the whole block depends on.
        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('All clear'))
            ->assertDontSee('activeTab=failed');
    }

    public function test_a_stuck_audit_links_to_the_stuck_tab(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subHours(3),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Stuck in pipeline'))
            ->assertSee('activeTab=stuck');
    }

    public function test_manual_action_tile_breaks_the_count_down_by_status(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Needs manual action'))
            ->assertSee('activeTab=needs-action')
            ->assertSee('2 '.mb_strtolower(__('Awaiting repo access')))
            ->assertSee('1 '.mb_strtolower(__('Awaiting payment')));
    }

    public function test_an_overdue_expert_review_lights_its_tile(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(26),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Expert review overdue'))
            ->assertSee(__('oldest waiting :hours h', ['hours' => 26]));
    }

    public function test_email_tile_reports_the_seven_day_delivery_rate(): void
    {
        $admin = $this->createAdminUser();

        // 1 failure in 10 attempts over the week => 90% delivered.
        AuditEmailLog::factory()->count(9)->create([
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDays(3),
        ]);
        AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(3),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Email failures'))
            ->assertSee(__(':rate% delivered over 7 days', ['rate' => 90]));
    }

    public function test_the_ops_block_ignores_the_dashboard_date_filter(): void
    {
        // "What is broken" is always now. A date-ranged failure count is a trap,
        // so this widget must not opt into the page filter. Pinned structurally
        // because a later refactor would otherwise "fix" it silently.
        $this->assertNotContains(
            InteractsWithPageFilters::class,
            class_uses_recursive(AuditAdminStatsWidget::class),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminStatsWidgetTest`

Expected: FAIL — the widget still renders `Total audits` / `Pending` / `Analyzing`, so `Audit operations` and `All clear` are not found.

- [ ] **Step 3: Rewrite the widget**

Replace the entire contents of `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`:

```php
<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\ResultStores\ResultStore;
use Throwable;

/**
 * The operator's "is anything on fire" block. Deliberately does NOT use
 * InteractsWithPageFilters: the dashboard's date range applies to revenue
 * metrics, but a date-ranged failure count answers a question nobody asks
 * during triage.
 */
class AuditAdminStatsWidget extends BaseWidget
{
    /** Below the revenue widgets, which occupy sorts 0-3. */
    protected static ?int $sort = 10;

    protected ?string $pollingInterval = '60s';

    /** A rate needs a wider base than an alarm to mean anything. */
    private const DELIVERY_RATE_HOURS = 168;

    protected function getHeading(): ?string
    {
        return __('Audit operations');
    }

    protected function getDescription(): ?string
    {
        return __('Live · :freshness', ['freshness' => $this->healthFreshness()]);
    }

    protected function getStats(): array
    {
        return [
            $this->problemStat(
                label: __('Failed audits'),
                count: AuditRequest::query()
                    ->where('status', AuditRequestStatus::FAILED->value)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                color: 'danger',
                icon: 'heroicon-m-x-circle',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'failed'], panel: 'admin'),
            ),
            $this->problemStat(
                label: __('Stuck in pipeline'),
                count: AuditRequest::query()->stuck()->count(),
                color: 'danger',
                icon: 'heroicon-m-clock',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'stuck'], panel: 'admin'),
                description: __('queued >:queued m or analyzing >:analyzing m', [
                    'queued' => (int) config('health.flexpick.oldest_queued_minutes'),
                    'analyzing' => (int) config('health.flexpick.oldest_analyzing_minutes'),
                ]),
            ),
            $this->problemStat(
                label: __('Needs manual action'),
                count: AuditRequest::query()->needsManualAction()->count(),
                color: 'warning',
                icon: 'heroicon-m-hand-raised',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'needs-action'], panel: 'admin'),
                description: $this->manualActionBreakdown(),
            ),
            $this->problemStat(
                label: __('Expert review overdue'),
                count: AuditRequest::query()->breachingExpertReviewSla()->count(),
                color: 'warning',
                icon: 'heroicon-m-clipboard-document-check',
                url: ExpertReviewResource::getUrl('index', panel: 'admin'),
                description: $this->oldestBreachingReview(),
            ),
            $this->problemStat(
                label: __('Email failures'),
                count: $this->emailFailures(),
                color: 'warning',
                icon: 'heroicon-m-envelope',
                url: AuditEmailLogResource::getUrl('index', ['activeTab' => 'failed-24h'], panel: 'admin'),
                description: $this->deliveryRateDescription(),
                // The delivery rate is worth reading even when nothing failed.
                descriptionWhenQuiet: $this->deliveryRateDescription(),
            ),
            Stat::make(__('Pipeline'), $this->queueDepth())
                ->description(__('avg :time · :count audits today', [
                    'time' => $this->averageProcessingTime(),
                    'count' => AuditRequest::query()->whereDate('created_at', today())->count(),
                ]))
                ->url('/horizon', shouldOpenInNewTab: true),
        ];
    }

    /**
     * A problem tile is gray, iconless and unlinked at zero, and coloured,
     * icon-bearing and clickable when it is not. Severity has to be legible
     * before the number is read -- which is exactly what the previous ten
     * identical tiles failed to do.
     */
    private function problemStat(
        string $label,
        int $count,
        string $color,
        string $icon,
        string $url,
        ?string $description = null,
        ?string $descriptionWhenQuiet = null,
    ): Stat {
        $stat = Stat::make($label, $count);

        if ($count === 0) {
            return $stat
                ->color('gray')
                ->description($descriptionWhenQuiet ?? __('All clear'));
        }

        return $stat
            ->color($color)
            ->icon($icon)
            ->description($description)
            ->descriptionColor($color)
            ->url($url);
    }

    private function manualActionBreakdown(): ?string
    {
        $counts = AuditRequest::query()
            ->needsManualAction()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if ($counts->isEmpty()) {
            return null;
        }

        $mapper = app(AuditRequestStatusMapper::class);

        return $counts
            ->map(fn (int $count, string $status): string => $count.' '.mb_strtolower($mapper->mapForDisplay($status)))
            ->implode(' · ');
    }

    private function oldestBreachingReview(): ?string
    {
        $oldest = AuditRequest::query()->breachingExpertReviewSla()->min('analysis_completed_at');

        if ($oldest === null) {
            return null;
        }

        return __('oldest waiting :hours h', [
            'hours' => (int) Carbon::parse($oldest)->diffInHours(now(), true),
        ]);
    }

    private function emailFailures(): int
    {
        // Tolerate the table being absent so the block still renders on a
        // partial schema.
        if (! Schema::hasTable('audit_email_logs')) {
            return 0;
        }

        return AuditEmailLog::query()->failedWithin()->count();
    }

    private function deliveryRateDescription(): ?string
    {
        if (! Schema::hasTable('audit_email_logs')) {
            return null;
        }

        $attempted = AuditEmailLog::query()->attemptedWithin(self::DELIVERY_RATE_HOURS)->count();

        if ($attempted === 0) {
            return null;
        }

        $failed = AuditEmailLog::query()->failedWithin(self::DELIVERY_RATE_HOURS)->count();

        return __(':rate% delivered over 7 days', [
            'rate' => (int) round(($attempted - $failed) / $attempted * 100),
        ]);
    }

    private function averageProcessingTime(): string
    {
        $seconds = AuditRequest::query()
            ->whereNotNull('analysis_started_at')
            ->whereNotNull('analysis_completed_at')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, analysis_started_at, analysis_completed_at)) as avg_seconds'))
            ->value('avg_seconds');

        if ($seconds === null) {
            return '—';
        }

        return $seconds >= 3600
            ? round($seconds / 3600, 1).'h'
            : round($seconds / 60).'m';
    }

    private function queueDepth(): int|string
    {
        try {
            return Queue::connection('redis-audit')->size((string) config('audit.queue'));
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * The dead-man's switch: if health:check stops storing results while this
     * block keeps rendering, a frozen scheduler is visible here rather than
     * silently freezing every number above it.
     */
    private function healthFreshness(): string
    {
        try {
            $results = app(ResultStore::class)->latestResults();
        } catch (Throwable) {
            return __('health checks unavailable');
        }

        if ($results === null) {
            return __('health checks have never run');
        }

        return __('health checks last ran :minutes min ago', [
            'minutes' => (int) Carbon::instance($results->finishedAt)->diffInMinutes(now(), true),
        ]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
```

- [ ] **Step 4: Remove the superseded stats test**

In `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php`, delete the entire `test_stats_widget_counts_statuses_and_average_processing_time` method and the now-unused `use App\Filament\Admin\Widgets\AuditAdminStatsWidget;` import. Leave `test_by_plan_widget_groups_current_month_audits` untouched — Task 5 rewrites it.

- [ ] **Step 5: Run both test files to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminStatsWidgetTest`

Expected: PASS, 8 tests.

Then, separately (never concurrently):

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminWidgetsTest`

Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php backend/tests/Feature/Filament/Admin/
git commit -m "feat(admin): rebuild audit stats widget as a problem-first ops block"
```

---

### Task 5: Convert `AuditsByPlanWidget` to a filtered bar chart

**Files:**
- Modify: `backend/app/Filament/Admin/Widgets/AuditsByPlanWidget.php` (full rewrite)
- Modify: `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Replace the body of `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php` with:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\SubscriptionStatus;
use App\Filament\Admin\Widgets\AuditsByPlanWidget;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminWidgetsTest extends FeatureTest
{
    private function subscribedUser(string $planName): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $product = Product::factory()->create([
            'name' => 'Audit Growth',
            'metadata' => ['audit_analyses_per_month' => 20],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => $planName]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
        ]);

        return $user;
    }

    public function test_by_plan_chart_labels_each_plan_and_the_free_bucket(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->subscribedUser('Audit Growth Monthly');

        AuditRequest::factory()->count(2)->create(['user_id' => $user->id]);
        AuditRequest::factory()->create(); // no subscription → free

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]])
            ->assertSee('Audit Growth Monthly')
            ->assertSee(__('Free / no plan'));
    }

    public function test_by_plan_chart_respects_the_dashboard_date_filter(): void
    {
        // Unlike the ops block, this is a metric rather than an alarm, so the
        // page's date range must apply. Pinned so the asymmetry stays deliberate.
        $this->assertContains(
            InteractsWithPageFilters::class,
            class_uses_recursive(AuditsByPlanWidget::class),
        );

        $admin = $this->createAdminUser();
        $user = $this->subscribedUser('Audit Scale Monthly');

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subMonths(6),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]])
            ->assertDontSee('Audit Scale Monthly');
    }

    public function test_by_plan_chart_renders_with_no_audits_at_all(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]])
            ->assertSuccessful()
            ->assertSee(__('No audits in the selected period.'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminWidgetsTest`

Expected: FAIL — `AuditsByPlanWidget` is still a `StatsOverviewWidget` and does not use `InteractsWithPageFilters`.

- [ ] **Step 3: Rewrite the widget as a chart**

Replace the entire contents of `backend/app/Filament/Admin/Widgets/AuditsByPlanWidget.php`:

```php
<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * A bar chart rather than a stat list: one Stat per plan produced a grid that
 * went ragged the moment the plan count was not a multiple of the column count.
 * A chart handles one plan or nine identically.
 */
class AuditsByPlanWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 11;

    protected ?string $pollingInterval = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('Audits by plan');
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->countsByPlan()->isEmpty()
            ? __('No audits in the selected period.')
            : __('Audit volume grouped by the submitter\'s active plan.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $byPlan = $this->countsByPlan();

        return [
            'datasets' => [
                [
                    'label' => __('Audits'),
                    'data' => $byPlan->values()->all(),
                ],
            ],
            'labels' => $byPlan->keys()->all(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function countsByPlan(): \Illuminate\Support\Collection
    {
        $startDate = $this->pageFilters['start_date'] ?? null;
        $endDate = $this->pageFilters['end_date'] ?? null;

        return AuditRequest::query()
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay()))
            ->when($endDate, fn ($query) => $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay()))
            ->with(['user.subscriptions' => fn ($query) => $query
                ->where('status', SubscriptionStatus::ACTIVE->value)
                ->where('ends_at', '>', now())
                ->with('plan'), ])
            ->get()
            ->groupBy(function (AuditRequest $audit): string {
                return $audit->user?->subscriptions->first()?->plan?->name ?? __('Free / no plan');
            })
            ->map->count()
            ->sortDesc();
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminWidgetsTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Admin/Widgets/AuditsByPlanWidget.php backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php
git commit -m "feat(admin): convert audits-by-plan widget to a filtered bar chart"
```

---

### Task 6: Audit Requests list — triage tabs and table urgency

**Files:**
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/Pages/ListAuditRequests.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` (the `table()` method only)
- Modify: `backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `AuditRequest::stuck()`, `needsManualAction()`, `emailLogs()` (Task 1).
- Produces: tab keys `all`, `needs-action`, `failed`, `stuck`, `expert-review` — the exact keys Task 4's tile URLs point at.

- [ ] **Step 1: Write the failing test**

Append these methods to `backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` (add any missing imports: `App\Constants\AuditRequestStatus`, `App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests`, `App\Models\AuditEmailLog`, `App\Models\AuditRequest`, `Livewire\Livewire`):

```php
    public function test_the_stuck_tab_shows_only_stuck_requests(): void
    {
        $this->freezeTime();
        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);

        $admin = $this->createAdminUser();

        $stuck = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'repo_url' => 'https://github.com/example/wedged',
            'created_at' => now()->subHours(3),
        ]);
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'repo_url' => 'https://github.com/example/fine',
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'stuck')
            ->assertCanSeeTableRecords([$stuck])
            ->assertCountTableRecords(1);
    }

    public function test_the_needs_action_tab_shows_only_operator_blocked_requests(): void
    {
        $admin = $this->createAdminUser();

        $blocked = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'needs-action')
            ->assertCanSeeTableRecords([$blocked])
            ->assertCountTableRecords(1);
    }

    public function test_the_failed_tab_shows_only_failed_requests(): void
    {
        $admin = $this->createAdminUser();

        $failed = AuditRequest::factory()->create(['status' => AuditRequestStatus::FAILED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCountTableRecords(1);
    }

    public function test_the_all_tab_is_the_default_and_shows_everything(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->count(3)->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->assertSet('activeTab', 'all')
            ->assertCountTableRecords(3);
    }

    public function test_the_table_shows_a_related_email_count(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['repo_url' => 'https://github.com/example/mailed']);
        AuditEmailLog::factory()->count(2)->create(['audit_request_id' => $request->id]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertTableColumnStateSet('email_logs_count', 2, $request);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest`

Expected: FAIL — `activeTab` cannot be set to `stuck` because `getTabs()` returns `[]`, and the `email_logs_count` column does not exist.

- [ ] **Step 3: Add the tabs**

Replace the contents of `backend/app/Filament/Admin/Resources/AuditRequests/Pages/ListAuditRequests.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditRequests extends ListRecords
{
    use ListDefaults;

    protected static string $resource = AuditRequestResource::class;

    /**
     * Tab keys are load-bearing: AuditAdminStatsWidget's tiles link to
     * ?activeTab=<key>, so renaming one silently breaks a drill-down.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),

            'needs-action' => Tab::make(__('Needs action'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->needsManualAction())
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->needsManualAction()->count())
                ->badgeColor('warning')
                ->deferBadge(),

            'failed' => Tab::make(__('Failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditRequestStatus::FAILED->value))
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->where('status', AuditRequestStatus::FAILED->value)->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'stuck' => Tab::make(__('Stuck'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->stuck())
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->stuck()->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'expert-review' => Tab::make(__('Expert review'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditRequestStatus::EXPERT_REVIEW->value))
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->where('status', AuditRequestStatus::EXPERT_REVIEW->value)->count())
                ->badgeColor('warning')
                ->deferBadge(),
        ];
    }
}
```

If `badgeColor()` is not available on `Tab` in this Filament version, drop those four calls — the badge still renders, just in the default colour. Confirm with:
`docker compose exec laravel.test grep -rn "function badgeColor" vendor/filament/support/src/Concerns/HasBadge.php`

- [ ] **Step 4: Add the table columns and row tinting**

In `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`, inside `table()`, add these two columns to the end of the `columns([...])` array (after the existing `source` column):

```php
                TextColumn::make('created_at')
                    ->label(__('Age'))
                    ->since()
                    ->sortable(),
                TextColumn::make('email_logs_count')
                    ->label(__('Emails'))
                    ->counts('emailLogs')
                    ->badge()
                    ->color(fn (AuditRequest $record): string => $record->emailLogs->contains(
                        fn ($log): bool => in_array($log->status, [AuditEmailLog::STATUS_FAILED, AuditEmailLog::STATUS_BOUNCED], true),
                    ) ? 'danger' : 'gray'),
```

Add `use App\Models\AuditEmailLog;` to the imports.

Then add `recordClasses()` immediately before `->defaultSort('created_at', 'desc')`:

```php
            ->recordClasses(fn (AuditRequest $record): ?string => match (true) {
                $record->status === AuditRequestStatus::FAILED->value => 'bg-danger-50 dark:bg-danger-500/10',
                $record->status === AuditRequestStatus::ANALYZING->value => null,
                default => null,
            })
```

- [ ] **Step 5: Eager-load the relation the colour callback reads**

The `email_logs_count` colour callback touches `$record->emailLogs`, which would fire one query per row. In the same file, add a `modifyQueryUsing` on the table or extend the resource's `getEloquentQuery()`. Add this method to `AuditRequestResource` (the admin one, which currently has none):

```php
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('emailLogs');
    }
```

Add `use Illuminate\Database\Eloquent\Builder;` if not already imported (it is — the filters use it).

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest`

Expected: PASS. If `assertTableColumnStateSet` is unavailable in this Filament version, replace that assertion with `->assertSee('2')` scoped by first asserting the repo URL is visible.

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Admin/Resources/AuditRequests/ backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php
git commit -m "feat(admin): add triage tabs and urgency columns to audit requests"
```

---

### Task 7: Audit Requests infolist — split sections and render a real timeline

**Files:**
- Create: `backend/resources/views/filament/admin/audit/pipeline-timeline.blade.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` (the `infolist()` method only)
- Modify: `backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `AuditRequest::emailLogs()` (Task 1).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` (add `use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;`):

```php
    public function test_the_view_page_renders_the_pipeline_log_as_a_timeline(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create([
            'pipeline_log' => [
                ['step' => 'clone', 'message' => 'Cloned 1200 files', 'at' => now()->subMinutes(10)->toIso8601String()],
                ['step' => 'analyze_failed', 'message' => 'AI returned unparseable JSON', 'at' => now()->subMinutes(4)->toIso8601String()],
            ],
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Timeline'))
            ->assertSee('clone')
            ->assertSee('Cloned 1200 files')
            ->assertSee('analyze_failed')
            ->assertSee('AI returned unparseable JSON');
    }

    public function test_a_half_written_pipeline_entry_renders_instead_of_throwing(): void
    {
        $admin = $this->createAdminUser();

        // The pipeline may die mid-write, so malformed entries are expected
        // input rather than corruption.
        $request = AuditRequest::factory()->create([
            'pipeline_log' => [
                ['message' => 'no step key'],
                ['step' => 'clone', 'message' => 'bad timestamp', 'at' => 'not-a-date'],
                'a bare string, not an array',
            ],
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('bad timestamp');
    }

    public function test_an_empty_pipeline_log_shows_the_placeholder(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['pipeline_log' => []]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('No processing activity recorded yet.'));
    }

    public function test_the_view_page_lists_this_requests_emails(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create();
        AuditEmailLog::factory()->create([
            'audit_request_id' => $request->id,
            'recipient' => 'infolist-target@example.com',
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Emails'))
            ->assertSee('infolist-target@example.com');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest`

Expected: FAIL — no `Timeline` section exists; the log renders as one monospace blob under `Processing log`.

- [ ] **Step 3: Create the timeline view**

Create `backend/resources/views/filament/admin/audit/pipeline-timeline.blade.php`:

```blade
@php
    // The pipeline writes this log incrementally and may die mid-write, so every
    // field here is treated as optional rather than guaranteed.
    $entries = collect($getState() ?? [])->filter(fn ($entry): bool => is_array($entry))->values();
@endphp

@if ($entries->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No processing activity recorded yet.') }}</p>
@else
    <ol class="relative ms-1 space-y-4 border-s border-gray-200 ps-6 dark:border-gray-700">
        @foreach ($entries as $entry)
            @php
                $step = (string) ($entry['step'] ?? __('unknown step'));
                $isFailure = str_contains(mb_strtolower($step), 'fail');

                try {
                    $at = isset($entry['at']) ? \Illuminate\Support\Carbon::parse($entry['at']) : null;
                } catch (\Throwable) {
                    $at = null;
                }
            @endphp

            <li class="relative">
                <span @class([
                    'absolute -start-[1.9rem] mt-1.5 size-3 rounded-full ring-4 ring-white dark:ring-gray-900',
                    'bg-danger-500' => $isFailure,
                    'bg-primary-500' => ! $isFailure,
                ])></span>

                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span @class([
                        'text-sm font-medium',
                        'text-danger-600 dark:text-danger-400' => $isFailure,
                        'text-gray-950 dark:text-white' => ! $isFailure,
                    ])>{{ $step }}</span>

                    <span
                        class="text-xs text-gray-500 dark:text-gray-400"
                        @if ($at) title="{{ $at->toDateTimeString() }}" @endif
                    >{{ $at?->diffForHumans() ?? (string) ($entry['at'] ?? '—') }}</span>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $entry['message'] ?? '' }}</p>
            </li>
        @endforeach
    </ol>
@endif
```

- [ ] **Step 4: Rewrite the infolist**

In `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`, replace the whole `infolist()` method:

```php
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Request'))->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('repo_url')->label(__('Repository')),
                TextEntry::make('message')->placeholder('—'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextEntry::make('tier'),
                TextEntry::make('source'),
                TextEntry::make('marketing_consent'),
                TextEntry::make('email_verified_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('tenants')
                    ->label(__('Company / workspaces'))
                    ->state(fn (AuditRequest $record): string => $record->user?->tenants()->pluck('name')->implode(', ') ?: '—'),
                TextEntry::make('admin_context')
                    ->label(__('Additional analysis context'))
                    ->placeholder('—'),
            ]),

            Section::make(__('Timeline'))->schema([
                TextEntry::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->color('danger')
                    ->visible(fn (AuditRequest $record): bool => $record->failure_reason !== null),
                TextEntry::make('analysis_started_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('analysis_completed_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                ViewEntry::make('pipeline_log')
                    ->label('')
                    ->view('filament.admin.audit.pipeline-timeline')
                    ->columnSpanFull(),
            ]),

            Section::make(__('Results'))
                ->visible(fn (AuditRequest $record): bool => $record->report !== null)
                ->schema([
                    TextEntry::make('report.uuid')->label(__('Report')),
                    TextEntry::make('overall_score')
                        ->label(__('Overall score'))
                        ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                ]),

            Section::make(__('Emails'))
                ->visible(fn (AuditRequest $record): bool => $record->emailLogs->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('emailLogs')
                        ->label('')
                        ->schema([
                            TextEntry::make('recipient'),
                            TextEntry::make('mailable')->label(__('Notification'))->badge()->color('gray'),
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    AuditEmailLog::STATUS_DELIVERED => 'success',
                                    AuditEmailLog::STATUS_SENT => 'info',
                                    AuditEmailLog::STATUS_PENDING => 'gray',
                                    default => 'danger',
                                }),
                            TextEntry::make('attempts'),
                            TextEntry::make('sent_at')->label(__('Last attempt'))->dateTime(config('app.datetime_format'))->placeholder('—'),
                        ])
                        ->columns(5),
                ]),

            Section::make(__('Next-run prompt preview'))->collapsed()->schema([
                TextEntry::make('prompt_preview')
                    ->label('')
                    ->state(fn (AuditRequest $record): string => app(PromptComposer::class)->preview($record))
                    ->markdown(false)
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;']),
            ]),
        ]);
    }
```

Add these imports: `use Filament\Infolists\Components\RepeatableEntry;` and `use Filament\Infolists\Components\ViewEntry;`. (`AuditEmailLog` and `AuditRequestStatusMapper` were already imported in Task 6 / the existing file.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/resources/views/filament/admin/audit/ backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php backend/tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php
git commit -m "feat(admin): split audit request infolist and render pipeline log as a timeline"
```

---

### Task 8: Audit Emails — back-link, tabs, sandboxed preview, health strip

**Files:**
- Create: `backend/resources/views/filament/admin/audit/email-preview.blade.php`
- Create: `backend/app/Filament/Admin/Resources/AuditEmailLogs/Widgets/AuditEmailHealthWidget.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`
- Modify: `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`

**Interfaces:**
- Consumes: `AuditEmailLog::failedWithin()`, `attemptedWithin()` (Task 2).
- Produces: tab key `failed-24h` — the exact key Task 4's email tile links to.

The health widget lives under `Resources/AuditEmailLogs/Widgets/`, not `app/Filament/Admin/Widgets/`. `AdminPanelProvider` calls `discoverWidgets(in: app_path('Filament/Admin/Widgets'))`, so anything placed there would also appear on the dashboard. This matches the existing `Resources/Referrals/Widgets/ReferralStatsOverview` convention.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php` (add `use App\Models\AuditRequest;`):

```php
    public function test_the_failed_24h_tab_shows_only_recent_failures(): void
    {
        $this->freezeTime();
        config()->set('health.flexpick.mail_failure.window_hours', 24);

        $admin = $this->createAdminUser();

        $recent = AuditEmailLog::factory()->create([
            'recipient' => 'recent-failure@example.com',
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subHours(2),
        ]);
        AuditEmailLog::factory()->create([
            'recipient' => 'old-failure@example.com',
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(4),
        ]);
        AuditEmailLog::factory()->create([
            'recipient' => 'fine@example.com',
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->set('activeTab', 'failed-24h')
            ->assertCanSeeTableRecords([$recent])
            ->assertCountTableRecords(1);
    }

    public function test_the_table_links_a_log_back_to_its_audit_request(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['repo_url' => 'https://github.com/example/linked']);
        AuditEmailLog::factory()->create(['audit_request_id' => $request->id]);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('https://github.com/example/linked');
    }

    public function test_the_preview_action_is_hidden_when_no_body_was_stored(): void
    {
        $admin = $this->createAdminUser();

        $empty = AuditEmailLog::factory()->create(['recipient' => 'empty@example.com', 'body' => '']);
        $stored = AuditEmailLog::factory()->create(['recipient' => 'stored@example.com', 'body' => '<p>hi</p>']);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->assertTableActionHidden('preview', $empty)
            ->assertTableActionVisible('preview', $stored);
    }

    public function test_the_preview_renders_the_body_inside_a_sandboxed_iframe(): void
    {
        $admin = $this->createAdminUser();

        $log = AuditEmailLog::factory()->create([
            'recipient' => 'preview@example.com',
            'subject' => 'Your report',
            'body' => '<style>body{color:red}</style><p>stored body</p>',
        ]);

        // Stored bodies are complete HTML documents. Rendering one inline would
        // bleed its CSS across the admin panel and run whatever it contains.
        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->mountTableAction('preview', $log)
            ->assertSee('sandbox', escape: false)
            ->assertSee('srcdoc', escape: false)
            ->assertDontSee('<style>body{color:red}</style>', escape: false);
    }

    public function test_the_header_strip_reports_the_seven_day_delivery_rate(): void
    {
        $this->freezeTime();

        $admin = $this->createAdminUser();

        AuditEmailLog::factory()->count(9)->create([
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDays(2),
        ]);
        AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Delivered (7 days)'))
            ->assertSee('90%');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEmailLogResourceTest`

Expected: FAIL — no tabs, no repository column, no `preview` action, no header widget.

- [ ] **Step 3: Create the sandboxed preview view**

Create `backend/resources/views/filament/admin/audit/email-preview.blade.php`:

```blade
{{--
    srcdoc + a valueless sandbox attribute is the whole point of this file: the
    stored body is a complete HTML document with its own <style> block, so
    rendering it inline would bleed CSS across the admin panel and execute
    whatever the template contains. A bare `sandbox` denies scripts, forms and
    same-origin access.
--}}
<iframe
    sandbox
    srcdoc="{{ $body }}"
    title="{{ __('Email preview') }}"
    class="h-[60vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
></iframe>
```

- [ ] **Step 4: Create the health strip widget**

Create `backend/app/Filament/Admin/Resources/AuditEmailLogs/Widgets/AuditEmailHealthWidget.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Widgets;

use App\Models\AuditEmailLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Same window and same scopes as AuditAdminStatsWidget's email tile, so the
 * dashboard and this page cannot report different numbers.
 */
class AuditEmailHealthWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    private const WINDOW_HOURS = 168;

    protected function getStats(): array
    {
        $attempted = AuditEmailLog::query()->attemptedWithin(self::WINDOW_HOURS)->count();
        $failed = AuditEmailLog::query()->failedWithin(self::WINDOW_HOURS)->count();

        $rate = $attempted === 0
            ? '—'
            : round(($attempted - $failed) / $attempted * 100).'%';

        return [
            Stat::make(__('Delivered (7 days)'), $rate)
                ->color($attempted > 0 && $failed / $attempted > 0.25 ? 'danger' : 'success'),
            Stat::make(__('Attempted (7 days)'), $attempted)->color('gray'),
            Stat::make(__('Failed (7 days)'), $failed)->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
```

- [ ] **Step 5: Add tabs and the header widget to the list page**

Replace the contents of `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Widgets\AuditEmailHealthWidget;
use App\Models\AuditEmailLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            AuditEmailHealthWidget::class,
        ];
    }

    /**
     * The 'failed-24h' key is load-bearing: AuditAdminStatsWidget's email tile
     * links to ?activeTab=failed-24h.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),

            'failed-24h' => Tab::make(__('Failed (24h)'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->failedWithin())
                ->badge(fn (): int => AuditEmailLog::query()->failedWithin()->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'bounced' => Tab::make(__('Bounced'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditEmailLog::STATUS_BOUNCED)),

            'pending' => Tab::make(__('Pending'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditEmailLog::STATUS_PENDING)),
        ];
    }
}
```

- [ ] **Step 6: Add the repository column and preview action**

In `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`:

Add to the imports: `use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;` and `use Illuminate\Database\Eloquent\Builder;`.

Add a repository column as the **first** entry of `columns([...])`:

```php
                TextColumn::make('auditRequest.repo_url')
                    ->label(__('Repository'))
                    ->limit(40)
                    ->placeholder('—')
                    ->url(fn (AuditEmailLog $record): ?string => $record->auditRequest === null
                        ? null
                        : AuditRequestResource::getUrl('view', ['record' => $record->auditRequest], panel: 'admin'))
                    ->searchable(),
```

Add eager loading so that column does not fire a query per row — add this method to the resource:

```php
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('auditRequest');
    }
```

Add the preview action as the **first** entry of `recordActions([...])`, before the existing `resend`:

```php
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->visible(fn (AuditEmailLog $record): bool => $record->body !== '')
                    ->modalHeading(fn (AuditEmailLog $record): string => $record->subject !== '' ? $record->subject : __('Email preview'))
                    ->modalContent(fn (AuditEmailLog $record) => view('filament.admin.audit.email-preview', ['body' => $record->body]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEmailLogResourceTest`

Expected: PASS, 8 tests.

- [ ] **Step 8: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint && docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Admin/Resources/AuditEmailLogs/ backend/resources/views/filament/admin/audit/email-preview.blade.php backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php
git commit -m "feat(admin): add back-link, failure tabs and sandboxed preview to audit emails"
```

---

### Task 9: Navigation group registration and funnel-page styling

The admin panel's seven registered groups each carry an icon while their resources carry none (`OrderResource`, `UserResource` declare only `getNavigationGroup()`). `AuditEmailLogResource` and `ExpertReviewResource` break that pattern by declaring `navigationIcon`, which is the exact collision noted in `DashboardPanelProvider` — Filament objects when a group and its items both carry icons. Moving the icon to the group fixes the missing-icon inconsistency and the collision at once.

**Files:**
- Modify: `backend/app/Providers/Filament/AdminPanelProvider.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`
- Modify: `backend/app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php`
- Modify: `backend/resources/views/filament/admin/pages/audit-funnel.blade.php`
- Test: `backend/tests/Feature/Filament/Admin/Page/AuditFunnelPageTest.php` (extend)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Filament/Admin/Page/AuditFunnelPageTest.php`:

```php
    public function test_the_funnel_table_uses_filament_styling_not_raw_borders(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin);

        $response = $this->get('/admin/audit-funnel');

        $response->assertSuccessful();
        // The hand-rolled `border-t` table was the visual outlier on the page.
        $this->assertStringNotContainsString('class="border-t"', $response->getContent());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditFunnelPageTest`

Expected: FAIL — the view still emits `<tr class="border-t">`.

- [ ] **Step 3: Register the Audits navigation group**

In `backend/app/Providers/Filament/AdminPanelProvider.php`, add as the **first** entry of the `navigationGroups([...])` array:

```php
                NavigationGroup::make()
                    ->label(fn () => (__('Audits')))
                    ->icon('heroicon-s-document-magnifying-glass')
                    ->collapsed(),
```

- [ ] **Step 4: Remove the colliding resource icons**

In `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`, delete the line:

```php
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';
```

In `backend/app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php`, delete the line:

```php
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
```

The icon now lives on the group, matching `OrderResource` and `UserResource`.

- [ ] **Step 5: Restyle the funnel table**

Replace the contents of `backend/resources/views/filament/admin/pages/audit-funnel.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 font-medium">{{ __('Stage') }}</th>
                        <th class="py-2 font-medium">{{ __('Last 7 days') }}</th>
                        <th class="py-2 font-medium">{{ __('Last 30 days') }}</th>
                        <th class="py-2 font-medium">{{ __('% of submitted (30d)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($last30 as $stage => $count)
                        <tr>
                            <td class="py-2 font-medium text-gray-950 dark:text-white">{{ $stage }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $last7[$stage] }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $count }}</td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                {{ $last30['submitted'] > 0 ? round($count / $last30['submitted'] * 100) . '%' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('submitted → verified → queued → report_sent → report_viewed → unlock_started → unlock_paid is the paid-report funnel; awaiting_payment, run_purchased and failed are side branches.') }}
        </p>
    </x-filament::section>
</x-filament-panels::page>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditFunnelPageTest`

Expected: PASS.

- [ ] **Step 7: Verify the admin panel still boots with the new group**

Run: `docker compose exec laravel.test php artisan test --filter=AuditEmailLogResourceTest`

Expected: PASS. A group/item icon collision surfaces as an exception when the panel renders navigation, so any list-page test passing confirms the registration is valid.

- [ ] **Step 8: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint
git add backend/app/Providers/Filament/AdminPanelProvider.php backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php backend/app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php backend/resources/views/filament/admin/pages/audit-funnel.blade.php backend/tests/Feature/Filament/Admin/Page/AuditFunnelPageTest.php
git commit -m "style(admin): register Audits nav group and restyle the funnel table"
```

---

### Task 10: Full verification

**Files:** none modified unless a regression surfaces.

- [ ] **Step 1: Formatting gate**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

Expected: `PASS`. This is the same command `.github/workflows/ci.yml` runs.

- [ ] **Step 2: Static analysis**

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`

Expected: `[OK] No errors`.

- [ ] **Step 3: Full suite**

Run: `docker compose exec laravel.test php artisan test --compact`

Expected: all green. This run is long — do not run anything else against the database while it executes.

- [ ] **Step 4: Fix any regression, then re-run**

The likeliest breakages are tests that asserted on the old ten-tile widget copy, or dashboard-panel tests that share `AuditRequest` factory states. Fix the assertion or the code as the failure indicates, then re-run only the failing filter before repeating Step 3.

- [ ] **Step 5: Manual smoke check**

Visit `/admin` and confirm: the audit block sits below the revenue widgets, problem tiles are gray when clean, and each lit tile links to a tab that loads. Then visit `/admin/audit-requests` (tabs switch, badges show counts), open one record (Timeline renders as a rail, Emails section lists messages), and `/admin/audit-email-logs` (repository links back, Preview opens in a bordered frame without disturbing the panel's styling).

- [ ] **Step 6: Commit any fixes**

```bash
git add -A backend/
git commit -m "fix(admin): resolve regressions from the audit UI rework"
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
| --- | --- |
| §1 scopes, relation, SLA config | 1, 2 |
| §1 status triage classification | 3 |
| §2 six-tile ops block, sort, quiet-at-zero, freshness line, polling | 4 |
| §2 by-plan chart, respects filter | 5 |
| §3 Audit Requests tabs, Age/Emails columns, recordClasses | 6 |
| §3 infolist split, timeline view | 7 |
| §3 Audit Emails back-link, tabs, header widget, sandboxed preview | 8 |
| §3 tidying — nav group, funnel styling, expert-review labels | 9 |
| §4 error handling (degradation) | 4 (widget guards), 7 (malformed log) |
| §5 testing | every task; 10 for the full gate |

Two spec details resolved during planning rather than left ambiguous:

- **Expert Reviews "label consistency"** turned out to be the `navigationIcon` collision, which Task 9 fixes by moving the icon to the group. No label edits were needed — the existing labels already read correctly.
- **`Stat::url()` on a quiet tile** — the spec said "no link"; implemented by returning early from `problemStat()` before `->url()` is applied, which is also what makes `assertDontSee('activeTab=…')` a valid zero-state assertion.

**Placeholder scan:** clean — every step carries the actual code, command, and expected output. Two steps carry an explicit conditional fallback (`badgeColor()` in Task 6 Step 3, `assertTableColumnStateSet` in Task 6 Step 6) with the exact verification command; these are version-probe branches, not unspecified work.

**Type consistency:** verified across tasks — `stuck()` / `needsManualAction()` / `breachingExpertReviewSla()` / `emailLogs` / `failedWithin()` / `attemptedWithin()` are spelled identically everywhere they appear, and the four tab keys used in Task 4's URLs (`failed`, `stuck`, `needs-action`, `failed-24h`) match the keys defined in Tasks 6 and 8.
