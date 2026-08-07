# Audit UI — Dashboard Implementation Plan (Part A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Filament user dashboard a clear hierarchy — plan and quota first, then only the audit states that are actually non-zero, then activity — and rebuild the Audit Reports page out of Filament components instead of raw divs.

**Architecture:** A new full-width `PlanUsageWidget` takes over all quota display from `AuditStatsWidget`, which shrinks to a pure status widget that hides empty buckets. A custom `Dashboard` page subclass adds a "Run audit" header action. The Audit Reports Blade view is rebuilt as one Filament section per repository.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, Tailwind 4, PHPUnit 11.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-07-audit-ui-design.md`. Part A only.
- Run every command inside Docker: `docker compose exec -T laravel.test <cmd>` from `/var/www/html/flexpick.net`.
- Tests are **PHPUnit**, not Pest. Extend `Tests\Feature\FeatureTest`. Create with `php artisan make:test --phpunit`.
- Format with `vendor/bin/pint` (never `pint --dirty` — the bind-mount excludes `.git`, so it passes vacuously). Verify with `vendor/bin/pint --test`.
- `vendor/bin/phpstan analyse` must report `[OK] No errors`.
- Panel is dark by default (`ThemeMode::Dark`). Use Filament components and the `primary`/`success`/`warning`/`danger` colour names, never hardcoded hexes.
- All user-facing strings go through `__()`.
- **Never give a navigation group an icon** — Filament throws when a group and its items both carry icons.
- Audit visibility everywhere uses `AuditEntitlementService::hasAuditAccess($user, $tenant)`.

---

### Task 1: PlanUsageWidget

**Files:**
- Create: `backend/app/Filament/Dashboard/Widgets/PlanUsageWidget.php`
- Create: `backend/resources/views/filament/dashboard/widgets/plan-usage-widget.blade.php`
- Test: `backend/tests/Feature/Filament/Dashboard/PlanUsageWidgetTest.php`

**Interfaces:**
- Consumes: `AuditEntitlementService` (`hasAuditAccess`, `subscriptionAllowance`, `dashboardRunsUsedThisMonth`, `deepAiCredits`, `deepAiRunsUsedThisMonth`, `freeRunsLimit`, `freeRunsUsed`), `SubscriptionService::findActiveTenantSubscriptions(?Tenant): Collection`.
- Produces: `PlanUsageWidget::canView(): bool` and view data keys `planName`, `renewsAt`, `bars` (array of `['label' => string, 'used' => int, 'total' => int, 'color' => string]`), `showUpgrade` (bool).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Widgets\PlanUsageWidget;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PlanUsageWidgetTest extends FeatureTest
{
    public function test_shows_plan_name_and_allowance_for_subscribed_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);
        $product = Product::factory()->create(['metadata' => ['audit_analyses_per_month' => 5]]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Studio']);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Studio')
            ->assertSee(__('Analyses this month'));
    }

    public function test_shows_free_runs_for_user_without_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee(__('Free audits'))
            ->assertDontSee(__('Analyses this month'));
    }

    public function test_hidden_without_any_entitlement(): void
    {
        config(['audit.free_reports_limit' => 0]);
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(PlanUsageWidget::canView());
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=PlanUsageWidgetTest`
Expected: FAIL — `Class "App\Filament\Dashboard\Widgets\PlanUsageWidget" not found`.

- [ ] **Step 3: Write the widget class**

