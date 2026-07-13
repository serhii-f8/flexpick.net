# User Dashboard Audit Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give dashboard users a read-only "Audits" section (list + details) covering every audit they own — including in-flight and failed ones — plus two main-dashboard stats widgets, all scoped so users can never see another account's audits.

**Architecture:** A `forUser` Eloquent scope on `AuditRequest` (user_id OR email match) is the single authorization boundary, shared by a new read-only Filament Resource in the dashboard panel and two widgets. `AuditEntitlementService` gains a `hasAuditAccess()` helper for navigation/widget visibility. The `LinkAuditReportsToUser` listener is extended to backfill `audit_requests.user_id` at registration. The existing `AuditReports` launch/schedules/trends page is untouched.

**Tech Stack:** Laravel 13, Filament 5 (Schemas API — `Filament\Schemas\Schema`, `Filament\Schemas\Components\Section`), Livewire test harness. Widgets are auto-discovered from `app/Filament/Dashboard/Widgets` (panel already calls `discoverWidgets`).

**Spec:** `docs/superpowers/specs/2026-07-13-user-dashboard-audits-design.md`

## Global Constraints

- Backend commands run from the repo root via `docker compose exec laravel.test <cmd>`. Tests: `docker compose exec laravel.test php artisan test --compact --filter=<Name>`. Format PHP with `docker compose exec laravel.test vendor/bin/pint <files>` before committing.
- Tests extend `Tests\Feature\FeatureTest` (fresh-migrates once per process, `withoutExceptionHandling()` on). Filament dashboard tests follow the existing pattern: `Filament::setCurrentPanel(Filament::getPanel('dashboard')); Filament::setTenant($tenant);` then `Livewire::actingAs($user)->test(...)` or `$this->get(Resource::getUrl(..., true, 'dashboard', tenant: $tenant))`.
- Ownership scope everywhere: `user_id = auth user id OR email = auth user's email`. Never query audits in dashboard code without it.
- The existing `app/Filament/Dashboard/Pages/AuditReports.php` page and its Blade view must not change.
- `AuditRequest` has no `tenant_id` — the new resource must set `protected static bool $isScopedToTenant = false;` or Filament's tenancy layer will try to scope through a nonexistent tenant relationship.
- Status display/colors reuse `App\Mapper\AuditRequestStatusMapper` (`mapForDisplay(string): string`, `mapColor(string): string`) — do not duplicate the match tables.
- Filament 5 note: this codebase's resources use the Schemas API (see `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` as the closest reference). If a `Filter::schema()` call fails on the installed Filament build, use `Filter::form()` — same component list.

---

### Task 1: Ownership scope, access helper, and listener backfill

**Files:**
- Modify: `backend/app/Models/AuditRequest.php` (add `scopeForUser`)
- Modify: `backend/app/Services/AuditReport/AuditEntitlementService.php` (add `hasAuditAccess`)
- Modify: `backend/app/Listeners/User/LinkAuditReportsToUser.php` (backfill requests)
- Test: `backend/tests/Feature/Listeners/LinkAuditReportsToUserTest.php` (extend)
- Test: `backend/tests/Feature/Models/AuditRequestForUserScopeTest.php` (new)

