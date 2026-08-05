# Expert Review Workflow (Phase 13) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a human review gate for the `expert` audit tier: the pipeline holds the report instead of auto-sending it, an operator edits/approves findings in a dedicated Filament queue, and a publish action sends the reviewed report.

**Architecture:** A new `expert_review` status branches the existing pipeline's delivery step (`AuditReportService::createAndDeliver()`) between persistence and email. A new optional `expert_review` payload key (schema v4) carries the reviewer's summary and attribution. A new, permission-gated Filament resource (`ExpertReviewResource`) is the queue and editing surface, backed by `AuditRequest` but writing through to the associated `AuditReport`'s payload.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, PHPUnit 11 (classic `TestCase`, not Pest), spatie/laravel-permission 6.25.

## Global Constraints

- Tests run inside Docker: `docker compose exec laravel.test php artisan test --filter=<Name>`. Reserve the full suite for the final task. **One test command at a time** — concurrent runs collide on the test database.
- Use `php artisan make:test --phpunit <Name>` to scaffold new test files so they're wired as classic PHPUnit `TestCase` classes, not Pest.
- Format with plain `vendor/bin/pint` (never `--dirty` inside the container — the bind-mount excludes `.git`, so `--dirty` silently checks nothing). Verify with `vendor/bin/pint --test` before the final commit.
- `vendor/bin/phpstan analyse` must report `[OK] No errors` against the frozen baseline (`backend/phpstan-baseline.neon`) — no new errors introduced.
- `ReportPayload::validate()` must stay tier-agnostic — it validates shape only, never branches on `AuditTier`.
- Business logic belongs in Services (`AuditReportService`, not `AuditPipeline` or Filament pages).
- No migration is needed anywhere in this plan: `audit_requests.status` is a plain `string` column (not a DB enum), and `audit_reports.payload` is already a JSON column — both absorb new values/keys with zero schema change.
- All commands below assume the working directory is `backend/` unless stated otherwise.

---

## File Structure

New files:

| File | Responsibility |
| --- | --- |
| `app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php` | The review queue resource: permission gate, table, form |
| `app/Filament/Admin/Resources/ExpertReviews/Pages/ListExpertReviews.php` | Queue list page |
| `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php` | Structured editing page + Publish action |
| `resources/views/reports/partials/expert-review.blade.php` | Shared human-verified rendering partial |
| `tests/Feature/Services/AuditReportServiceTest.php` | Service-level coverage for `createAndDeliver()` / `publish()` |
| `tests/Unit/AuditAdminStatsWidgetTest.php` | Bucket-exhaustiveness coverage (admin widget) |
| `tests/Feature/Filament/Dashboard/AuditStatsWidgetBucketsTest.php` | Bucket-exhaustiveness coverage (dashboard widget) |
| `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php` | Queue access, editing, and publish end to end |
| `tests/Unit/RolesAndPermissionsSeederTest.php` | Confirms the new permission is seeded and granted to `admin` (created only if no existing seeder test is found — see Task 7) |

Modified files (grouped by task below): `AuditRequestStatus.php`, `AuditRequestStatusMapper.php`, Dashboard `AuditRequestResource.php`, `AuditRequestController.php`, `AuditAdminStatsWidget.php`, `AuditStatsWidget.php`, Admin `AuditRequestResource.php`, `ReportPayload.php`, `AuditReportService.php`, `AuditPipeline.php`, `RolesAndPermissionsSeeder.php`, `reports/audit-web.blade.php`, `reports/audit.blade.php`, plus their existing test files.

---

### Task 1: `expert_review` status, its display mapper, and admin retry visibility

**Files:**
- Modify: `app/Constants/AuditRequestStatus.php`
- Modify: `app/Mapper/AuditRequestStatusMapper.php`
- Modify: `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`
- Test: `tests/Unit/AuditRequestStatusMapperTest.php`
- Test: `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Produces: `AuditRequestStatus::EXPERT_REVIEW` (value `'expert_review'`), consumed by every later task.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/AuditRequestStatusMapperTest.php`:

```php
public function test_maps_expert_review(): void
{
    $mapper = new AuditRequestStatusMapper;

    $this->assertSame('Awaiting expert review', $mapper->mapForDisplay('expert_review'));
    $this->assertSame('warning', $mapper->mapColor('expert_review'));
}
```

Add to `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` (an admin can re-run the whole pipeline on a held expert-review report if something looks wrong pre-review):

```php
    public function test_retry_action_is_visible_for_expert_review_status(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $record = AuditRequest::factory()->create([
            'repo_url' => 'https://example.com/repo.git',
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);

        Livewire::actingAs($this->createAdminUser())
            ->test(ListAuditRequests::class)
            ->callTableAction('retry', $record);

        $record->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $record->status);
        Queue::assertPushed(GenerateAuditReport::class);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run (inside the container): `docker compose exec laravel.test php artisan test --filter=test_maps_expert_review`
Expected: FAIL — `mapForDisplay('expert_review')` falls through to the `default => $status` arm and returns `'expert_review'`, not `'Awaiting expert review'`.

Run: `docker compose exec laravel.test php artisan test --filter=test_retry_action_is_visible_for_expert_review_status`
Expected: FAIL — `retry`'s `visible()` closure's `in_array` doesn't include `EXPERT_REVIEW->value` yet, so the table action isn't callable.

- [ ] **Step 3: Add the enum case**

In `app/Constants/AuditRequestStatus.php`, add after `AWAITING_PAYMENT`:

```php
    case EXPERT_REVIEW = 'expert_review';
```

- [ ] **Step 4: Wire the mapper**

In `app/Mapper/AuditRequestStatusMapper.php`, add to `mapForDisplay()`'s match, after the `AWAITING_PAYMENT` line:

```php
            AuditRequestStatus::EXPERT_REVIEW->value => __('Awaiting expert review'),
```

And to `mapColor()`'s match, add `AuditRequestStatus::EXPERT_REVIEW->value` to the `warning` group (same line as `NEEDS_FOLLOWUP`/`AWAITING_ACCESS`/`AWAITING_PAYMENT`):

```php
            AuditRequestStatus::NEEDS_FOLLOWUP->value, AuditRequestStatus::AWAITING_ACCESS->value, AuditRequestStatus::AWAITING_PAYMENT->value, AuditRequestStatus::EXPERT_REVIEW->value => 'warning',