```php
<?php

namespace App\Filament\Dashboard\Widgets;

use App\Services\AuditReport\AuditEntitlementService;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class PlanUsageWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.plan-usage-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $subscription = app(SubscriptionService::class)
            ->findActiveTenantSubscriptions($tenant)
            ->first();

        $allowance = $tenant !== null ? $entitlements->subscriptionAllowance($tenant) : 0;
        $deepAiCredits = $tenant !== null ? $entitlements->deepAiCredits($tenant) : 0;

        $bars = [];

        if ($allowance > 0) {
            $bars[] = [
                'label' => __('Analyses this month'),
                'used' => $entitlements->dashboardRunsUsedThisMonth($user),
                'total' => $allowance,
                'color' => 'bg-primary-500',
            ];

            // Hidden entirely at zero, matching how the stats widget already
            // treats Deep AI: a plan without credits should not advertise them.
            if ($deepAiCredits > 0) {
                $bars[] = [
                    'label' => __('Deep AI credits'),
                    'used' => $entitlements->deepAiRunsUsedThisMonth($user),
                    'total' => $deepAiCredits,
                    'color' => 'bg-secondary-500',
                ];
            }
        } else {
            $bars[] = [
                'label' => __('Free audits'),
                'used' => $entitlements->freeRunsUsed($user->email),
                'total' => $entitlements->freeRunsLimit($user->email),
                'color' => 'bg-primary-500',
            ];
        }

        return [
            'planName' => $subscription?->plan?->name ?? __('Free'),
            'renewsAt' => $subscription?->ends_at,
            'bars' => $bars,
            'showUpgrade' => $allowance === 0 || $entitlements->remainingDashboardRuns($user, $tenant) === 0,
        ];
    }
}
```

Note: `remainingDashboardRuns()` requires a non-null `Tenant`. Guard it — when `$tenant` is null, `showUpgrade` is simply `true`:

```php
'showUpgrade' => $tenant === null
    || $allowance === 0
    || $entitlements->remainingDashboardRuns($user, $tenant) === 0,
```

Use that guarded version.

- [ ] **Step 4: Write the Blade view**

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Current plan') }}
                </p>
                <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ $planName }}</p>
                @if ($renewsAt)
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Renews :date', ['date' => $renewsAt->format(config('app.date_format', 'd/m/Y'))]) }}
                    </p>
                @endif
            </div>

            @if ($showUpgrade)
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource::getUrl() }}"
                    color="primary"
                    size="sm"
                >
                    {{ __('Upgrade') }}
                </x-filament::button>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($bars as $bar)
                @php($percent = $bar['total'] > 0 ? min(100, (int) round($bar['used'] / $bar['total'] * 100)) : 0)
                <div>
                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ $bar['label'] }}</span>
                        <span class="font-medium">
                            {{ __(':used of :total used', ['used' => $bar['used'], 'total' => $bar['total']]) }}
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full {{ $bar['color'] }}" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
```

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=PlanUsageWidgetTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Format, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
git add backend/app/Filament/Dashboard/Widgets/PlanUsageWidget.php \
        backend/resources/views/filament/dashboard/widgets/plan-usage-widget.blade.php \
        backend/tests/Feature/Filament/Dashboard/PlanUsageWidgetTest.php
git commit -m "feat(dashboard): plan and quota widget with usage bars"
```

---

### Task 2: Custom Dashboard page with a Run audit header action

**Files:**
- Create: `backend/app/Filament/Dashboard/Pages/Dashboard.php`
- Modify: `backend/app/Providers/Filament/DashboardPanelProvider.php` (the `->pages([...])` call)
- Test: `backend/tests/Feature/Filament/Dashboard/DashboardPageTest.php`

**Interfaces:**
- Consumes: `AuditEntitlementService::hasAuditAccess()`, `AuditReports::getUrl()`.
- Produces: `App\Filament\Dashboard\Pages\Dashboard` replacing `Filament\Pages\Dashboard` in the panel.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\Dashboard;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class DashboardPageTest extends FeatureTest
{
    public function test_run_audit_header_action_is_present_for_entitled_user(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Run audit'));
    }

    public function test_run_audit_header_action_hidden_without_entitlement(): void
    {
        config(['audit.free_reports_limit' => 0]);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertDontSee(__('Run audit'));
    }
}
```

This test renders the page end-to-end, which is deliberate — it is the sidebar-render guard the spec requires in §6.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=DashboardPageTest`
Expected: FAIL — `Class "App\Filament\Dashboard\Pages\Dashboard" not found`.

- [ ] **Step 3: Write the page class**