**Interfaces:**
- Consumes: existing `AuditRequest` model (`user_id`, `email` columns), `AuditEntitlementService::subscriptionAllowance(Tenant): int`.
- Produces: `AuditRequest::forUser(User $user): Builder` (local scope; static-callable) and `AuditEntitlementService::hasAuditAccess(User $user, ?Tenant $tenant): bool` — Tasks 2–4 build every query and visibility check on these two.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Models/AuditRequestForUserScopeTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditRequestForUserScopeTest extends FeatureTest
{
    public function test_for_user_matches_user_id_and_email_but_not_others(): void
    {
        $user = $this->createUser(null, [], ['email' => 'scope-owner@example.com']);

        $byId = AuditRequest::factory()->create(['user_id' => $user->id, 'email' => 'other@example.com']);
        $byEmail = AuditRequest::factory()->create(['user_id' => null, 'email' => 'scope-owner@example.com']);
        $foreign = AuditRequest::factory()->create(['user_id' => null, 'email' => 'stranger@example.com']);

        $ids = AuditRequest::forUser($user)->pluck('id');

        $this->assertTrue($ids->contains($byId->id));
        $this->assertTrue($ids->contains($byEmail->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_has_audit_access_rules(): void
    {
        $entitlements = app(AuditEntitlementService::class);

        // No audits, no tenant → no access
        $bare = $this->createUser();
        $this->assertFalse($entitlements->hasAuditAccess($bare, null));

        // Has an audit → access regardless of tenant
        $withAudit = $this->createUser();
        AuditRequest::factory()->create(['user_id' => $withAudit->id]);
        $this->assertTrue($entitlements->hasAuditAccess($withAudit, null));

        // Tenant without allowance → no access
        $tenant = Tenant::factory()->create();
        $this->assertFalse($entitlements->hasAuditAccess($bare, $tenant));
    }
}
```

Append to `backend/tests/Feature/Listeners/LinkAuditReportsToUserTest.php` (inside the class):

```php
    public function test_requests_matching_email_are_linked_on_registration(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'newuser2@example.com', 'user_id' => null]);
        $other = AuditRequest::factory()->create(['email' => 'someoneelse@example.com', 'user_id' => null]);

        $user = $this->createUser();
        $user->update(['email' => 'newuser2@example.com']);

        event(new \Illuminate\Auth\Events\Registered($user));

        $this->assertSame($user->id, $request->fresh()->user_id);
        $this->assertNull($other->fresh()->user_id);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter="AuditRequestForUserScopeTest|LinkAuditReportsToUserTest"`
Expected: FAIL — `Call to undefined method ... forUser()` / `hasAuditAccess()`; the new listener test fails with `user_id` still null. The pre-existing listener test passes.

- [ ] **Step 3: Implement scope, helper, and backfill**

In `backend/app/Models/AuditRequest.php`, add to the imports:

```php
use Illuminate\Database\Eloquent\Builder;
```

and add inside the class (after the casts):

```php
    /**
     * All audits owned by the given user: linked by id, or submitted with
     * their email before they registered.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)->orWhere('email', $user->email);
        });
    }
```

In `backend/app/Services/AuditReport/AuditEntitlementService.php`, add:

```php
    public function hasAuditAccess(User $user, ?Tenant $tenant): bool
    {
        if (AuditRequest::forUser($user)->exists()) {
            return true;
        }

        return $tenant !== null && $this->subscriptionAllowance($tenant) > 0;
    }
```

(`AuditRequest`, `Tenant`, and `User` are already imported in that file.)

Replace the `handle` method in `backend/app/Listeners/User/LinkAuditReportsToUser.php`:

```php
    public function handle(Registered $event): void
    {
        AuditRequest::query()
            ->whereNull('user_id')
            ->where('email', $event->user->email)
            ->update(['user_id' => $event->user->getAuthIdentifier()]);

        AuditReport::query()
            ->whereNull('user_id')
            ->whereHas('auditRequest', fn ($query) => $query->where('email', $event->user->email))
            ->update(['user_id' => $event->user->getAuthIdentifier()]);
    }
```

and add `use App\Models\AuditRequest;` to its imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter="AuditRequestForUserScopeTest|LinkAuditReportsToUserTest"`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Models/AuditRequest.php app/Services/AuditReport/AuditEntitlementService.php app/Listeners/User/LinkAuditReportsToUser.php tests/Feature/Models/AuditRequestForUserScopeTest.php tests/Feature/Listeners/LinkAuditReportsToUserTest.php
git add backend/app/Models/AuditRequest.php backend/app/Services/AuditReport/AuditEntitlementService.php backend/app/Listeners/User/LinkAuditReportsToUser.php backend/tests/Feature/Models/AuditRequestForUserScopeTest.php backend/tests/Feature/Listeners/LinkAuditReportsToUserTest.php
git commit -m "feat(backend): audit ownership scope, access helper, and request backfill on registration"
```

---

### Task 2: Dashboard "Audits" resource (list + view)

**Files:**
- Create: `backend/app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`
- Create: `backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php`
- Create: `backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ViewAuditRequest.php`
- Test: `backend/tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `AuditRequest::forUser()` and `hasAuditAccess()` from Task 1; `AuditRequestStatusMapper`; `AuditReportService::signedUrl(AuditReport): string`; route `reports.download` (param `auditReport` by uuid); `App\Filament\Dashboard\Pages\AuditReports::getUrl()`.
- Produces: `App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource` with pages `index` and `view` — Task 4's widget links to `AuditRequestResource::getUrl('view', ['record' => $record])`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    public function test_list_shows_own_audits_only(): void
    {
        $user = User::factory()->create(['email' => 'list-owner@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create(['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/mine-by-id']);
        AuditRequest::factory()->create(['user_id' => null, 'email' => 'list-owner@example.com', 'repo_url' => 'https://github.com/acme/mine-by-email']);
        AuditRequest::factory()->create(['repo_url' => 'https://github.com/acme/not-mine']);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('mine-by-id');
        $response->assertSee('mine-by-email');
        $response->assertDontSee('not-mine');
    }

    public function test_foreign_audit_view_is_not_found(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $foreign = AuditRequest::factory()->create();

        $this->actingAs($user);
        $this->expectException(NotFoundHttpException::class);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $foreign->uuid], true, 'dashboard', tenant: $tenant));
    }

    public function test_view_shows_failure_reason_for_failed_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::FAILED->value,
            'failure_reason' => 'Clone timed out after 120s',
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Clone timed out after 120s');
    }

    public function test_view_shows_invite_instructions_for_awaiting_access(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('flexpick-audit');
    }

    public function test_view_shows_scores_and_report_links_for_completed_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('55'); // overall score from AuditReportFactory payload
        $response->assertSee(__('View online'));
        $response->assertSee(__('Download PDF'));
    }

    public function test_navigation_hidden_without_audits_or_allowance(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('dashboard'));
        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertFalse(AuditRequestResource::shouldRegisterNavigation());
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter="Dashboard.*AuditRequestResourceTest"`
Expected: FAIL — `Class "App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource" not found`.

- [ ] **Step 3: Implement the resource**

Create `backend/app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditRequestResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Audit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audits');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->forUser(auth()->user())
            ->with('report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
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
                TextColumn::make('repo_url')
                    ->label(__('Repository'))
                    ->limit(50)
                    ->placeholder(__('No repository'))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('score')
                    ->label(__('Score'))
                    ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                TextColumn::make('source')
                    ->label(__('Source')),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format'))
                    ->sortable(),
                TextColumn::make('report.created_at')
                    ->label(__('Completed'))
                    ->dateTime(config('app.datetime_format'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(
                        collect(AuditRequestStatus::cases())
                            ->mapWithKeys(fn (AuditRequestStatus $status) => [$status->value => app(AuditRequestStatusMapper::class)->mapForDisplay($status->value)])
                            ->all()
                    ),
                Filter::make('submitted')
                    ->schema([
                        DatePicker::make('submitted_from')->label(__('Submitted from')),
                        DatePicker::make('submitted_until')->label(__('Submitted until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['submitted_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['submitted_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Project'))->schema([
                TextEntry::make('repo_url')
                    ->label(__('Repository'))
                    ->url(fn (AuditRequest $record): ?string => $record->repo_url, shouldOpenInNewTab: true)
                    ->placeholder(__('No repository')),
                TextEntry::make('name')->label(__('Submitted by')),
                TextEntry::make('email'),
                TextEntry::make('source'),
                TextEntry::make('message')->placeholder('—'),
            ]),
            Section::make(__('Status & timeline'))->schema([
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextEntry::make('status_description')
                    ->label('')
                    ->state(fn (AuditRequest $record): string => static::statusDescription($record)),
                TextEntry::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->color('danger')
                    ->visible(fn (AuditRequest $record): bool => $record->failure_reason !== null),
                TextEntry::make('created_at')->label(__('Submitted'))->dateTime(config('app.datetime_format')),
                TextEntry::make('email_verified_at')->label(__('Email verified'))->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('report.created_at')->label(__('Completed'))->dateTime(config('app.datetime_format'))->placeholder('—'),
            ]),
            Section::make(__('Results'))
                ->visible(fn (AuditRequest $record): bool => $record->report !== null)
                ->schema([
                    TextEntry::make('overall_score')
                        ->label(__('Overall score'))
                        ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                    TextEntry::make('category_scores')
                        ->label(__('Category scores'))
                        ->state(function (AuditRequest $record): string {
                            $scores = collect(data_get($record->report?->payload, 'scores', []))
                                ->except('overall')
                                ->map(fn ($value, $key) => __(ucfirst(str_replace('_', ' ', $key))).': '.$value);

                            return $scores->isEmpty() ? '—' : $scores->implode(' · ');
                        }),
                    TextEntry::make('risks_summary')
                        ->label(__('Risks'))
                        ->state(function (AuditRequest $record): string {
                            $risks = collect(data_get($record->report?->payload, 'risks', []));

                            if ($risks->isEmpty()) {
                                return __('None found');
                            }

                            return $risks->countBy('impact')
                                ->map(fn (int $count, string $impact) => $count.' '.__($impact))
                                ->implode(' · ');
                        }),
                ]),
        ]);
    }

    public static function statusDescription(AuditRequest $record): string
    {
        return match ($record->status) {
            AuditRequestStatus::PENDING_VERIFICATION->value => __('Waiting for email confirmation.'),
            AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value => __('Your audit is queued and will start shortly.'),
            AuditRequestStatus::ANALYZING->value => __('We are analyzing your repository right now.'),
            AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value => __('Your report is ready.'),
            AuditRequestStatus::FAILED->value => __('This audit failed — see the reason below.'),
            AuditRequestStatus::NEEDS_FOLLOWUP->value => __('We need more information — please check your email.'),
            AuditRequestStatus::AWAITING_ACCESS->value => __('Invite flexpick-audit as a read-only collaborator on your GitHub repository. We launch the audit as soon as the invite lands.'),
            AuditRequestStatus::AWAITING_PAYMENT->value => __('This audit is waiting for an available analysis. Upgrade your plan or buy a run to start it.'),
            default => '',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditRequests::route('/'),
            'view' => ViewAuditRequest::route('/{record}'),
        ];
    }
}
```

Create `backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListAuditRequests extends ListRecords
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runNewAudit')
                ->label(__('Run new audit'))
                ->url(fn (): string => AuditReports::getUrl())
                ->visible(function (): bool {
                    $tenant = Filament::getTenant();

                    return $tenant !== null
                        && app(AuditEntitlementService::class)->remainingDashboardRuns(auth()->user(), $tenant) > 0;
                }),
        ];
    }
}
```

Create `backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ViewAuditRequest.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\AuditReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnline')
                ->label(__('View online'))
                ->url(fn (): string => app(AuditReportService::class)->signedUrl($this->record->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record->report !== null),
            Action::make('downloadPdf')
                ->label(__('Download PDF'))
                ->url(fn (): string => route('reports.download', $this->record->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record->report !== null),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter="Dashboard.*AuditRequestResourceTest"`
Expected: PASS (6 tests).

Also confirm no collision with the admin resource tests:
Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Filament`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Dashboard/Resources/AuditRequests tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php
git add backend/app/Filament/Dashboard/Resources/AuditRequests backend/tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php
git commit -m "feat(backend): read-only Audits resource in the user dashboard"
```

---

### Task 3: AuditStatsWidget (main-dashboard stat tiles)

**Files:**
- Create: `backend/app/Filament/Dashboard/Widgets/AuditStatsWidget.php`
- Test: `backend/tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php`

**Interfaces:**
- Consumes: `AuditRequest::forUser()`, `AuditEntitlementService` (`subscriptionAllowance`, `dashboardRunsUsedThisMonth`, `remainingDashboardRuns`, `freeRunsLimit`, `freeRunsUsed`, `hasAuditAccess`).
- Produces: `App\Filament\Dashboard\Widgets\AuditStatsWidget` — auto-discovered by the panel's existing `discoverWidgets` call; no provider changes needed.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Widgets\AuditStatsWidget;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditStatsWidgetTest extends FeatureTest
{
    public function test_subscriber_sees_allowance_usage_and_status_counts(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        // 2 dashboard runs this month; statuses: 1 in progress, 1 completed
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::ANALYZING->value]);
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::SENT->value]);
        AuditRequest::factory()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::FAILED->value, 'source' => 'web']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditStatsWidget::class)
            ->assertSee('3 / 5')   // remaining of allowance (2 dashboard runs used)
            ->assertSee('40')      // 40% used
            ->assertSee(__('In progress'))
            ->assertSee(__('Completed'))
            ->assertSee(__('Failed'));
    }

    public function test_free_user_sees_free_quota(): void
    {
        $user = User::factory()->create(['email' => 'free-widget@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->freeRun()->create(['email' => 'free-widget@example.com', 'user_id' => $user->id, 'status' => AuditRequestStatus::SENT->value]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditStatsWidget::class)
            ->assertSee(__('Free audits remaining'))
            ->assertSee('2 / 3');
    }

    public function test_hidden_for_user_without_audits_or_allowance(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(AuditStatsWidget::canView());
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }

    private function createActiveSubscriptionFor(Tenant $tenant, User $user, array $productMetadata): Subscription
    {
        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditStatsWidgetTest`
Expected: FAIL — `Class "App\Filament\Dashboard\Widgets\AuditStatsWidget" not found`.

- [ ] **Step 3: Implement the widget**

Create `backend/app/Filament/Dashboard/Widgets/AuditStatsWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Widgets;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $allowance = $tenant !== null ? $entitlements->subscriptionAllowance($tenant) : 0;

        if ($allowance > 0) {
            $used = $entitlements->dashboardRunsUsedThisMonth($user);
            $remaining = $entitlements->remainingDashboardRuns($user, $tenant);
            $usagePercent = (int) round(min($used, $allowance) / $allowance * 100);

            $quotaStat = Stat::make(__('Analyses remaining this month'), $remaining.' / '.$allowance)
                ->description(__(':percent% of your plan used', ['percent' => $usagePercent]))
                ->color($remaining > 0 ? 'success' : 'warning');
        } else {
            $limit = $entitlements->freeRunsLimit($user->email);
            $used = $entitlements->freeRunsUsed($user->email);

            $quotaStat = Stat::make(__('Free audits remaining'), max(0, $limit - $used).' / '.$limit)
                ->description(__(':used of :limit free audits used', ['used' => $used, 'limit' => $limit]))
                ->color($used < $limit ? 'success' : 'warning');
        }

        return [
            $quotaStat,
            Stat::make(__('In progress'), AuditRequest::forUser($user)->whereIn('status', [
                AuditRequestStatus::QUEUED->value,
                AuditRequestStatus::ANALYZING->value,
            ])->count())->color('info'),
            Stat::make(__('Completed'), AuditRequest::forUser($user)->whereIn('status', [
                AuditRequestStatus::REPORT_READY->value,
                AuditRequestStatus::SENT->value,
            ])->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::forUser($user)->where('status', AuditRequestStatus::FAILED->value)->count())
                ->color('danger'),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditStatsWidgetTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Dashboard/Widgets/AuditStatsWidget.php tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php
git add backend/app/Filament/Dashboard/Widgets/AuditStatsWidget.php backend/tests/Feature/Filament/Dashboard/AuditStatsWidgetTest.php
git commit -m "feat(backend): audit usage stats widget on the user dashboard"
```

---

### Task 4: RecentAuditsWidget (main-dashboard table)

**Files:**
- Create: `backend/app/Filament/Dashboard/Widgets/RecentAuditsWidget.php`
- Test: `backend/tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php`

**Interfaces:**
- Consumes: `AuditRequest::forUser()`, `hasAuditAccess()`, `AuditRequestStatusMapper`, `AuditRequestResource::getUrl('view', ['record' => $record])` from Task 2.
- Produces: `App\Filament\Dashboard\Widgets\RecentAuditsWidget` — auto-discovered.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Widgets\RecentAuditsWidget;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class RecentAuditsWidgetTest extends FeatureTest
{
    public function test_shows_last_five_own_audits_only(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        foreach (range(1, 6) as $i) {
            AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => "https://github.com/acme/recent-{$i}",
                'status' => AuditRequestStatus::SENT->value,
                'created_at' => now()->subDays(7 - $i),
            ]);
        }
        AuditRequest::factory()->create(['repo_url' => 'https://github.com/acme/foreign-recent']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(RecentAuditsWidget::class)
            ->assertSee('recent-6')
            ->assertSee('recent-2')
            ->assertDontSee('recent-1')       // 6th-newest falls off the 5-row list
            ->assertDontSee('foreign-recent'); // isolation
    }

    public function test_hidden_for_user_without_audits_or_allowance(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(RecentAuditsWidget::canView());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=RecentAuditsWidgetTest`
Expected: FAIL — `Class "App\Filament\Dashboard\Widgets\RecentAuditsWidget" not found`.

- [ ] **Step 3: Implement the widget**

Create `backend/app/Filament/Dashboard/Widgets/RecentAuditsWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentAuditsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent audits'))
            ->query(
                AuditRequest::forUser(auth()->user())
                    ->latest()
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('repo_url')
                    ->label(__('Repository'))
                    ->limit(50)
                    ->placeholder(__('No repository')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format')),
            ])
            ->recordUrl(fn (AuditRequest $record): string => AuditRequestResource::getUrl('view', ['record' => $record]));
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=RecentAuditsWidgetTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Dashboard/Widgets/RecentAuditsWidget.php tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php
git add backend/app/Filament/Dashboard/Widgets/RecentAuditsWidget.php backend/tests/Feature/Filament/Dashboard/RecentAuditsWidgetTest.php
git commit -m "feat(backend): recent audits table widget on the user dashboard"
```

---

### Task 5: Full regression gate and manual verification

**Files:** none (verification only).

**Interfaces:**
- Consumes: everything above; the Spec 1 demo seeder accounts.
- Produces: verified feature.

- [ ] **Step 1: Full backend suite and static analysis**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS, 0 failures.

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 2: Manual verification against demo users (requires Spec 1's demo seeder)**

Run: `docker compose exec laravel.test php artisan db:seed --class="Database\Seeders\Demo\DemoDatabaseSeeder"` (if not already seeded), then at `http://localhost:8080/dashboard`:

1. `audit-starter-demo@flexpick.net` / `password` — main dashboard shows the stats row (remaining of 5, in progress/completed/failed counts) and the recent-audits table; "Audits" appears in navigation; list shows the seeded audits with correct badges; a `sent` audit's view page shows scores and both report links; a `failed` audit's view page shows the failure reason; an `awaiting_access` audit shows the invite instructions.
2. `audit-exhausted-demo@flexpick.net` — quota tile shows "0 / 3" free audits.
3. A fresh user with no audits/subscription (register a new account) — no Audits nav item, no audit widgets.
4. Confirm the existing Audit Reports page (launch, schedules, trends) still works unchanged.

- [ ] **Step 3: Report**

Summarize test results and the manual matrix outcomes.