```

- [ ] **Step 5: Add `EXPERT_REVIEW` to the retry action's visibility**

In `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`, in `table()`'s `retry` action, change:

```php
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::FAILED->value,
                        AuditRequestStatus::NEEDS_FOLLOWUP->value,
                        AuditRequestStatus::REPORT_READY->value,
                    ], true))
```

to:

```php
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::FAILED->value,
                        AuditRequestStatus::NEEDS_FOLLOWUP->value,
                        AuditRequestStatus::REPORT_READY->value,
                        AuditRequestStatus::EXPERT_REVIEW->value,
                    ], true))
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestStatusMapperTest`
Expected: PASS (all methods in the class, including the pre-existing `test_every_case_has_display_and_color`, which now also exercises the new case).

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestResourceTest`
Expected: PASS, all methods in the admin resource test file (the pre-existing `launch`-action tests are unaffected).

- [ ] **Step 7: Commit**

```bash
git add app/Constants/AuditRequestStatus.php app/Mapper/AuditRequestStatusMapper.php app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php tests/Unit/AuditRequestStatusMapperTest.php tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php
git commit -m "feat(audit): add the expert_review status, its display mapping, and retry visibility"
```

---

### Task 2: Customer-facing copy for `expert_review`

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`
- Modify: `app/Http/Controllers/AuditRequestController.php`
- Test: `tests/Feature/Http/Controllers/AuditRequestStatusTest.php`

**Interfaces:**
- Consumes: `AuditRequestStatus::EXPERT_REVIEW` (Task 1).
- Produces: no new methods; extends existing `statusDescription()` and `label()` matches.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Http/Controllers/AuditRequestStatusTest.php` (which already covers `status()`/`statusJson()` and already has the `signedJsonUrl()` helper and `URL` import in scope):

```php
    public function test_expert_review_label_is_not_generic_processing(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee(__('Your report is complete and is being reviewed by our expert auditor before delivery.'));
    }

    public function test_status_json_does_not_report_expert_review_as_done(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditReport::factory()->create(['audit_request_id' => $request->id]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('failed', false)
            ->assertJsonPath('report_url', null);
    }

    public function test_dashboard_status_description_for_expert_review(): void
    {
        $request = AuditRequest::factory()->make(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->assertSame(
            'Your report is complete and is being reviewed by our expert auditor before delivery.',
            \App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource::statusDescription($request),
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestStatusTest`
Expected: `test_expert_review_label_is_not_generic_processing` and `test_dashboard_status_description_for_expert_review` FAIL — `label()`'s `default` arm returns `'Processing'`, and `statusDescription()`'s `default` arm returns `''`. `test_status_json_does_not_report_expert_review_as_done` already PASSes today (no code change needed for that behavior), which confirms Section 1 of the design doc's claim that `statusJson()`'s `done` guard needs no change — keep the test anyway as a regression lock.

- [ ] **Step 3: Add the copy**

In `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`, add to `statusDescription()`'s match, after the `AWAITING_PAYMENT` line:

```php
            AuditRequestStatus::EXPERT_REVIEW->value => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
```

In `app/Http/Controllers/AuditRequestController.php`, add to the `label()` match, after the `awaiting_payment` line:

```php
            'expert_review' => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
```

Leave `statusJson()`'s `$ready` computation untouched — `in_array($auditRequest->status, [REPORT_READY, SENT])` already excludes `expert_review` by construction, since it's a new value not in that list.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditRequestStatusTest`
Expected: PASS, all methods in the file.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php app/Http/Controllers/AuditRequestController.php tests/Feature/Http/Controllers/AuditRequestStatusTest.php
git commit -m "feat(audit): add customer-facing copy for expert_review status"
```

---

### Task 3: Stats widget buckets — add `expert_review`, close the HANDLED gap

The existing `AuditAdminStatsWidget` never counts `AuditRequestStatus::HANDLED` in any bucket — this is the exact "one status reconciles to no statistics bucket" defect the roadmap names (§18.7). This task fixes it while adding the new status, and locks the fix down with an exhaustiveness test so it can't silently regress again.

**Files:**
- Modify: `app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`
- Modify: `app/Filament/Dashboard/Widgets/AuditStatsWidget.php`
- Test: `tests/Unit/AuditAdminStatsWidgetTest.php` (new)
- Test: `tests/Feature/Filament/Dashboard/AuditStatsWidgetBucketsTest.php` (new)

**Interfaces:**
- Produces: `AuditAdminStatsWidget::statusBuckets(): array<string, list<string>>`, `AuditStatsWidget::statusBuckets(): array<string, list<string>>` — both public static, keyed by bucket name, valued by a list of `AuditRequestStatus` string values. No other task consumes these directly, but the exhaustiveness tests do.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/AuditAdminStatsWidgetTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
use Tests\TestCase;

class AuditAdminStatsWidgetTest extends TestCase
{
    public function test_every_status_belongs_to_exactly_one_bucket(): void
    {
        $buckets = AuditAdminStatsWidget::statusBuckets();
        $flat = collect($buckets)->flatten()->all();

        $allStatuses = collect(AuditRequestStatus::cases())->map(fn (AuditRequestStatus $c) => $c->value)->sort()->values()->all();
        $this->assertSame($allStatuses, collect($flat)->sort()->values()->all());
        $this->assertCount(count($flat), array_unique($flat), 'a status must not appear in more than one bucket');
    }

    public function test_expert_review_is_its_own_bucket(): void
    {
        $buckets = AuditAdminStatsWidget::statusBuckets();

        $this->assertSame([AuditRequestStatus::EXPERT_REVIEW->value], $buckets['expert_review']);
    }
}
```

Create `tests/Feature/Filament/Dashboard/AuditStatsWidgetBucketsTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Widgets\AuditStatsWidget;
use Tests\Feature\FeatureTest;