```php
<?php

namespace App\Filament\Dashboard\Pages;

use App\Services\AuditReport\AuditEntitlementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * Filament's built-in Dashboard exposes no header actions, so the panel
     * registers this subclass instead. The action links to the Audit Reports
     * page rather than duplicating its submit form.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runAudit')
                ->label(__('Run audit'))
                ->icon('heroicon-o-play')
                ->url(fn (): string => AuditReports::getUrl())
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user !== null
                        && app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
                }),
        ];
    }
}
```

- [ ] **Step 4: Register it in the panel**

In `backend/app/Providers/Filament/DashboardPanelProvider.php`, replace the `Dashboard::class` entry in `->pages([...])` with the new page, and update the import from `Filament\Pages\Dashboard` to `App\Filament\Dashboard\Pages\Dashboard`.

```php
use App\Filament\Dashboard\Pages\Dashboard;
```

The `->pages([Dashboard::class, CreateWorkspace::class])` call itself needs no change once the import points at the subclass.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=DashboardPageTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Format, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
git add backend/app/Filament/Dashboard/Pages/Dashboard.php \
        backend/app/Providers/Filament/DashboardPanelProvider.php \
        backend/tests/Feature/Filament/Dashboard/DashboardPageTest.php
git commit -m "feat(dashboard): run audit action in the dashboard header"
```

---

### Task 3: Shrink AuditStatsWidget to a status widget

**Files:**
- Modify: `backend/app/Filament/Dashboard/Widgets/AuditStatsWidget.php:40-84` (the `getStats()` body)
- Modify: `backend/tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php`
- Test: same file

**Interfaces:**
- Consumes: `AuditStatsWidget::statusBuckets()` (unchanged, still public — `AuditStatsWidgetBucketsTest` asserts on it).
- Produces: a `getStats()` returning only non-empty buckets. No quota stats — Task 1 owns those.

- [ ] **Step 1: Update the existing test file**

The quota assertions move to `PlanUsageWidgetTest` (Task 1). Delete any test in `AuditStatsWidgetTest` asserting on `'Analyses remaining this month'`, `'Free audits remaining'`, or `'Deep AI credits remaining this month'`, and add:

```php
    public function test_zero_count_buckets_are_not_rendered(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::ANALYZING->value,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditStatsWidget::class)
            ->assertSee(__('In progress'))
            ->assertDontSee(__('Failed'));
    }

    public function test_quota_stats_are_no_longer_rendered_here(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditStatsWidget::class)
            ->assertDontSee(__('Free audits remaining'));
    }
```

Add `use App\Constants\AuditRequestStatus;` and `use App\Models\AuditRequest;` if absent.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditStatsWidgetTest`
Expected: FAIL — `Failed` and the quota strings are still rendered.

- [ ] **Step 3: Rewrite getStats()**

Replace the whole `getStats()` method with:

```php
    protected function getStats(): array
    {
        $user = auth()->user();
        $buckets = self::statusBuckets();

        $definitions = [
            'in_progress' => [__('In progress'), 'info', 'heroicon-m-arrow-path', __('Being analyzed now')],
            'expert_review' => [__('Awaiting expert review'), 'warning', 'heroicon-m-eye', __('With a human reviewer')],
            'needs_action' => [__('Needs your action'), 'warning', 'heroicon-m-exclamation-triangle', __('Blocked until you respond')],
            'completed' => [__('Completed'), 'success', 'heroicon-m-check-circle', __('Reports delivered')],
            'failed' => [__('Failed'), 'danger', 'heroicon-m-x-circle', __('Could not be analyzed')],
        ];

        $stats = [];

        foreach ($definitions as $key => [$label, $color, $icon, $description]) {
            $count = AuditRequest::forUser($user)->whereIn('status', $buckets[$key])->count();

            // A wall of zeroes competes with the states that matter. Only
            // surface a bucket once it actually holds something.
            if ($count === 0) {
                continue;
            }

            $stats[] = Stat::make($label, $count)
                ->description($description)
                ->icon($icon)
                ->color($color);
        }

        return $stats;
    }
```

Remove the now-unused `AuditEntitlementService` and `Filament` imports **only if** nothing else in the class uses them — `canView()` still uses both, so keep them.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter='AuditStatsWidget'`
Expected: PASS, including `AuditStatsWidgetBucketsTest`.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
git add backend/app/Filament/Dashboard/Widgets/AuditStatsWidget.php \
        backend/tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php
git commit -m "refactor(dashboard): audit stats widget shows only non-empty states"
```

---

### Task 4: Score and delta in RecentAuditsWidget

**Files:**
- Modify: `backend/app/Filament/Dashboard/Widgets/RecentAuditsWidget.php:24-28` (query) and `:30-45` (columns)
- Test: `backend/tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php`

**Interfaces:**
- Consumes: `AuditRequest::forUser()`, the `report` relation, `payload.scores.overall`.
- Produces: no public API change.

- [ ] **Step 1: Write the failing test**

```php
    public function test_shows_score_and_delta_against_previous_audit_of_same_repo(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $older = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
            'created_at' => now()->subDays(7),
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $older->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 60]],
            'created_at' => now()->subDays(7),
        ]);

        $newer = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $newer->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 68]],
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(RecentAuditsWidget::class)
            ->assertSee('68')
            ->assertSee('+8');
    }
```

Add `use App\Models\AuditReport;` if absent.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=RecentAuditsWidgetTest`
Expected: FAIL — `68` is not rendered; there is no score column.

- [ ] **Step 3: Add eager loading and the score column**

The current query has **no eager loading**, so a score column would issue one query per row. Change the query to:

```php
            ->query(
                AuditRequest::forUser(auth()->user())
                    ->with('report')
                    ->latest()
                    ->limit(5)
            )
```

Add a `previousScores()` helper to the class. It runs **one** query for prior scores across the
repos in view.

**Call it exactly once, in `table()`, and capture the result with `use`.** Calling
`$this->previousScores()` from inside the per-row `->state()` closure would re-run the query for
every row — the N+1 this helper exists to avoid.

```php
    /**
     * Previous overall score per repo_url, for the repos currently in view.
     * One query total; computing this per row would be an N+1.
     *
     * @return array<string, int>
     */
    private function previousScores(): array
    {
        $user = auth()->user();

        return AuditRequest::forUser($user)
            ->with('report')
            ->whereNotNull('repo_url')
            ->latest()
            ->get()
            ->groupBy('repo_url')
            ->map(function ($requests): ?int {
                $scored = $requests
                    ->map(fn (AuditRequest $r): ?int => data_get($r->report?->payload, 'scores.overall'))
                    ->filter(fn (?int $s): bool => $s !== null)
                    ->values();

                // Index 0 is the newest; index 1 is what we compare against.
                return $scored->get(1);
            })
            ->filter()
            ->all();
    }