class AuditStatsWidgetBucketsTest extends FeatureTest
{
    public function test_every_status_belongs_to_exactly_one_bucket(): void
    {
        $buckets = AuditStatsWidget::statusBuckets();
        $flat = collect($buckets)->flatten()->all();

        $allStatuses = collect(AuditRequestStatus::cases())->map(fn (AuditRequestStatus $c) => $c->value)->sort()->values()->all();
        $this->assertSame($allStatuses, collect($flat)->sort()->values()->all());
        $this->assertCount(count($flat), array_unique($flat), 'a status must not appear in more than one bucket');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminStatsWidgetTest`
Run: `docker compose exec laravel.test php artisan test --filter=AuditStatsWidgetBucketsTest`
Expected: FAIL — `statusBuckets()` doesn't exist yet on either class.

- [ ] **Step 3: Refactor `AuditAdminStatsWidget`**

Replace the body of `app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`'s `getStats()` method and add the new static method. Full replacement for the class body from `protected function getStats()` down to (not including) `private function averageProcessingTime()`:

```php
    public static function statusBuckets(): array
    {
        return [
            'pending' => [AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value, AuditRequestStatus::PENDING_VERIFICATION->value],
            'analyzing' => [AuditRequestStatus::ANALYZING->value],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW->value],
            'completed' => [AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value, AuditRequestStatus::HANDLED->value],
            'failed' => [AuditRequestStatus::FAILED->value],
            'manual' => [AuditRequestStatus::NEEDS_FOLLOWUP->value, AuditRequestStatus::AWAITING_ACCESS->value, AuditRequestStatus::AWAITING_PAYMENT->value],
        ];
    }

    protected function getStats(): array
    {
        $buckets = self::statusBuckets();

        return [
            Stat::make(__('Total audits'), AuditRequest::count())
                ->description(__(':today today · :week this week · :month this month', [
                    'today' => AuditRequest::whereDate('created_at', today())->count(),
                    'week' => AuditRequest::where('created_at', '>=', now()->startOfWeek())->count(),
                    'month' => AuditRequest::where('created_at', '>=', now()->startOfMonth())->count(),
                ])),
            Stat::make(__('Pending'), AuditRequest::whereIn('status', $buckets['pending'])->count())->color('gray'),
            Stat::make(__('Analyzing'), AuditRequest::whereIn('status', $buckets['analyzing'])->count())->color('info'),
            Stat::make(__('Awaiting expert review'), AuditRequest::whereIn('status', $buckets['expert_review'])->count())->color('warning'),
            Stat::make(__('Completed'), AuditRequest::whereIn('status', $buckets['completed'])->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::whereIn('status', $buckets['failed'])->count())->color('danger'),
            Stat::make(__('Needs manual action'), AuditRequest::whereIn('status', $buckets['manual'])->count())->color('warning'),
            Stat::make(__('Avg processing time'), $this->averageProcessingTime())
                ->description(__('From analysis start to report')),
            Stat::make(__('Email failures'), $this->emailFailures())->color('danger'),
            Stat::make(__('Audit queue depth'), $this->queueDepth())
                ->description(__('Jobs waiting on the audit queue'))
                ->url('/horizon', shouldOpenInNewTab: true),
        ];
    }

```

Nothing else in the file changes — `averageProcessingTime()`, `emailFailures()`, `queueDepth()`, `canView()` stay as they are.

- [ ] **Step 4: Refactor `AuditStatsWidget`**

In `app/Filament/Dashboard/Widgets/AuditStatsWidget.php`, add the static method and replace the final `return [...]` block. Add before `getStats()`:

```php
    public static function statusBuckets(): array
    {
        return [
            'in_progress' => [
                AuditRequestStatus::NEW->value,
                AuditRequestStatus::PENDING_VERIFICATION->value,
                AuditRequestStatus::QUEUED->value,
                AuditRequestStatus::ANALYZING->value,
            ],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW->value],
            'needs_action' => [
                AuditRequestStatus::NEEDS_FOLLOWUP->value,
                AuditRequestStatus::AWAITING_ACCESS->value,
                AuditRequestStatus::AWAITING_PAYMENT->value,
            ],
            'completed' => [AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value, AuditRequestStatus::HANDLED->value],
            'failed' => [AuditRequestStatus::FAILED->value],
        ];
    }

```

Replace the final `return [...$stats, ...]` block inside `getStats()` with:

```php
        $buckets = self::statusBuckets();

        return [
            ...$stats,
            Stat::make(__('In progress'), AuditRequest::forUser($user)->whereIn('status', $buckets['in_progress'])->count())->color('info'),
            Stat::make(__('Awaiting expert review'), AuditRequest::forUser($user)->whereIn('status', $buckets['expert_review'])->count())->color('warning'),
            Stat::make(__('Needs your action'), AuditRequest::forUser($user)->whereIn('status', $buckets['needs_action'])->count())->color('warning'),
            Stat::make(__('Completed'), AuditRequest::forUser($user)->whereIn('status', $buckets['completed'])->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::forUser($user)->whereIn('status', $buckets['failed'])->count())->color('danger'),
        ];
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditAdminStatsWidgetTest`
Run: `docker compose exec laravel.test php artisan test --filter=AuditStatsWidgetBucketsTest`
Expected: PASS.

Then re-run the pre-existing widget test to confirm nothing broke:

Run: `docker compose exec laravel.test php artisan test --filter=AuditStatsWidgetTest`
Expected: PASS — it only asserts label visibility (`assertSee(__('In progress'))` etc.), not specific counts, so the bucket restructuring doesn't affect it. If it fails, read the failure carefully before changing test expectations — this file's existing four tests should be unaffected by this task.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Admin/Widgets/AuditAdminStatsWidget.php app/Filament/Dashboard/Widgets/AuditStatsWidget.php tests/Unit/AuditAdminStatsWidgetTest.php tests/Feature/Filament/Dashboard/AuditStatsWidgetBucketsTest.php
git commit -m "fix(audit): make stats widget status buckets exhaustive, add expert_review"
```

---

### Task 4: Payload contract v4 — the expert-review section

**Files:**
- Modify: `app/Services/AuditReport/ReportPayload.php`
- Test: `tests/Unit/ReportPayloadTest.php`