```

At the very top of `table()`, before `return $table`, hoist the lookup:

```php
    public function table(Table $table): Table
    {
        // Resolved once per render and closed over below. Calling this inside
        // the column closure would issue one query per row.
        $previousScores = $this->previousScores();

        return $table
```

Then add the column after `status`, capturing `$previousScores` by value:

```php
                TextColumn::make('score')
                    ->label(__('Score'))
                    ->badge()
                    ->color('gray')
                    ->state(function (AuditRequest $record) use ($previousScores): string {
                        $score = data_get($record->report?->payload, 'scores.overall');

                        if ($score === null) {
                            return '—';
                        }

                        $previous = $previousScores[$record->repo_url] ?? null;

                        if ($previous === null) {
                            return (string) $score;
                        }

                        return $score.' ('.sprintf('%+d', $score - $previous).')';
                    }),
```

- [ ] **Step 4: Add an empty state**

Chain onto the table builder, after `->columns([...])`:

```php
            ->emptyStateHeading(__('No audits yet'))
            ->emptyStateDescription(__('Run your first audit to see results here.'))
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
```

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=RecentAuditsWidgetTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
git add backend/app/Filament/Dashboard/Widgets/RecentAuditsWidget.php \
        backend/tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php
git commit -m "feat(dashboard): score and trend delta in recent audits"
```

---

### Task 5: Rebuild the Audit Reports page view

**Files:**
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php` (full rewrite)
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php` (`getViewData()` — add `deltas`)
- Test: `backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php`

**Interfaces:**
- Consumes: existing `getViewData()` keys `reports`, `allowance`, `remainingRuns`, `freeRunsRemaining`, `canRun`, `schedules`, `repoGroups`.
- Produces: adds a `deltas` key — `array<string, ?int>` keyed by trimmed `repo_url`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_repo_section_shows_current_score_and_delta(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        foreach ([[60, 7], [68, 0]] as [$score, $daysAgo]) {
            $request = AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => 'https://github.com/acme/app',
                'status' => AuditRequestStatus::SENT->value,
                'created_at' => now()->subDays($daysAgo),
            ]);
            AuditReport::factory()->create([
                'audit_request_id' => $request->id,
                'user_id' => $user->id,
                'payload' => ['scores' => ['overall' => $score]],
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditReports::class)
            ->assertSee('68')
            ->assertSee('+8');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditReportsPageTest`
Expected: FAIL — `+8` is not rendered.

- [ ] **Step 3: Add deltas to getViewData()**

Inside `getViewData()`, after `$repoGroups` is built, add a `deltas` entry computed from the already-loaded collection (no extra query):

```php
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
```

- [ ] **Step 4: Rewrite the Blade view**

Replace the report-list and repo-group portions (everything after the submit-form `@if ($canRun) ... @endif` block, which stays exactly as committed in `bdb49fa`) with:

```blade
    @forelse ($repoGroups as $repoUrl => $group)
        @php
            $current = $group['scores']->last();
            $delta = $deltas[rtrim($repoUrl, '/')] ?? null;
            $scoreColor = $current >= 70 ? 'text-success-500' : ($current >= 50 ? 'text-warning-500' : 'text-danger-500');
        @endphp

        <x-filament::section class="mb-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-medium">{{ $repoUrl }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ trans_choice('{1} :count audit|[2,*] :count audits', $group['reports']->count(), ['count' => $group['reports']->count()]) }}
                        · {{ $group['reports']->first()->created_at->diffForHumans() }}
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-2xl font-bold {{ $scoreColor }}">{{ $current }}</p>
                    @if ($delta !== null && $delta !== 0)
                        <p class="text-xs {{ $delta > 0 ? 'text-success-500' : 'text-danger-500' }}">
                            {{ sprintf('%+d', $delta) }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Only meaningful with history; single-audit repos show a score and no chart. --}}
            @if ($group['scores']->count() > 1)
                @php
                    $scores = $group['scores'];
                    $max = max(1, $scores->max());
                    $step = 200 / max(1, $scores->count() - 1);
                    $points = $scores->map(fn ($s, $i) => round($i * $step, 2).','.round(34 - ($s / $max) * 30, 2))->implode(' ');
                @endphp
                <svg viewBox="0 0 200 40" class="mt-3 h-10 w-full" fill="none" aria-hidden="true">
                    <polyline points="{{ $points }}" stroke="currentColor" stroke-width="2" class="text-primary-500" />
                </svg>
            @endif

            @if ($allowance > 0)
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select
                        class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        wire:change="setSchedule('{{ $repoUrl }}', $event.target.value)"
                        aria-label="{{ __('Audit schedule for :repo', ['repo' => $repoUrl]) }}"
                    >
                        @foreach (['off' => __('No schedule'), 'weekly' => __('Audit weekly'), 'monthly' => __('Audit monthly')] as $value => $optionLabel)
                            <option value="{{ $value }}" @selected(($schedules[rtrim($repoUrl, '/')] ?? 'off') === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>

                    <x-filament::button size="sm" color="gray" wire:click="launchAudit('{{ $repoUrl }}')">
                        {{ __('Re-run') }}
                    </x-filament::button>
                </div>
            @endif

            <div class="mt-4 divide-y divide-gray-200 border-t border-gray-200 pt-2 dark:divide-gray-700 dark:border-gray-700">
                @foreach ($group['reports'] as $report)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $report->created_at->format(config('app.datetime_format', 'd/m/Y H:i')) }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">{{ data_get($report->payload, 'scores.overall', '—') }}</span>
                            <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', $report) }}">
                                {{ __('PDF') }}
                            </x-filament::button>
                            <x-filament::button tag="a" size="xs" color="primary" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">
                                {{ __('View') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <div class="py-8 text-center">
                <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="mx-auto h-10 w-10 text-gray-400" />
                <p class="mt-2 font-medium">{{ __('No audit reports yet') }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter a repository URL above to run your first audit.') }}
                </p>
            </div>
        </x-filament::section>
    @endforelse
```

The `<select>` stays a native element because Filament's `Select` form component requires a Livewire form schema this page does not have; it now carries `fi-select-input` and an `aria-label` instead of hand-rolled dark classes.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditReportsPageTest`
Expected: PASS (all tests, including the ones from `bdb49fa`).

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
git add backend/resources/views/filament/dashboard/pages/audit-reports.blade.php \
        backend/app/Filament/Dashboard/Pages/AuditReports.php \
        backend/tests/Feature/Filament/Dashboard/AuditReportsPageTest.php
git commit -m "feat(dashboard): rebuild audit reports page as per-repo sections"
```

---

### Task 6: Category score bars in the audit infolist

**Files:**
- Modify: `backend/app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php` (the `category_scores` `TextEntry` in `infolist()`)
- Create: `backend/resources/views/filament/dashboard/partials/category-scores.blade.php`
- Test: `backend/tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `payload.scores` minus the `overall` key.
- Produces: no public API change.

- [ ] **Step 1: Write the failing test**

```php
    public function test_category_scores_render_as_bars_not_a_joined_string(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $audit->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 68, 'security' => 80, 'testing' => 44]],
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('role="meter"', false)
            ->assertDontSee('Security: 80 · Testing: 44');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditRequestResourceTest`
Expected: FAIL — no `role="meter"` in the output.

- [ ] **Step 3: Write the partial**

```blade
@php($scores = collect($scores)->except('overall'))

<div class="space-y-2">
    @foreach ($scores as $key => $value)
        @php($label = __(ucfirst(str_replace('_', ' ', $key))))
        <div>
            <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                <span>{{ $label }}</span>
                <span class="font-medium">{{ $value }}</span>
            </div>
            <div
                class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                role="meter"
                aria-valuenow="{{ $value }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="{{ $label }}"
            >
                <div
                    class="h-full {{ $value >= 70 ? 'bg-success-500' : ($value >= 50 ? 'bg-warning-500' : 'bg-danger-500') }}"
                    style="width: {{ max(0, min(100, (int) $value)) }}%"
                ></div>
            </div>
        </div>
    @endforeach
</div>
```

- [ ] **Step 4: Swap the infolist entry**

Replace the `category_scores` `TextEntry` with a view entry. Add `use Filament\Infolists\Components\ViewEntry;` to the imports:

```php
                    ViewEntry::make('category_scores')
                        ->label(__('Category scores'))
                        ->view('filament.dashboard.partials.category-scores')
                        ->viewData(fn (AuditRequest $record): array => [
                            'scores' => data_get($record->report?->payload, 'scores', []),
                        ]),
```

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditRequestResourceTest`
Expected: PASS.

- [ ] **Step 6: Full verification and commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
docker compose exec -T laravel.test php artisan test --compact
git add backend/app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php \
        backend/resources/views/filament/dashboard/partials/category-scores.blade.php \
        backend/tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php
git commit -m "feat(dashboard): render category scores as bars"
```

Expected: Pint `PASS`, PHPStan `[OK] No errors`, full suite green.