**Interfaces:**
- Produces: `ReportPayload::VERSION = 4`; the payload key `expert_review` with shape `['expert_summary' => string, 'review_notes' => string, 'reviewed_by' => string, 'reviewed_at' => string]`, optional. Consumed by Task 6 (`publish()`) and Task 9 (the edit form).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ReportPayloadTest.php` (this file extends plain PHPUnit `TestCase`, not `FeatureTest` — no database needed, keep it that way):

```php
    public function test_accepts_valid_payload_with_expert_review(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'Reviewed and solid.',
            'review_notes' => 'Nothing further to add.',
            'reviewed_by' => 'Jane Reviewer',
            'reviewed_at' => '2026-08-05T12:00:00+00:00',
        ];

        $validated = ReportPayload::validate($payload, 4);

        $this->assertSame($payload['expert_review'], $validated['expert_review']);
    }

    public function test_expert_review_is_optional_in_v4(): void
    {
        $this->assertSame($this->valid(), ReportPayload::validate($this->valid(), 4));
    }

    public function test_rejects_expert_review_missing_a_field(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'ok',
            'review_notes' => 'ok',
            'reviewed_by' => 'Jane',
            // reviewed_at missing
        ];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload, 4);
    }

    public function test_rejects_expert_review_with_non_string_field(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'ok',
            'review_notes' => 'ok',
            'reviewed_by' => 'Jane',
            'reviewed_at' => 12345, // not a string
        ];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload, 4);
    }

    public function test_default_version_is_now_four(): void
    {
        $this->assertSame(4, ReportPayload::VERSION);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=ReportPayloadTest`
Expected: FAIL — version 4 is `Unknown payload schema version` today.

- [ ] **Step 3: Implement v4**

In `app/Services/AuditReport/ReportPayload.php`:

Change the constant:

```php
    public const VERSION = 4;
```

Change the `validate()` match to add the v4 arm:

```php
        return match ($version) {
            1 => self::validateV1($payload),
            2 => self::validateV2($payload),
            3 => self::validateV3($payload),
            4 => self::validateV4($payload),
            default => throw new AiAnalysisException("Unknown payload schema version: {$version}"),
        };
```

Add two new private methods, after `validateDeepReviewMeta()`:

```php
    private static function validateV4(array $payload): array
    {
        $payload = self::validateV3($payload);

        // Optional by design, matching file_findings/deep_review — the
        // validator is context-free and must not learn about tiers.
        if (array_key_exists('expert_review', $payload)) {
            self::validateExpertReview($payload['expert_review']);
        }

        return $payload;
    }

    private static function validateExpertReview(mixed $review): void
    {
        if (! is_array($review)
            || ! is_string($review['expert_summary'] ?? null)
            || ! is_string($review['review_notes'] ?? null)
            || ! is_string($review['reviewed_by'] ?? null)
            || ! is_string($review['reviewed_at'] ?? null)) {
            throw new AiAnalysisException('Malformed expert_review section');
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=ReportPayloadTest`
Expected: PASS, including every pre-existing test in the file (v1/v2/v3 behavior is untouched).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/ReportPayload.php tests/Unit/ReportPayloadTest.php
git commit -m "feat(audit): extend the payload contract to v4 for the expert-review section"
```

---

### Task 5: The delivery hold — `AuditReportService::createAndDeliver()`

**Files:**
- Modify: `app/Services/AuditReport/AuditReportService.php`
- Modify: `app/Services/AuditReport/AuditPipeline.php`
- Modify: `tests/Feature/Services/AuditPipelineTest.php`
- Test (new): `tests/Feature/Services/AuditReportServiceTest.php`

**Interfaces:**
- Consumes: `AuditReportService::create()` (existing, unchanged), `AuditReportService::send()` (existing, unchanged), `AuditTier::EXPERT` (existing).
- Produces: `AuditReportService::createAndDeliver(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport` — replaces the `create()`+`send()` pair at the pipeline's delivery point. Consumed by `AuditPipeline::run()` in this task, and is the seam Task 9's queue reads from (rows land in the queue because this method sets `EXPERT_REVIEW` instead of calling `send()`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/AuditReportServiceTest.php` via `php artisan make:test --phpunit AuditReportServiceTest` (place it under `tests/Feature/Services/`), then replace its contents:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditReportServiceTest extends FeatureTest
{
    public function test_expert_tier_holds_instead_of_sending(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value]);

        $report = app(AuditReportService::class)->createAndDeliver($request, $this->payload(), 1);

        $this->assertSame(AuditRequestStatus::EXPERT_REVIEW->value, $request->fresh()->status);
        Mail::assertNotQueued(AuditReportReady::class);
        $this->assertNotNull($report->id);
    }

    public function test_every_other_tier_sends_as_before(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);

        app(AuditReportService::class)->createAndDeliver($request, $this->payload(), 1);

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        Mail::assertQueued(AuditReportReady::class);
    }

    private function payload(): array
    {
        return [
            'summary' => 'ok',
            'scores' => ['overall' => 50],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [],
        ];
    }
}
```

Add to `tests/Feature/Services/AuditPipelineTest.php` (pipeline-level, end-to-end confirmation the wiring is actually used):

```php
    public function test_expert_tier_run_holds_for_review_instead_of_sending(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = $this->runPipelineWithFakes(tier: AuditTier::EXPERT);

        $this->assertSame(AuditRequestStatus::EXPERT_REVIEW->value, $request->fresh()->status);
        Mail::assertNotQueued(AuditReportReady::class);
        $this->assertNotNull($request->fresh()->report);
    }
```

(`runPipelineWithFakes()` already accepts a `tier` parameter and already binds a `FakeDeepReviewer` path via its `deepReviewer` parameter if needed — omit it here since `EXPERT_REVIEW`-holding doesn't depend on deep-review content. `AiAnalyzer` and `Mail` are already imported at the top of this file.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportServiceTest`
Run: `docker compose exec laravel.test php artisan test --filter=test_expert_tier_run_holds_for_review_instead_of_sending`
Expected: FAIL — `createAndDeliver()` doesn't exist; the pipeline test fails because expert tier currently sends like every other tier (status ends up `SENT`, not `EXPERT_REVIEW`).

- [ ] **Step 3: Add `createAndDeliver()`**

In `app/Services/AuditReport/AuditReportService.php`, add the import:

```php
use App\Constants\AuditTier;
```

Add the method, directly after `create()`:

```php
    public function createAndDeliver(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport
    {
        $report = $this->create($auditRequest, $payload, $scoringVersion);

        if ($auditRequest->tier === AuditTier::EXPERT) {
            $auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        } else {
            $this->send($report);
        }

        return $report;
    }
```

- [ ] **Step 4: Wire the pipeline to use it**

In `app/Services/AuditReport/AuditPipeline.php`, replace:

```php
            $report = $this->reportService->create($auditRequest, $payload, $scoreSet->scoringVersion);
            $this->reportService->send($report);
```

with:

```php
            $this->reportService->createAndDeliver($auditRequest, $payload, $scoreSet->scoringVersion);
```

(The `$report` local variable is no longer read after this line in the current code — check the lines immediately below still don't reference `$report`; they read `$result`/`$suite`/`$path` instead, so removing the assignment is safe. If anything below does reference `$report`, keep the assignment: `$report = $this->reportService->createAndDeliver(...)`.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportServiceTest`
Run: `docker compose exec laravel.test php artisan test --filter=AuditPipelineTest`
Expected: PASS for both, including every pre-existing `AuditPipelineTest` method (they all use the default `AuditTier::AUTOMATED`, which still sends).

Also re-run Phase 12's deep-review pipeline test, since it exercises `AuditTier::EXPERT` too:

Run: `docker compose exec laravel.test php artisan test --filter=DeepReviewPipelineTest`
Expected: PASS — `test_the_expert_tier_also_runs_deep_review` only asserts `$reviewer->receivedSelection` is not null, not final delivery status, so it's unaffected.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AuditReport/AuditReportService.php app/Services/AuditReport/AuditPipeline.php tests/Feature/Services/AuditReportServiceTest.php tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(audit): hold expert-tier reports for review instead of auto-sending"
```

---

### Task 6: `AuditReportService::publish()`

**Files:**
- Modify: `app/Services/AuditReport/AuditReportService.php`
- Modify: `tests/Feature/Services/AuditReportServiceTest.php`

**Interfaces:**
- Consumes: `ReportPayload::validate()` (Task 4), `AuditReportService::send()` (existing, unchanged).
- Produces: `AuditReportService::publish(AuditReport $report): void` — requires `$report->payload['expert_review']['expert_summary']` to already be a non-empty string (set by the reviewer's draft save, Task 9); stamps `reviewed_by`/`reviewed_at`, regenerates the PDF, sends. `AuditReportService::regeneratePdf(AuditReport $report): void` — public wrapper around the existing private `generatePdf()`, callable from `publish()` and (in principle) any future caller that needs to force a PDF rebuild. Consumed by Task 10's Publish action.
- **Note on scope**: this method must only ever be invoked from an authenticated web request (it reads `auth()->user()->name`) — never from a queued job.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Services/AuditReportServiceTest.php`:

```php
    public function test_publish_requires_an_expert_summary(): void
    {
        $report = \App\Models\AuditReport::factory()->create([
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => '',
                'review_notes' => '',
                'reviewed_by' => '',
                'reviewed_at' => '',
            ]]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(AuditReportService::class)->publish($report);
    }

    public function test_publish_stamps_attribution_regenerates_pdf_and_sends(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $report = \App\Models\AuditReport::factory()->create([
            'audit_request_id' => AuditRequest::factory()->create([
                'tier' => AuditTier::EXPERT->value,
                'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            ]),
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => 'Solid codebase, minor nits.',
                'review_notes' => 'See risks.',
                'reviewed_by' => '',
                'reviewed_at' => '',
            ]]),
        ]);
        $oldPdfPath = $report->pdf_path;

        app(AuditReportService::class)->publish($report);

        $report->refresh();
        $this->assertSame($admin->name, $report->payload['expert_review']['reviewed_by']);
        $this->assertNotEmpty($report->payload['expert_review']['reviewed_at']);
        $this->assertNotNull($report->pdf_path);
        $this->assertSame(AuditRequestStatus::SENT->value, $report->auditRequest->fresh()->status);
        Mail::assertQueued(AuditReportReady::class);
    }
```

`createAdminUser()` is already available via `FeatureTest` (used elsewhere in this suite, e.g. `AuditRequestResourceTest`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportServiceTest`
Expected: FAIL — `publish()` doesn't exist.

- [ ] **Step 3: Implement `publish()` and `regeneratePdf()`**

In `app/Services/AuditReport/AuditReportService.php`, add after `send()`:

```php
    public function publish(AuditReport $report): void
    {
        $payload = $report->payload;

        if (trim((string) ($payload['expert_review']['expert_summary'] ?? '')) === '') {
            throw new \InvalidArgumentException('Cannot publish a report without an expert summary.');
        }

        $payload['expert_review']['reviewed_by'] = auth()->user()->name;
        $payload['expert_review']['reviewed_at'] = now()->toIso8601String();

        $validated = ReportPayload::validate($payload, ReportPayload::VERSION);

        $report->update(['payload' => $validated, 'payload_schema_version' => ReportPayload::VERSION]);
        $this->regeneratePdf($report);
        $this->send($report);
    }

    public function regeneratePdf(AuditReport $report): void
    {
        $this->generatePdf($report);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportServiceTest`
Expected: PASS, all methods.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/AuditReportService.php tests/Feature/Services/AuditReportServiceTest.php
git commit -m "feat(audit): add the expert-review publish action to AuditReportService"
```

---

### Task 7: Reviewer permission

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Unit/RolesAndPermissionsSeederTest.php` (new — check first with `find tests -iname "*RolesAndPermissions*"`; if a test already covers this seeder, extend it instead)

**Interfaces:**
- Produces: the spatie permission string `'review expert audits'`, granted to the `admin` role automatically (the seeder already grants every non-tenancy permission to `admin`). Consumed by Task 8's resource gate.

- [ ] **Step 1: Check for an existing seeder test**

Run: `find tests -iname "*RolesAndPermissions*"`. If found, read it and add the new assertions there instead of creating a new file; otherwise proceed with Step 2.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/RolesAndPermissionsSeederTest.php` via `php artisan make:test --phpunit RolesAndPermissionsSeederTest`, contents:

```php
<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\FeatureTest;

class RolesAndPermissionsSeederTest extends FeatureTest
{
    public function test_admin_role_has_the_review_expert_audits_permission(): void
    {
        $this->assertTrue(Permission::where('name', 'review expert audits')->exists());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('review expert audits'));
    }
}
```

This extends `FeatureTest`, whose `setUp()` already runs `RolesAndPermissionsSeeder` via `TestingDatabaseSeeder` on the first test in the run — no manual seeding call needed.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=RolesAndPermissionsSeederTest`
Expected: FAIL — the permission doesn't exist yet.

- [ ] **Step 3: Add the permission**

In `database/seeders/RolesAndPermissionsSeeder.php`, add after the `Permission::findOrCreate('view stats');` line:

```php
        Permission::findOrCreate('review expert audits');
```

No other change is needed — the existing `$role->givePermissionTo(Permission::all()->filter(...))` call below already grants every non-tenancy permission to `admin`, this one included.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=RolesAndPermissionsSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php tests/Unit/RolesAndPermissionsSeederTest.php
git commit -m "feat(audit): add the review expert audits permission"
```

---

### Task 8: `ExpertReviewResource` — queue list page

**Files:**
- Create: `app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php`
- Create: `app/Filament/Admin/Resources/ExpertReviews/Pages/ListExpertReviews.php`
- Test: `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php` (new)

**Interfaces:**
- Consumes: `AuditRequestStatus::EXPERT_REVIEW` (Task 1), `AuditTier::EXPERT` (existing), the `'review expert audits'` permission (Task 7).
- Produces: `ExpertReviewResource` class, auto-discovered by `AdminPanelProvider`'s `discoverResources()` — no manual registration needed. `ExpertReviewResource::canViewAny(): bool`, `canEdit($record): bool`, `canView($record): bool` — all permission-gated, consumed implicitly by Filament's authorization and directly by this task's tests.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php` via `php artisan make:test --phpunit ExpertReviewResourceTest` (path: `tests/Feature/Filament/Admin/Resources/`), contents:

```php
<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Models\AuditRequest;
use App\Models\User;
use Tests\Feature\FeatureTest;

class ExpertReviewResourceTest extends FeatureTest
{
    public function test_reviewer_can_list_the_queue(): void
    {
        $reviewer = $this->createAdminUser();
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::SENT->value]); // not in queue

        $response = $this->actingAs($reviewer)->get(ExpertReviewResource::getUrl('index', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_queue_only_lists_expert_tier_requests_awaiting_review(): void
    {
        $inQueue = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::SENT->value]); // already published
        AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::REPORT_READY->value]);

        $query = ExpertReviewResource::getEloquentQuery();

        $this->assertSame([$inQueue->id], $query->pluck('id')->all());
    }

    public function test_user_without_the_permission_is_denied(): void
    {
        $user = User::factory()->create(['is_admin' => true]); // no role assigned, so no permission

        $this->assertFalse(ExpertReviewResource::canViewAny());

        $this->actingAs($user);
        $this->assertFalse(ExpertReviewResource::canViewAny());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=ExpertReviewResourceTest`
Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Create the resource**

Create `app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php`:

```php
<?php

namespace App\Filament\Admin\Resources\ExpertReviews;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview;
use App\Filament\Admin\Resources\ExpertReviews\Pages\ListExpertReviews;
use App\Models\AuditRequest;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpertReviewResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Expert review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expert reviews');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tier', AuditTier::EXPERT->value)
            ->where('status', AuditRequestStatus::EXPERT_REVIEW->value);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermissionTo('review expert audits') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('repo_url')->label(__('Repository'))->limit(50)->searchable(),
                TextColumn::make('name')->label(__('Customer'))->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('created_at')->label(__('Submitted'))->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('analysis_completed_at')->label(__('Awaiting since'))->since()->sortable(),
            ])
            ->defaultSort('analysis_completed_at', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpertReviews::route('/'),
            'edit' => EditExpertReview::route('/{record}/edit'),
        ];
    }
}
```

Create `app/Filament/Admin/Resources/ExpertReviews/Pages/ListExpertReviews.php`:

```php
<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;

class ListExpertReviews extends ListRecords
{
    use ListDefaults;

    protected static string $resource = ExpertReviewResource::class;
}
```

`ListDefaults` and the `EditExpertReview` page reference — `EditExpertReview` doesn't exist yet, which will make `getPages()` fail to autoload. Create a minimal placeholder now so the class loads; Task 9 fills it in:

Create `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php`:

```php
<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use Filament\Resources\Pages\EditRecord;

class EditExpertReview extends EditRecord
{
    protected static string $resource = ExpertReviewResource::class;

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([]); // filled in by Task 9
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=ExpertReviewResourceTest`
Expected: PASS, all three tests.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/ExpertReviews tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php
git commit -m "feat(audit): add the expert review queue resource"
```

---

### Task 9: Structured editing form and draft save

**Files:**
- Modify: `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php`
- Modify: `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php`

**Interfaces:**
- Consumes: `ReportPayload::validate()` (Task 4), `App\Services\AuditReport\Findings\Severity` (existing enum — reused for the severity select options so they can't drift from `ReportPayload`'s accepted values).
- Produces: nothing new consumed elsewhere — this is the leaf editing surface. Task 10 adds the Publish action to the same page class.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php`:

```php
    public function test_reviewer_can_edit_and_save_findings(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = \App\Models\AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => [
                'summary' => 'ok',
                'scores' => ['overall' => 60],
                'risks' => [
                    ['title' => 'Keep', 'impact' => 'high', 'evidence' => 'e1', 'recommendation' => 'r1'],
                    ['title' => 'Drop as false positive', 'impact' => 'low', 'evidence' => 'e2', 'recommendation' => 'r2'],
                ],
                'fix_first_plan' => [],
                'groups' => [],
                'file_findings' => [
                    ['path' => 'app/A.php', 'line' => 3, 'title' => 'Finding', 'evidence' => 'ev', 'recommendation' => 'rec', 'severity' => 'high', 'category' => 'security', 'effort' => 'M', 'related_paths' => ['app/B.php']],
                ],
            ],
        ]);

        \Livewire\Livewire::actingAs($reviewer)
            ->test(\App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm([
                'risks' => [
                    ['title' => 'Keep', 'impact' => 'high', 'evidence' => 'e1', 'recommendation' => 'r1'],
                    // second risk removed — simulates deleting a false positive
                ],
                'file_findings' => [
                    ['path' => 'app/A.php', 'line' => 3, 'title' => 'Finding', 'evidence' => 'ev', 'recommendation' => 'rec', 'severity' => 'high', 'category' => 'security', 'effort' => 'M', 'related_paths' => ['app/B.php']],
                ],
                'expert_summary' => 'Looks solid overall.',
                'review_notes' => 'One risk removed as a false positive.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $payload = $report->fresh()->payload;
        $this->assertCount(1, $payload['risks']);
        $this->assertSame('Keep', $payload['risks'][0]['title']);
        $this->assertSame('app/B.php', $payload['file_findings'][0]['related_paths'][0]); // hidden field round-tripped
        $this->assertSame('Looks solid overall.', $payload['expert_review']['expert_summary']);
        $this->assertSame('', $payload['expert_review']['reviewed_by']); // not stamped until publish
    }

    public function test_draft_save_without_a_summary_omits_the_expert_review_key(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = \App\Models\AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        \Livewire\Livewire::actingAs($reviewer)
            ->test(\App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm(['risks' => [], 'file_findings' => [], 'expert_summary' => '', 'review_notes' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('expert_review', $report->fresh()->payload);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=test_reviewer_can_edit_and_save_findings`
Expected: FAIL — the form has no fields yet (placeholder from Task 8), so `fillForm()` targets nonexistent keys.

- [ ] **Step 3: Implement the form and save handling**

Replace `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php` entirely:

```php
<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\ReportPayload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditExpertReview extends EditRecord
{
    protected static string $resource = ExpertReviewResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Risks'))->schema([
                Repeater::make('risks')
                    ->label('')
                    ->schema([
                        Select::make('impact')->options(['high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')])->required(),
                        TextInput::make('title')->required(),
                        Textarea::make('evidence')->required()->rows(2),
                        Textarea::make('recommendation')->required()->rows(2),
                    ])
                    ->reorderable()
                    ->deletable()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
            ]),
            Section::make(__('File-bound findings'))->schema([
                Repeater::make('file_findings')
                    ->label('')
                    ->schema([
                        TextInput::make('path')->required(),
                        TextInput::make('title')->required(),
                        Textarea::make('evidence')->required()->rows(2),
                        Textarea::make('recommendation')->required()->rows(2),
                        Select::make('severity')
                            ->options(collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [$s->value => ucfirst($s->value)]))
                            ->required(),
                        Select::make('category')
                            ->options([
                                'business_logic' => __('Business logic'),
                                'authorization' => __('Authorization'),
                                'architecture' => __('Architecture'),
                                'security' => __('Security'),
                            ])
                            ->required(),
                        Select::make('effort')->options(['S' => __('Small'), 'M' => __('Medium'), 'L' => __('Large')])->required(),
                        // Preserved but not reviewer-editable — dropping these
                        // on save would silently discard the model's original
                        // line/cross-module attribution.
                        Hidden::make('line'),
                        Hidden::make('related_paths'),
                    ])
                    ->reorderable()
                    ->deletable()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
            ]),
            Section::make(__('Expert section'))->schema([
                Textarea::make('expert_summary')
                    ->label(__('Expert summary'))
                    ->helperText(__('Required before this report can be published.'))
                    ->rows(4),
                Textarea::make('review_notes')
                    ->label(__('Review notes'))
                    ->rows(4),
            ]),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $payload = $this->getRecord()->report->payload;

        $data['risks'] = $payload['risks'] ?? [];
        $data['file_findings'] = $payload['file_findings'] ?? [];
        $data['expert_summary'] = $payload['expert_review']['expert_summary'] ?? '';
        $data['review_notes'] = $payload['expert_review']['review_notes'] ?? '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $payload = $record->report->payload;
        $payload['risks'] = $data['risks'];
        $payload['file_findings'] = $data['file_findings'];

        if (trim((string) $data['expert_summary']) !== '' || trim((string) $data['review_notes']) !== '') {
            $payload['expert_review'] = [
                'expert_summary' => $data['expert_summary'],
                'review_notes' => $data['review_notes'],
                'reviewed_by' => $payload['expert_review']['reviewed_by'] ?? '',
                'reviewed_at' => $payload['expert_review']['reviewed_at'] ?? '',
            ];
        } else {
            unset($payload['expert_review']);
        }

        $validated = ReportPayload::validate($payload, ReportPayload::VERSION);
        $record->report->update(['payload' => $validated, 'payload_schema_version' => ReportPayload::VERSION]);

        return $record;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=ExpertReviewResourceTest`
Expected: PASS, all five tests (three from Task 8, two new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php
git commit -m "feat(audit): structured editing form for expert review findings"
```

---

### Task 10: The Publish action

**Files:**
- Modify: `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php`
- Modify: `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php`

**Interfaces:**
- Consumes: `AuditReportService::publish()` (Task 6).
- Produces: nothing consumed by later tasks — this closes the operator-facing loop.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php`:

```php
    public function test_publish_action_is_disabled_without_a_summary(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        \App\Models\AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        \Livewire\Livewire::actingAs($reviewer)
            ->test(\App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->assertActionDisabled('publish');
    }

    public function test_publish_action_sends_and_transitions_status(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = \App\Models\AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        \Livewire\Livewire::actingAs($reviewer)
            ->test(\App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm(['risks' => [], 'file_findings' => [], 'expert_summary' => 'All clear.', 'review_notes' => ''])
            ->callAction('publish');

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        $this->assertSame($reviewer->name, $report->fresh()->payload['expert_review']['reviewed_by']);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\Audit\AuditReportReady::class);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=test_publish_action_is_disabled_without_a_summary`
Expected: FAIL — no `publish` action exists on the page yet.

- [ ] **Step 3: Add the header action**

In `app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php`, add imports:

```php
use App\Services\AuditReport\AuditReportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
```

Add the method (anywhere in the class body, e.g. after `handleRecordUpdate()`):

```php
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish report'))
                ->requiresConfirmation()
                ->modalDescription(__('This sends the report to the customer immediately and cannot be undone.'))
                ->disabled(fn (): bool => trim((string) ($this->data['expert_summary'] ?? '')) === '')
                ->action(function (): void {
                    $this->save(shouldRedirect: false);

                    app(AuditReportService::class)->publish($this->getRecord()->report->fresh());

                    Notification::make()->title(__('Report published'))->success()->send();

                    $this->redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=ExpertReviewResourceTest`
Expected: PASS, all seven tests. (`$this->data` is the live form-state array every Filament `EditRecord` page binds via `->statePath('data')`, confirmed at `vendor/filament/filament/src/Resources/Pages/EditRecord.php:374` — the same convention `save()` itself relies on internally.)

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/ExpertReviews/Pages/EditExpertReview.php tests/Feature/Filament/Admin/Resources/ExpertReviewResourceTest.php
git commit -m "feat(audit): add the publish action to the expert review queue"
```

---

### Task 11: Human-verified report rendering

**Files:**
- Create: `resources/views/reports/partials/expert-review.blade.php`
- Modify: `resources/views/reports/audit-web.blade.php`
- Modify: `resources/views/reports/audit.blade.php`
- Test: `tests/Feature/Http/Controllers/AuditReportControllerTest.php`

**Interfaces:**
- Consumes: `$payload['expert_review']` (Task 4/6's shape).
- Produces: nothing consumed elsewhere — this is the final rendering leaf.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Http/Controllers/AuditReportControllerTest.php`, which already imports `AuditReport`, `AuditReportService`, and has the `payload()` helper (`AuditReport::factory()->raw()['payload']`) and the `app(AuditReportService::class)->signedUrl($report)` pattern used at its line ~70:

```php
    public function test_report_view_renders_the_expert_review_section_when_present(): void
    {
        $report = AuditReport::factory()->unlocked()->create([
            'payload' => array_merge($this->payload(), [
                'expert_review' => [
                    'expert_summary' => 'Reviewed thoroughly, no blockers.',
                    'review_notes' => 'Two risks reprioritized.',
                    'reviewed_by' => 'Jane Reviewer',
                    'reviewed_at' => '2026-08-05T12:00:00+00:00',
                ],
            ]),
        ]);

        $response = $this->get(app(AuditReportService::class)->signedUrl($report));

        $response->assertOk();
        $response->assertSee('Reviewed thoroughly, no blockers.');
        $response->assertSee('Jane Reviewer');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=test_report_view_renders_the_expert_review_section_when_present`
Expected: FAIL — `expert-review.blade.php` doesn't exist and the includes aren't wired.

- [ ] **Step 3: Create the partial**

Create `resources/views/reports/partials/expert-review.blade.php`:

```blade
@php
    $expertReview = $payload['expert_review'] ?? null;
@endphp

@if ($expertReview !== null)
    <h2>{{ __('Human expert review') }}</h2>

    <p class="deep-notice" style="border-left-color: #16a34a;">
        {{ __('Reviewed by a human expert.') }}
    </p>

    <p>{{ $expertReview['expert_summary'] }}</p>

    @if (trim($expertReview['review_notes'] ?? '') !== '')
        <div class="risk-detail">
            {{ $expertReview['review_notes'] }}
        </div>
    @endif

    <p class="muted">
        {{ __('Reviewed by :name on :date', [
            'name' => $expertReview['reviewed_by'],
            'date' => \Illuminate\Support\Carbon::parse($expertReview['reviewed_at'])->format(config('app.datetime_format')),
        ]) }}
    </p>
@endif
```

(The `.deep-notice`/`.risk-detail`/`.muted` classes already exist in both templates' stylesheets, per `deep-findings.blade.php`'s precedent — reuse them rather than inventing new ones.)

- [ ] **Step 4: Wire the includes**

In `resources/views/reports/audit-web.blade.php`, immediately after the existing block:

```blade
    @if (($payload['deep_review'] ?? null) !== null)
        @include('reports.partials.deep-findings', ['payload' => $payload, 'unlocked' => $unlocked])
    @endif
```

add:

```blade
    @if (($payload['expert_review'] ?? null) !== null)
        @include('reports.partials.expert-review', ['payload' => $payload])
    @endif
```

In `resources/views/reports/audit.blade.php`, immediately after the existing:

```blade
    @include('reports.partials.deep-findings', ['payload' => $payload, 'unlocked' => true])
```

add:

```blade
    @include('reports.partials.expert-review', ['payload' => $payload])
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=test_report_view_renders_the_expert_review_section_when_present`
Expected: PASS.

Also re-run the full controller test file to confirm nothing else broke: `docker compose exec laravel.test php artisan test --filter=AuditReportControllerTest`

- [ ] **Step 6: Commit**

```bash
git add resources/views/reports/partials/expert-review.blade.php resources/views/reports/audit-web.blade.php resources/views/reports/audit.blade.php tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(audit): render the human-verified expert review section"
```

---

### Task 12: Full-suite verification and gates

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: all green, no risky tests. If anything fails, fix it before proceeding — do not skip or weaken an assertion to make it pass.

- [ ] **Step 2: Run the full suite a second time**

Run the same command again.
Expected: identical green result — this repo's convention (see Phase 12's exit criteria) is to prove the suite is not flaky by running it twice in a row.

- [ ] **Step 3: Format**

Run: `vendor/bin/pint` (plain, not `--dirty`).

- [ ] **Step 4: Verify the formatting gate**

Run: `vendor/bin/pint --test`
Expected: clean, no files needing changes. If Step 3 changed anything, re-run the full suite once more (Step 1) to confirm formatting didn't touch behavior, then commit the formatting fixes separately:

```bash
git add -A
git commit -m "style: apply Pint after Phase 13 implementation"
```

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: `[OK] No errors` against the frozen baseline in `backend/phpstan-baseline.neon`. If new errors appear, fix the underlying code — do not add to the baseline for new code written in this plan.

- [ ] **Step 6: Manual smoke check of the new resource**

`docker compose exec laravel.test php artisan tinker` and confirm:

```php
\App\Models\Permission::where('name', 'review expert audits')->exists(); // true
\App\Constants\AuditRequestStatus::EXPERT_REVIEW->value; // 'expert_review'
\App\Services\AuditReport\ReportPayload::VERSION; // 4
```

- [ ] **Step 7: Final commit checkpoint**

Confirm `git status` is clean (everything from Tasks 1–11 already committed). If not, commit any stragglers with an appropriately scoped message.

---

## Notes for the executor

- Every task's tests use PHPUnit with `TestCase`-based classes per this repo's convention — `backend/AGENTS.md`'s Pest snippets do not apply anywhere in this plan.
- `FeatureTest::createAdminUser()` already grants the new `review expert audits` permission automatically (Task 7), since it assigns the `admin` spatie role and the seeder grants every non-tenancy permission to `admin`. To test permission *denial*, build a user with `is_admin: true` but skip `assignRole('admin')` (see Task 8, Step 1's `test_user_without_the_permission_is_denied`).
- Tasks 8–10 build one Filament resource incrementally (list → form → publish action) rather than as a single task, because each stage has an independently meaningful, testable deliverable a reviewer could approve or reject on its own — right-sized per the smallest-unit-with-its-own-test-cycle rule.
- If any exact API surface referenced here (`Repeater::itemLabel()`, `TextColumn::since()`, `$this->data`) has drifted in the installed Filament 5 version, the failing test in that task's Step 1/2 will surface it immediately — fix by reading the actual error and consulting the installed vendor source (`backend/vendor/filament/filament/src/...`), not by guessing. `$this->data` and `handleRecordUpdate(Model $record, array $data): Model` were both confirmed directly against `vendor/filament/filament/src/Resources/Pages/EditRecord.php` while writing this plan (lines 281 and 374).
