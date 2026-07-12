# Growth, Conversion & Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Instrument the audit funnel, remove the conversion blockers in the $5 unlock flow, add recovery emails and a live status page, make the report substantively better (real dependency/secret audits, deterministic scores, git insights), add retention loops (deltas + scheduled re-audits), and close the marketing-site trust gaps (legal pages, OG image, structured data, product positioning).

**Architecture:** Backend work extends the existing `App\Services\AuditReport\*` pipeline (SaaSykit Tenancy, Laravel 13, Filament 5) — new small services (`AuditFunnelRecorder`, `DependencyAuditor`, `ScoreCalculator`, `AuditDeltaService`, `AuditGuestAccountService`), three new migrations, two scheduled commands, and edits to the existing controllers/mailables/blades. Frontend work is confined to the static Astro site (`index.astro`, `ContactModal.astro`, two new legal pages, an OG-image build script).

**Tech Stack:** PHP 8.4 / Laravel 13 / Filament 5 / Livewire 4 / PHPUnit (backend); Astro 6 / Tailwind 4 / Node ≥ 22.12 (frontend); OSV.dev REST API for dependency vulnerabilities.

## Global Constraints

- Backend commands run from `backend/`. In the Docker dev env prefix with `docker compose exec laravel.test` (e.g. `docker compose exec laravel.test php artisan test --compact --filter=X`). Bare commands below assume you are inside `backend/`.
- Test gate per task: `php artisan test --compact --filter=<TestClass>`; before each commit run `vendor/bin/pint --dirty`. Do **not** gate on PHPStan — the repo has 394 pre-existing errors (accepted deviation).
- Frontend gate per task (run from `frontend/`): `npm run check && npm run build`.
- All new user-facing backend strings wrapped in `__()`. Follow existing file style (see neighboring classes/views).
- Price/copy facts (verbatim, do not invent others): unlock is **$5** (product slug `audit-report-unlock`, config `audit.unlock_product_slug`); plans are **$10/$30/$60 per month for 5/20/50 analyses**; free limit is **3 audits per email** (`audit.free_reports_limit`).
- **Never fabricate social proof**: no invented testimonials, client names, customer counts, or ROI numbers anywhere.
- Queue/statuses: `App\Constants\AuditRequestStatus` enum values are strings (`pending_verification`, `new`, `queued`, `analyzing`, `report_ready`, `sent`, `failed`, `needs_followup`, `handled`, `awaiting_access`, `awaiting_payment`).
- Tests extend `Tests\Feature\FeatureTest` (migrates + seeds once per class, `withoutExceptionHandling()` by default — call `$this->withExceptionHandling()` when asserting 4xx). Scope every DB assertion to the models the test created (the DB is shared across tests in a class run).
- Commit after every task: `git add <files> && git commit -m "<type>(backend|frontend): <summary>"`.

## Out of Scope (deliberately)

- GitHub App / PR-check integration, README score badges (future retention anchors).
- Real testimonials/case studies — requires real client quotes; do not fake them.
- Creating the GA4 property — the measurement ID is pasted into `frontend/src/config.yaml` by the operator at deploy time; all code must work with it unset.
- Churn×complexity "risk map" grid visualization (we ship hotspot *lists*; the grid is a later design task).

## Phases

| Phase | Tasks | Ships |
|---|---|---|
| 1. Measure | 1–3 | Funnel events table + admin funnel page + GA/modal events & error copy |
| 2. Convert | 4–7 | Guest-friendly $5 unlock, prepaid $5 run, live status page, job retries |
| 3. Recover | 8–10 | Verification reminder, abandoned-unlock reminder, dashboard nav fix |
| 4. Report value | 11–16 | Repo facts section, OSV dependency audit, secret patterns, tooling detection, deterministic scores, git insights |
| 5. Retain | 17–18 | Score deltas (view + email), scheduled re-audits |
| 6. Marketing | 19–21 | Legal pages, OG image + JSON-LD + package identity, product-path section |

Tasks within a phase are ordered; Task 5 depends on Task 4, Task 17 on Task 15 being merged is **not** required (deltas work with LLM scores too). Everything else is independent across phases.

---

### Task 1: Audit funnel event recording

**Files:**
- Create: `backend/database/migrations/2026_07_13_000001_create_audit_funnel_events_table.php`
- Create: `backend/app/Models/AuditFunnelEvent.php`
- Create: `backend/app/Services/AuditReport/AuditFunnelRecorder.php`
- Create: `backend/app/Services/AuditReport/AuditFunnelStats.php`
- Modify: `backend/app/Services/AuditRequestService.php` (constructor + `submit`, `routeVerified`, `markFailed`)
- Modify: `backend/app/Services/AuditReport/AuditReportService.php` (new constructor + `send`, `unlock`)
- Modify: `backend/app/Http/Controllers/AuditRequestController.php` (`verify`)
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (`show`, `unlock`)
- Test: `backend/tests/Feature/Services/AuditFunnelTest.php`

**Interfaces:**
- Produces: `AuditFunnelRecorder::record(string $stage, ?AuditRequest $auditRequest = null, array $meta = []): void` with stage constants `STAGE_SUBMITTED|STAGE_VERIFIED|STAGE_QUEUED|STAGE_AWAITING_PAYMENT|STAGE_REPORT_SENT|STAGE_REPORT_VIEWED|STAGE_UNLOCK_STARTED|STAGE_UNLOCK_PAID|STAGE_RUN_PURCHASED|STAGE_FAILED` and `AuditFunnelRecorder::STAGES` (ordered array of all ten). `AuditFunnelStats::counts(int $days = 30): array` returning `[stage => int]` for **all** stages (zero-filled). Tasks 2, 4, 5 consume these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditFunnelStats;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditFunnelTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_recorder_stores_stage_request_and_meta(): void
    {
        $request = AuditRequest::factory()->create();

        app(AuditFunnelRecorder::class)->record(AuditFunnelRecorder::STAGE_QUEUED, $request, ['source' => 'web']);

        $event = AuditFunnelEvent::where('audit_request_id', $request->id)->firstOrFail();
        $this->assertSame('queued', $event->stage);
        $this->assertSame(['source' => 'web'], $event->meta);
        $this->assertNotNull($event->created_at);
    }

    public function test_submit_records_submitted_stage(): void
    {
        app(AuditRequestService::class)->submit(['name' => 'Ada', 'email' => 'funnel-submit@example.com']);

        $request = AuditRequest::where('email', 'funnel-submit@example.com')->firstOrFail();
        $this->assertSame(1, AuditFunnelEvent::where('audit_request_id', $request->id)
            ->where('stage', AuditFunnelRecorder::STAGE_SUBMITTED)->count());
    }

    public function test_stats_zero_fills_all_stages_and_respects_window(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted']);
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'verified']);
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted'])
            ->forceFill(['created_at' => now()->subDays(40)])->save();

        $counts = app(AuditFunnelStats::class)->counts(30);

        $this->assertSame(array_values(AuditFunnelRecorder::STAGES), array_keys($counts));
        $this->assertGreaterThanOrEqual(1, $counts['submitted']);
        $this->assertGreaterThanOrEqual(1, $counts['verified']);
        $this->assertSame(0, $counts['unlock_paid']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditFunnelTest`
Expected: FAIL — `Class "App\Services\AuditReport\AuditFunnelRecorder" not found`

- [ ] **Step 3: Implement migration, model, recorder, stats**

`backend/database/migrations/2026_07_13_000001_create_audit_funnel_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_funnel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 40)->index();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_funnel_events');
    }
};
```

`backend/app/Models/AuditFunnelEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFunnelEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['audit_request_id', 'stage', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
```

`backend/app/Services/AuditReport/AuditFunnelRecorder.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;

class AuditFunnelRecorder
{
    public const STAGE_SUBMITTED = 'submitted';

    public const STAGE_VERIFIED = 'verified';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_AWAITING_PAYMENT = 'awaiting_payment';

    public const STAGE_REPORT_SENT = 'report_sent';

    public const STAGE_REPORT_VIEWED = 'report_viewed';

    public const STAGE_UNLOCK_STARTED = 'unlock_started';

    public const STAGE_UNLOCK_PAID = 'unlock_paid';

    public const STAGE_RUN_PURCHASED = 'run_purchased';

    public const STAGE_FAILED = 'failed';

    public const STAGES = [
        self::STAGE_SUBMITTED,
        self::STAGE_VERIFIED,
        self::STAGE_QUEUED,
        self::STAGE_AWAITING_PAYMENT,
        self::STAGE_REPORT_SENT,
        self::STAGE_REPORT_VIEWED,
        self::STAGE_UNLOCK_STARTED,
        self::STAGE_UNLOCK_PAID,
        self::STAGE_RUN_PURCHASED,
        self::STAGE_FAILED,
    ];

    public function record(string $stage, ?AuditRequest $auditRequest = null, array $meta = []): void
    {
        AuditFunnelEvent::create([
            'audit_request_id' => $auditRequest?->id,
            'stage' => $stage,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
```

`backend/app/Services/AuditReport/AuditFunnelStats.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditFunnelEvent;

class AuditFunnelStats
{
    public function counts(int $days = 30): array
    {
        $rows = AuditFunnelEvent::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        return collect(AuditFunnelRecorder::STAGES)
            ->mapWithKeys(fn (string $stage) => [$stage => (int) ($rows[$stage] ?? 0)])
            ->all();
    }
}
```

- [ ] **Step 4: Instrument the existing flow**

`AuditRequestService` — add the recorder to the constructor and record at each branch:

```php
public function __construct(
    private AuditEntitlementService $entitlements,
    private RepositoryCloner $cloner,
    private AuditFunnelRecorder $funnel,
) {}
```

In `submit()`, directly after `AuditRequest::create([...])` (before the Mail line): `$this->funnel->record(AuditFunnelRecorder::STAGE_SUBMITTED, $auditRequest);`

In `routeVerified()`: in the quota-exhausted branch, after the status update add `$this->funnel->record(AuditFunnelRecorder::STAGE_AWAITING_PAYMENT, $auditRequest);` — and in the success branch, after `GenerateAuditReport::dispatch(...)` add `$this->funnel->record(AuditFunnelRecorder::STAGE_QUEUED, $auditRequest);`

In `markFailed()`, after the status update: `$this->funnel->record(AuditFunnelRecorder::STAGE_FAILED, $auditRequest, ['reason' => $reason]);`

Add `use App\Services\AuditReport\AuditFunnelRecorder;` to the imports.

`AuditReportService` — add a constructor and record send/paid-unlock:

```php
public function __construct(
    private AuditFunnelRecorder $funnel,
) {}
```

In `send()`, after the status update: `$this->funnel->record(AuditFunnelRecorder::STAGE_REPORT_SENT, $report->auditRequest);`

In `unlock()`, after `$report->update([...])`: 

```php
if ($order !== null) {
    $this->funnel->record(AuditFunnelRecorder::STAGE_UNLOCK_PAID, $report->auditRequest);
}
```

`AuditRequestController::verify` — record first-time verification (method injection):

```php
public function verify(AuditRequest $auditRequest, AuditFunnelRecorder $funnel)
{
    if ($auditRequest->email_verified_at === null) {
        $auditRequest->update(['email_verified_at' => now()]);
        $funnel->record(AuditFunnelRecorder::STAGE_VERIFIED, $auditRequest);
        RouteVerifiedAuditRequest::dispatch($auditRequest);
    }

    return view('audit.verified', ['auditRequest' => $auditRequest]);
}
```

`AuditReportController` — in `show()`, before the `return view(...)`: 

```php
app(AuditFunnelRecorder::class)->record(
    AuditFunnelRecorder::STAGE_REPORT_VIEWED,
    $auditReport->auditRequest,
    ['unlocked' => $auditReport->unlocked_at !== null],
);
```

In `unlock()`, directly before the final `return redirect()->route('buy.product', ...)`:

```php
app(AuditFunnelRecorder::class)->record(AuditFunnelRecorder::STAGE_UNLOCK_STARTED, $auditReport->auditRequest);
```

Add `use App\Services\AuditReport\AuditFunnelRecorder;` to both controllers.

- [ ] **Step 5: Run tests, then the full audit suite**

Run: `php artisan test --compact --filter=AuditFunnelTest`
Expected: PASS (3 tests)

Run: `php artisan test --compact --filter=Audit`
Expected: PASS — existing audit tests must not regress (they resolve the services via the container, so the new constructor args are auto-injected).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/AuditFunnelEvent.php app/Services tests app/Http/Controllers
git commit -m "feat(backend): record audit funnel events at every stage"
```

---

### Task 2: Admin funnel page

**Files:**
- Create: `backend/app/Filament/Admin/Pages/AuditFunnel.php`
- Create: `backend/resources/views/filament/admin/pages/audit-funnel.blade.php`
- Test: `backend/tests/Feature/Filament/AuditFunnelPageTest.php`

**Interfaces:**
- Consumes: `AuditFunnelStats::counts(int $days): array` and `AuditFunnelRecorder::STAGES` from Task 1.

- [ ] **Step 1: Confirm the admin pages namespace**

Run: `ls app/Filament/Admin/Pages/ | head`
Expected: existing page classes. Open one and mirror its base class/property style if it differs from the code below (Consistency First). The Dashboard panel's `AuditReports` page (plain `Filament\Pages\Page`, `protected string $view`, `getViewData()`) is the in-repo pattern this follows.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\AuditFunnel;
use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditFunnelPageTest extends FeatureTest
{
    public function test_admin_can_view_funnel_counts(): void
    {
        $admin = $this->createAdminUser(); // FeatureTest helper; if named differently, mirror an existing Admin page test in tests/Feature/Filament
        $request = AuditRequest::factory()->create();
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted']);

        $this->actingAs($admin);

        Livewire::test(AuditFunnel::class)
            ->assertOk()
            ->assertSee(__('Audit Funnel'))
            ->assertSee('submitted');
    }
}
```

Note: check `tests/Feature/Filament/` for how existing admin-page tests authenticate (helper name for creating an admin user, panel setup). Use the same arrangement; the assertion block stays as written.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditFunnelPageTest`
Expected: FAIL — class `App\Filament\Admin\Pages\AuditFunnel` not found

- [ ] **Step 4: Implement page + view**

`backend/app/Filament/Admin/Pages/AuditFunnel.php`:

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Services\AuditReport\AuditFunnelStats;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditFunnel extends Page
{
    protected string $view = 'filament.admin.pages.audit-funnel';

    public function getTitle(): string|Htmlable
    {
        return __('Audit Funnel');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Audit Funnel');
    }

    public function getViewData(): array
    {
        $stats = app(AuditFunnelStats::class);

        return [
            'last7' => $stats->counts(7),
            'last30' => $stats->counts(30),
        ];
    }
}
```

`backend/resources/views/filament/admin/pages/audit-funnel.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-2">{{ __('Stage') }}</th>
                    <th class="py-2">{{ __('Last 7 days') }}</th>
                    <th class="py-2">{{ __('Last 30 days') }}</th>
                    <th class="py-2">{{ __('% of submitted (30d)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($last30 as $stage => $count)
                    <tr class="border-t">
                        <td class="py-2 font-medium">{{ $stage }}</td>
                        <td class="py-2">{{ $last7[$stage] }}</td>
                        <td class="py-2">{{ $count }}</td>
                        <td class="py-2 text-gray-500">
                            {{ $last30['submitted'] > 0 ? round($count / $last30['submitted'] * 100) . '%' : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-4 text-xs text-gray-500">
            {{ __('submitted → verified → queued → report_sent → report_viewed → unlock_started → unlock_paid is the paid-report funnel; awaiting_payment, run_purchased and failed are side branches.') }}
        </p>
    </x-filament::section>
</x-filament-panels::page>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=AuditFunnelPageTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament resources/views/filament tests/Feature/Filament
git commit -m "feat(backend): admin audit-funnel page with 7/30-day stage counts"
```

---

### Task 3: Modal error specificity + client analytics events

**Files:**
- Modify: `frontend/src/components/widgets/ContactModal.astro` (submit handler + `open()`)

**Interfaces:**
- Consumes: backend `POST /api/audit-requests` returns 201, 422 (validation), or 429 (duplicate within 10 min / throttle) — already live.
- Produces: `gtag('event', 'audit_modal_open' | 'audit_request_submitted')` when GA is present. GA activates when the operator sets `analytics.vendors.googleAnalytics.id` in `frontend/src/config.yaml` (leave it `null` in code; `Analytics.astro` already renders nothing when unset).

- [ ] **Step 1: Update the submit handler and open() in `ContactModal.astro`**

Add a `track` helper at the top of the `<script>` (inside `initAuditModal`, before `open()`):

```ts
const track = (name: string) => {
  (window as unknown as { gtag?: (...args: unknown[]) => void }).gtag?.('event', name);
};
```

In `open()`, after `modal.classList.add('is-open');` add: `track('audit_modal_open');`

Replace the whole `form.addEventListener('submit', ...)` block with:

```ts
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(form);
  errorEl.hidden = true;
  submitBtn.disabled = true;
  try {
    const res = await fetch(form.dataset.endpoint || '', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        name: fd.get('name'),
        email: fd.get('email'),
        repo_url: fd.get('link') || null,
        message: fd.get('message') || null,
        website: fd.get('website') || '',
        marketing_consent: fd.get('consent') === 'on',
      }),
    });
    if (!res.ok) {
      errorEl.textContent =
        res.status === 429
          ? 'We already have a recent request from this email — check your inbox for the confirmation link.'
          : res.status === 422
            ? 'Please double-check your name and email address.'
            : 'Something went wrong — please try again, or email hello@flexpick.net.';
      errorEl.hidden = false;
      return;
    }
    track('audit_request_submitted');
    formWrap.hidden = true;
    sentWrap.hidden = false;
  } catch {
    errorEl.textContent = 'Something went wrong — please try again, or email hello@flexpick.net.';
    errorEl.hidden = false;
  } finally {
    submitBtn.disabled = false;
  }
});
```

- [ ] **Step 2: Verify build and lint**

Run (from `frontend/`): `npm run check && npm run build`
Expected: both pass, no new warnings.

- [ ] **Step 3: Manual smoke test**

Run: `npm run dev`, open `http://localhost:4321`, open the modal, submit twice with the same email (backend running) — second attempt must show the "recent request" message, not the generic one.

- [ ] **Step 4: Commit**

```bash
git add src/components/widgets/ContactModal.astro
git commit -m "feat(frontend): specific modal error messages and GA event hooks"
```

---

### Task 4: Guest-friendly $5 unlock (kill the auth wall)

**Files:**
- Create: `backend/app/Services/AuditGuestAccountService.php`
- Modify: `backend/routes/web.php:216-218` (unlock route: `auth` → `signed`)
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (`show`, `unlock`)
- Modify: `backend/resources/views/reports/audit-web.blade.php:119` (signed unlock URL)
- Test: `backend/tests/Feature/Http/Controllers/AuditReportGuestUnlockTest.php`

**Interfaces:**
- Consumes: `UserService::createUser(array $data, bool $dispatchRegisterEvent = false): User`, `UserService::findByEmail(string $email): ?User` (existing); `HandleAuditUnlockOrder::INTENT_PARAM` (existing); `AuditFunnelRecorder` (Task 1).
- Produces: `AuditGuestAccountService::resolveUser(AuditRequest $auditRequest): ?User` — returns the acting user (current auth user, or a freshly created + logged-in account for the audit email), or `null` when an existing account owns that email and the visitor must log in. Task 5 reuses this. The `reports.unlock` route becomes `signed`; the report page passes `$unlockUrl` to the blade.

**Design notes (why this is safe):** the report page is only reachable via a signed URL delivered to the verified email, so possession of the link proves control of the inbox. We therefore auto-create + log in an account for that email — but **only if no account exists yet**. If a real account already exists, we never auto-login (a shared report link must not grant access to an existing account); we redirect to login with the intended URL preserved.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditReportGuestUnlockTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function lockedReportFor(string $email): AuditReport
    {
        $request = AuditRequest::factory()->verified()->create(['email' => $email, 'name' => 'Guest User']);

        return AuditReport::factory()->locked()->create(['audit_request_id' => $request->id, 'user_id' => null]);
    }

    private function signedUnlockUrl(AuditReport $report): string
    {
        return URL::temporarySignedRoute('reports.unlock', now()->addDay(), ['auditReport' => $report->uuid]);
    }

    public function test_guest_unlock_creates_account_logs_in_and_redirects_to_checkout(): void
    {
        $report = $this->lockedReportFor('guest-unlock@example.com');

        $response = $this->get($this->signedUnlockUrl($report));

        $user = User::where('email', 'guest-unlock@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($user->id, $report->refresh()->user_id);
        $this->assertSame($report->uuid, UserParameter::where('user_id', $user->id)
            ->where('name', HandleAuditUnlockOrder::INTENT_PARAM)->value('value'));
        $response->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));
    }

    public function test_existing_account_is_never_auto_logged_in(): void
    {
        $existing = User::factory()->create(['email' => 'already-registered@example.com']);
        $report = $this->lockedReportFor('already-registered@example.com');

        $response = $this->get($this->signedUnlockUrl($report));

        $this->assertGuest();
        $this->assertNull($report->refresh()->user_id);
        $response->assertRedirect(route('login'));
    }

    public function test_logged_in_owner_still_reaches_checkout(): void
    {
        $report = $this->lockedReportFor('owner-unlock@example.com');
        $user = User::factory()->create(['email' => 'owner-unlock@example.com']);

        $this->actingAs($user)
            ->get($this->signedUnlockUrl($report))
            ->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));

        $this->assertSame($user->id, $report->refresh()->user_id);
    }

    public function test_unsigned_unlock_request_is_rejected(): void
    {
        $this->withExceptionHandling();
        $report = $this->lockedReportFor('unsigned-unlock@example.com');

        $this->get('/reports/'.$report->uuid.'/unlock')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditReportGuestUnlockTest`
Expected: FAIL — guest request redirected to login (current `auth` middleware), no user created.

- [ ] **Step 3: Implement**

`backend/app/Services/AuditGuestAccountService.php`:

```php
<?php

namespace App\Services;

use App\Models\AuditRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

class AuditGuestAccountService
{
    public function __construct(
        private UserService $userService,
    ) {}

    /**
     * Resolve the user acting on a verified audit request's behalf.
     * Creates + logs in a new account for the audit email when none exists.
     * Returns null when an existing account owns the email (visitor must log in).
     */
    public function resolveUser(AuditRequest $auditRequest): ?User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        if ($this->userService->findByEmail($auditRequest->email) !== null) {
            return null;
        }

        $user = $this->userService->createUser([
            'name' => $auditRequest->name,
            'email' => $auditRequest->email,
        ]);

        $user->email_verified_at = now();
        $user->save();

        event(new Registered($user));

        auth()->login($user);

        return $user;
    }
}
```

(The `Registered` event fires the existing `LinkAuditReportsToUser` listener, which claims all of this email's reports. `createUser` hashes a random password; the user can set a real one via password reset later.)

`routes/web.php` — change the unlock route middleware:

```php
Route::get('/reports/{auditReport:uuid}/unlock', [AuditReportController::class, 'unlock'])
    ->name('reports.unlock')
    ->middleware('signed');
```

`AuditReportController::unlock` — replace the method body (keep the Task 1 funnel line):

```php
public function unlock(AuditReport $auditReport, AuditGuestAccountService $guestAccounts)
{
    $user = $guestAccounts->resolveUser($auditReport->auditRequest);

    if ($user === null) {
        return redirect()->guest(route('login'))->with('status', __(
            'An account already exists for :email — log in to unlock this report.',
            ['email' => $auditReport->auditRequest->email],
        ));
    }

    if ($auditReport->user_id === null && strtolower($auditReport->auditRequest->email) === strtolower($user->email)) {
        $auditReport->user_id = $user->id;
        $auditReport->save();
    }

    abort_unless($auditReport->user_id === $user->id, 403);

    if ($auditReport->unlocked_at !== null) {
        return redirect(app(AuditReportService::class)->signedUrl($auditReport));
    }

    UserParameter::updateOrCreate(
        ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM],
        ['value' => $auditReport->uuid],
    );

    app(AuditFunnelRecorder::class)->record(AuditFunnelRecorder::STAGE_UNLOCK_STARTED, $auditReport->auditRequest);

    return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
}
```

Add `use App\Services\AuditGuestAccountService;` to the controller imports.

`AuditReportController::show` — pass a signed unlock URL to the view (add to the view data array):

```php
'unlockUrl' => URL::temporarySignedRoute(
    'reports.unlock',
    now()->addDays((int) config('audit.report_link_days')),
    ['auditReport' => $auditReport->uuid],
),
```

Add `use Illuminate\Support\Facades\URL;`. In `sample()`, add `'unlockUrl' => null,` to its view data (the sample renders unlocked, the CTA card never shows).

`resources/views/reports/audit-web.blade.php` line 119 — replace:

```blade
<a class="btn" href="{{ url('/reports/'.$report->uuid.'/unlock') }}">{{ __('Unlock for $5') }}</a>
```

with:

```blade
<a class="btn" href="{{ $unlockUrl }}">{{ __('Unlock for $5') }}</a>
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=AuditReportGuestUnlockTest`
Expected: PASS (4 tests)

Run: `php artisan test --compact --filter=Audit`
Expected: PASS — no regressions (any existing test hitting `reports.unlock` with `actingAs` + plain URL must be updated to use a signed URL; adjust those assertions, not the behavior).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add routes app/Services app/Http resources/views tests
git commit -m "feat(backend): signed guest-friendly unlock flow, no auth wall before checkout"
```

---

### Task 5: Prepaid $5 run when free quota is exhausted

**Files:**
- Create: `backend/database/migrations/2026_07_13_000002_add_prepaid_to_audit_requests_table.php`
- Modify: `backend/app/Models/AuditRequest.php` (fillable + cast)
- Modify: `backend/routes/web.php` (new signed route)
- Modify: `backend/app/Http/Controllers/AuditRequestController.php` (`purchaseRun`)
- Modify: `backend/app/Services/AuditRequestService.php` (`purchaseRunUrl`, pass URL to quota mail)
- Modify: `backend/app/Mail/Audit/AuditQuotaExhausted.php` + `backend/resources/views/emails/audit/quota-exhausted.blade.php`
- Modify: `backend/app/Listeners/Order/HandleAuditUnlockOrder.php` (run-intent handling)
- Modify: `backend/app/Services/AuditReport/AuditReportService.php` (`create` unlock condition)
- Test: `backend/tests/Feature/Services/AuditPrepaidRunTest.php`

**Interfaces:**
- Consumes: `AuditGuestAccountService::resolveUser` (Task 4), `AuditFunnelRecorder` (Task 1), existing `HandleAuditUnlockOrderTest::unlockOrderFor()` arrangement for paid-order fixtures.
- Produces: `HandleAuditUnlockOrder::RUN_INTENT_PARAM = 'audit_run_intent'`; route `audit-requests.purchase-run` (signed); `audit_requests.prepaid` boolean; `AuditRequestService::purchaseRunUrl(AuditRequest): string` (7-day signed URL). Prepaid reports are born unlocked.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditPrepaidRunTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function unlockOrderFor(User $user): Order
    {
        $product = OneTimeProduct::firstOrCreate(
            ['slug' => config('audit.unlock_product_slug')],
            ['name' => 'Audit Report Unlock', 'description' => 'Unlock full audit report', 'features' => []],
        );
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
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

    public function test_purchase_run_link_sets_run_intent_and_redirects_to_checkout(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'email' => 'prepaid-run@example.com',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);

        $response = $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));

        $user = User::where('email', 'prepaid-run@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($request->uuid, UserParameter::where('user_id', $user->id)
            ->where('name', HandleAuditUnlockOrder::RUN_INTENT_PARAM)->value('value'));
        $response->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));
    }

    public function test_paid_run_intent_queues_the_audit_as_prepaid(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $request = AuditRequest::factory()->verified()->create([
            'email' => $user->email,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM, 'value' => $request->uuid]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $request->refresh();
        $this->assertTrue($request->prepaid);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM]);
    }

    public function test_prepaid_request_report_is_born_unlocked_with_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->create(['prepaid' => true]);

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
    }

    private function payload(): array
    {
        return \App\Models\AuditReport::factory()->raw()['payload'];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditPrepaidRunTest`
Expected: FAIL — unknown column `prepaid`, missing route/constant.

- [ ] **Step 3: Implement**

Migration `2026_07_13_000002_add_prepaid_to_audit_requests_table.php`:

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
            $table->boolean('prepaid')->default(false)->after('free_run');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('prepaid');
        });
    }
};
```

`AuditRequest` model: add `'prepaid'` to `$fillable` and `'prepaid' => 'boolean'` to `$casts`.

`routes/web.php` (next to the other audit routes):

```php
Route::get('/audit-requests/{auditRequest:uuid}/purchase-run', [AuditRequestController::class, 'purchaseRun'])
    ->name('audit-requests.purchase-run')
    ->middleware('signed');
```

`AuditRequestController::purchaseRun`:

```php
public function purchaseRun(AuditRequest $auditRequest, AuditGuestAccountService $guestAccounts)
{
    abort_unless($auditRequest->status === AuditRequestStatus::AWAITING_PAYMENT->value, 404);

    $user = $guestAccounts->resolveUser($auditRequest);

    if ($user === null) {
        return redirect()->guest(route('login'))->with('status', __(
            'An account already exists for :email — log in to pay for this audit.',
            ['email' => $auditRequest->email],
        ));
    }

    UserParameter::updateOrCreate(
        ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM],
        ['value' => $auditRequest->uuid],
    );

    return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
}
```

Imports to add: `App\Constants\AuditRequestStatus`, `App\Listeners\Order\HandleAuditUnlockOrder`, `App\Models\UserParameter`, `App\Services\AuditGuestAccountService`.

`AuditRequestService` — add:

```php
public function purchaseRunUrl(AuditRequest $auditRequest): string
{
    return URL::temporarySignedRoute(
        'audit-requests.purchase-run',
        now()->addDays(7),
        ['auditRequest' => $auditRequest->uuid],
    );
}
```

and in `routeVerified()`'s quota branch change the mail line to:

```php
Mail::to($auditRequest->email)->send(new AuditQuotaExhausted($auditRequest, $this->purchaseRunUrl($auditRequest)));
```

`AuditQuotaExhausted` — add a second constructor property:

```php
public function __construct(
    public AuditRequest $auditRequest,
    public string $purchaseUrl,
) {}
```

`quota-exhausted.blade.php` — replace the last two `<p>` blocks (plans paragraph + pricing link) with:

```blade
<p style="margin: 16px 0 0; line-height: 24px">
    {{ __('Two ways to keep going:') }}
</p>
<p style="margin: 24px 0 0; line-height: 24px">
    <a href="{{ $purchaseUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Run this audit now for $5 — full report included') }}</a>
</p>
<p style="margin: 12px 0 0; line-height: 24px">
    <a href="{{ url('/pricing') }}" style="color: #2563eb; text-decoration: underline;">{{ __('Or subscribe from $10/month for 5 analyses') }}</a>
</p>
```

`HandleAuditUnlockOrder` — add the constant, funnel dependency, and intent dispatch. Full updated class body (keep the existing imports plus the new ones shown):

```php
<?php

namespace App\Listeners\Order;

use App\Constants\AuditRequestStatus;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;

class HandleAuditUnlockOrder
{
    public const INTENT_PARAM = 'audit_unlock_intent';

    public const RUN_INTENT_PARAM = 'audit_run_intent';

    public function __construct(
        private AuditReportService $reportService,
        private AuditFunnelRecorder $funnel,
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

        if ($this->handleUnlockIntent($order) || $this->handleRunIntent($order)) {
            return;
        }

        $report = AuditReport::query()
            ->where('user_id', $order->user_id)
            ->whereNull('unlocked_at')
            ->latest()
            ->first();

        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }
    }

    private function handleUnlockIntent(Order $order): bool
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return false;
        }

        $report = AuditReport::query()->where('uuid', $intent->value)->first();
        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }
        $intent->delete();

        return true;
    }

    private function handleRunIntent(Order $order): bool
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::RUN_INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return false;
        }

        $auditRequest = AuditRequest::query()
            ->where('uuid', $intent->value)
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->first();

        if ($auditRequest !== null) {
            $auditRequest->update(['prepaid' => true, 'status' => AuditRequestStatus::QUEUED->value]);
            GenerateAuditReport::dispatch($auditRequest);
            $this->funnel->record(AuditFunnelRecorder::STAGE_RUN_PURCHASED, $auditRequest);
        }
        $intent->delete();

        return true;
    }
}
```

Behavior note: a consumed-but-dangling unlock intent (report deleted) no longer falls through to the latest-locked-report fallback — it now short-circuits. That's intentional; the fallback remains for intent-less orders.

`AuditReportService::create` — change the unlock condition line to:

```php
$unlocked = $auditRequest->source === 'dashboard' || $wasUnlocked || $auditRequest->prepaid;
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="AuditPrepaidRunTest|HandleAuditUnlockOrderTest|AuditReportUnlockTest"`
Expected: PASS — new tests green, existing listener/unlock tests unaffected.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database app/Models app/Http app/Services app/Mail app/Listeners resources/views routes tests
git commit -m "feat(backend): prepaid \$5 audit run for exhausted free quota"
```

---

### Task 6: Live status page

**Files:**
- Modify: `backend/routes/web.php` (two new signed routes)
- Modify: `backend/app/Http/Controllers/AuditRequestController.php` (`verify` redirect, `status`, `statusJson`, `label`)
- Modify: `backend/app/Services/AuditRequestService.php` (`statusUrl`, pass status link to received mail)
- Modify: `backend/app/Mail/Audit/AuditRequestReceived.php` + `backend/resources/views/emails/audit/received.blade.php`
- Create: `backend/resources/views/audit/status.blade.php`
- Delete: `backend/resources/views/audit/verified.blade.php`
- Test: `backend/tests/Feature/Http/Controllers/AuditRequestStatusTest.php`

**Interfaces:**
- Consumes: `AuditReportService::signedUrl(AuditReport): string` (existing).
- Produces: `AuditRequestService::statusUrl(AuditRequest): string` (non-expiring signed URL, route `audit-requests.status`); JSON endpoint `audit-requests.status.json` returning `{status, label, done, failed, report_url}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Tests\Feature\FeatureTest;

class AuditRequestStatusTest extends FeatureTest
{
    public function test_status_page_renders_current_label(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee(__('Analyzing your repository'));
    }

    public function test_status_json_includes_signed_report_url_when_sent(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::SENT->value]);
        AuditReport::factory()->locked()->create(['audit_request_id' => $request->id]);

        $json = $this->getJson(route('audit-requests.status.json', [
            'auditRequest' => $request->uuid,
            ...$this->signedQuery($request),
        ]));

        $json->assertOk()
            ->assertJsonPath('done', true)
            ->assertJsonPath('failed', false);
        $this->assertStringContainsString('/reports/', $json->json('report_url'));
    }

    public function test_status_json_flags_failure(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::FAILED->value]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('failed', true)
            ->assertJsonPath('report_url', null);
    }

    public function test_unsigned_status_request_is_rejected(): void
    {
        $this->withExceptionHandling();
        $request = AuditRequest::factory()->verified()->create();

        $this->get('/audit-requests/'.$request->uuid.'/status')->assertForbidden();
    }

    private function signedJsonUrl(AuditRequest $request): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute('audit-requests.status.json', ['auditRequest' => $request->uuid]);
    }

    private function signedQuery(AuditRequest $request): array
    {
        parse_str((string) parse_url($this->signedJsonUrl($request), PHP_URL_QUERY), $query);

        return $query;
    }
}
```

(Simplify: use `signedJsonUrl()` directly in the second test too — `$this->getJson($this->signedJsonUrl($request))` — and drop `signedQuery`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditRequestStatusTest`
Expected: FAIL — route `audit-requests.status` not defined.

- [ ] **Step 3: Implement routes, controller, service, view, emails**

`routes/web.php` (after the verify route):

```php
Route::get('/audit-requests/{auditRequest:uuid}/status', [AuditRequestController::class, 'status'])
    ->name('audit-requests.status')
    ->middleware('signed');

Route::get('/audit-requests/{auditRequest:uuid}/status.json', [AuditRequestController::class, 'statusJson'])
    ->name('audit-requests.status.json')
    ->middleware('signed');
```

`AuditRequestService::statusUrl` (permanent signature — the page must outlive the 48h verify link):

```php
public function statusUrl(AuditRequest $auditRequest): string
{
    return URL::signedRoute('audit-requests.status', ['auditRequest' => $auditRequest->uuid]);
}
```

`AuditRequestController` — change `verify` to redirect to the status page, and add the endpoints:

```php
public function verify(AuditRequest $auditRequest, AuditFunnelRecorder $funnel, AuditRequestService $auditRequestService)
{
    if ($auditRequest->email_verified_at === null) {
        $auditRequest->update(['email_verified_at' => now()]);
        $funnel->record(AuditFunnelRecorder::STAGE_VERIFIED, $auditRequest);
        RouteVerifiedAuditRequest::dispatch($auditRequest);
    }

    return redirect($auditRequestService->statusUrl($auditRequest));
}

public function status(AuditRequest $auditRequest)
{
    return view('audit.status', [
        'auditRequest' => $auditRequest,
        'label' => $this->label($auditRequest->status),
        'pollUrl' => URL::signedRoute('audit-requests.status.json', ['auditRequest' => $auditRequest->uuid]),
    ]);
}

public function statusJson(AuditRequest $auditRequest, AuditReportService $reportService): JsonResponse
{
    $report = $auditRequest->report;
    $ready = $report !== null && in_array($auditRequest->status, [
        AuditRequestStatus::REPORT_READY->value,
        AuditRequestStatus::SENT->value,
    ], true);

    return response()->json([
        'status' => $auditRequest->status,
        'label' => $this->label($auditRequest->status),
        'done' => $ready,
        'failed' => $auditRequest->status === AuditRequestStatus::FAILED->value,
        'report_url' => $ready ? $reportService->signedUrl($report) : null,
    ]);
}

private function label(string $status): string
{
    return match ($status) {
        'pending_verification' => __('Waiting for you to confirm your email'),
        'new' => __('Request received'),
        'queued' => __('Queued for analysis'),
        'analyzing' => __('Analyzing your repository'),
        'report_ready', 'sent' => __('Your report is ready'),
        'failed' => __('The analysis hit a snag — an engineer is taking a look'),
        'needs_followup', 'awaiting_access' => __('We need access to your repository — check your email'),
        'awaiting_payment' => __('Your free audits are used up — check your email for options'),
        default => __('Processing'),
    };
}
```

Imports to add: `App\Constants\AuditRequestStatus`, `App\Services\AuditReport\AuditReportService`, `Illuminate\Support\Facades\URL`.

`resources/views/audit/status.blade.php` (replaces `verified.blade.php` — same visual shell):

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Audit status') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 40px; max-width: 460px; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #57534e; line-height: 1.5; margin: 0; }
        .spinner { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #d4a853; margin-right: 8px; animation: pulse 1.6s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.35; } 50% { opacity: 1; } }
        .btn { display: none; margin-top: 20px; background: #1c1917; color: #fafaf9; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; }
        .muted { font-size: 12px; color: #a8a29e; margin-top: 18px; }
    </style>
</head>
<body data-poll-url="{{ $pollUrl }}">
    <div class="card">
        <h1>{{ __('Your codebase audit') }}</h1>
        <p><span class="spinner" id="spinner"></span><span id="status-label">{{ $label }}</span></p>
        <a class="btn" id="report-link" href="#">{{ __('Open my report →') }}</a>
        <p class="muted">{{ __('This page updates automatically. We also email you at every step — safe to close.') }}</p>
    </div>
    <script>
        (function () {
            var pollUrl = document.body.dataset.pollUrl;
            var label = document.getElementById('status-label');
            var link = document.getElementById('report-link');
            var spinner = document.getElementById('spinner');

            function poll() {
                fetch(pollUrl, { headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (!data) return setTimeout(poll, 10000);
                        label.textContent = data.label;
                        if (data.report_url) {
                            link.href = data.report_url;
                            link.style.display = 'inline-block';
                            spinner.style.display = 'none';
                            return;
                        }
                        if (data.failed) { spinner.style.display = 'none'; return; }
                        setTimeout(poll, 5000);
                    })
                    .catch(function () { setTimeout(poll, 10000); });
            }

            setTimeout(poll, 5000);
        })();
    </script>
</body>
</html>
```

Delete `resources/views/audit/verified.blade.php` (no longer referenced).

`AuditRequestReceived` mailable — add `public string $statusUrl` as a second constructor property. In `AuditRequestService::routeVerified()` success branch change the mail line to:

```php
Mail::to($auditRequest->email)->send(new AuditRequestReceived($auditRequest, $this->statusUrl($auditRequest)));
```

`received.blade.php` — add before the closing `</td>`:

```blade
<p style="margin: 24px 0 0; line-height: 24px">
    <a href="{{ $statusUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Track your audit\'s progress live') }}</a>
</p>
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="AuditRequestStatusTest|AuditRequestRoutingTest"`
Expected: PASS — routing tests may need their `verified.blade` view assertion changed to a redirect assertion (`assertRedirect`); update those assertions.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add routes app/Http app/Services app/Mail resources/views tests
git commit -m "feat(backend): live audit status page with polling and email deep links"
```

---

### Task 7: Pipeline retry policy

**Files:**
- Modify: `backend/app/Jobs/GenerateAuditReport.php`
- Test: `backend/tests/Feature/Services/AuditPipelineTest.php` (add one test)

**Interfaces:**
- Produces: `GenerateAuditReport::$tries = 3`, `$backoff = [60, 300]`. `failed()` (→ `markFailed` → failure email) now only fires after the third attempt.

- [ ] **Step 1: Write the failing test** (append to `AuditPipelineTest`)

```php
public function test_report_job_retries_transient_failures_before_giving_up(): void
{
    $job = new \App\Jobs\GenerateAuditReport(\App\Models\AuditRequest::factory()->create());

    $this->assertSame(3, $job->tries);
    $this->assertSame([60, 300], $job->backoff);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_report_job_retries_transient_failures`
Expected: FAIL — `tries` is 1, no `backoff` property.

- [ ] **Step 3: Implement**

In `GenerateAuditReport`, replace `public int $tries = 1;` with:

```php
public int $tries = 3;

/** @var array<int, int> */
public array $backoff = [60, 300];
```

`AuditNotAnalyzableException` is caught inside `AuditPipeline::run` (no throw), so hopeless repos still fail fast without retries; only transient errors (clone hiccups, `AiAnalysisException`, timeouts) retry.

- [ ] **Step 4: Verify queue timeout headroom**

Run: `grep -n "retry_after" config/queue.php config/horizon.php`
The `redis-audit` connection's `retry_after` must exceed the job `timeout` (900). If it is ≤ 900, raise it to `960` in the same style the file already uses. If it's already > 900, change nothing.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test --compact --filter=AuditPipelineTest`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add app/Jobs config tests
git commit -m "feat(backend): retry audit report generation twice with backoff before failing"
```

---

### Task 8: Verification reminder email

**Files:**
- Create: `backend/app/Mail/Audit/AuditVerifyReminderEmail.php`
- Create: `backend/resources/views/emails/audit/verify-reminder.blade.php`
- Create: `backend/app/Console/Commands/SendAuditVerificationReminders.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/SendAuditVerificationRemindersTest.php`

**Interfaces:**
- Consumes: `AuditRequestService::verificationUrl(AuditRequest): string` (existing — issues a **fresh** 48h signed link, so the reminder always carries a working URL).
- Produces: command `app:send-audit-verification-reminders`, scheduled daily at 09:00. Reminder marker: `meta->verification_reminder_sent_at` (ISO string) on the request.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditVerifyReminderEmail;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class SendAuditVerificationRemindersTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function pendingRequest(array $attributes = []): AuditRequest
    {
        return AuditRequest::factory()->create(array_merge([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'email_verified_at' => null,
        ], $attributes));
    }

    public function test_reminds_day_old_unverified_requests_exactly_once(): void
    {
        $stale = $this->pendingRequest(['email' => 'remind-me@example.com', 'created_at' => now()->subHours(30)]);

        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();
        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();

        Mail::assertQueued(AuditVerifyReminderEmail::class, 1);
        $this->assertNotNull($stale->refresh()->meta['verification_reminder_sent_at'] ?? null);
    }

    public function test_ignores_fresh_verified_and_expired_requests(): void
    {
        $this->pendingRequest(['email' => 'too-fresh@example.com', 'created_at' => now()->subHours(2)]);
        $this->pendingRequest(['email' => 'too-old@example.com', 'created_at' => now()->subHours(72)]);
        AuditRequest::factory()->verified()->create(['email' => 'already-verified@example.com', 'created_at' => now()->subHours(30)]);

        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();

        Mail::assertNotQueued(AuditVerifyReminderEmail::class);
    }
}
```

(If `AuditRequestFactory` has no explicit unverified default, check it — the base factory state must leave `email_verified_at` null and status `pending_verification` for `pendingRequest()` to be honest; override attributes as shown regardless.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SendAuditVerificationRemindersTest`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement mailable, view, command, schedule**

`backend/app/Mail/Audit/AuditVerifyReminderEmail.php` (mirror `AuditQuotaExhausted`'s shape):

```php
<?php

namespace App\Mail\Audit;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditVerifyReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditRequest $auditRequest,
        public string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Still want your free codebase audit?'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.verify-reminder',
        );
    }
}
```

`backend/resources/views/emails/audit/verify-reminder.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Still want your free codebase audit?') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You asked for a free codebase audit yesterday but haven\'t confirmed your email yet — the audit only starts after that click.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $verifyUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Confirm my email and start the audit') }}</a>
            </p>
            <p style="margin: 16px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                {{ __('This link is valid for 48 hours. If you didn\'t request an audit, you can ignore this email.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
```

`backend/app/Console/Commands/SendAuditVerificationReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditVerifyReminderEmail;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAuditVerificationReminders extends Command
{
    protected $signature = 'app:send-audit-verification-reminders';

    protected $description = 'Remind unverified audit requesters before their verification window closes';

    public function handle(AuditRequestService $auditRequestService): int
    {
        $pending = AuditRequest::query()
            ->where('status', AuditRequestStatus::PENDING_VERIFICATION->value)
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', now()->subHours(24))
            ->where('created_at', '>', now()->subHours(48))
            ->whereNull('meta->verification_reminder_sent_at')
            ->get();

        foreach ($pending as $auditRequest) {
            Mail::to($auditRequest->email)->send(
                new AuditVerifyReminderEmail($auditRequest, $auditRequestService->verificationUrl($auditRequest))
            );
            $auditRequest->update([
                'meta' => array_merge($auditRequest->meta ?? [], ['verification_reminder_sent_at' => now()->toIso8601String()]),
            ]);
        }

        $this->info("Sent {$pending->count()} verification reminders.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add:

```php
Schedule::command('app:send-audit-verification-reminders')->dailyAt('09:00');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=SendAuditVerificationRemindersTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Mail app/Console resources/views routes/console.php tests
git commit -m "feat(backend): 24h verification reminder for unconfirmed audit requests"
```

---

### Task 9: Abandoned-unlock reminder email

**Files:**
- Create: `backend/app/Mail/Audit/AuditUnlockReminder.php`
- Create: `backend/resources/views/emails/audit/unlock-reminder.blade.php`
- Create: `backend/app/Console/Commands/SendAuditUnlockReminders.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/SendAuditUnlockRemindersTest.php`

**Interfaces:**
- Consumes: `HandleAuditUnlockOrder::INTENT_PARAM` (the `audit_unlock_intent` UserParameter written when a visitor clicks "Unlock for $5" — it lingers when checkout is abandoned, because the listener only deletes it on a paid order); signed `reports.unlock` route (Task 4).
- Produces: command `app:send-audit-unlock-reminders` (daily 09:05); one-shot marker `UserParameter` named `audit_unlock_reminder_sent` (value = report uuid), constant `SendAuditUnlockReminders::REMINDER_PARAM`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SendAuditUnlockReminders;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Mail\Audit\AuditUnlockReminder;
use App\Models\AuditReport;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class SendAuditUnlockRemindersTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function abandonedIntent(User $user, AuditReport $report, int $hoursAgo = 30): UserParameter
    {
        $intent = UserParameter::create([
            'user_id' => $user->id,
            'name' => HandleAuditUnlockOrder::INTENT_PARAM,
            'value' => $report->uuid,
        ]);
        $intent->timestamps = false;
        $intent->forceFill(['updated_at' => now()->subHours($hoursAgo)])->save();

        return $intent;
    }

    public function test_reminds_abandoned_unlock_exactly_once(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $this->abandonedIntent($user, $report);

        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();
        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();

        Mail::assertQueued(AuditUnlockReminder::class, 1);
        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'name' => SendAuditUnlockReminders::REMINDER_PARAM,
            'value' => $report->uuid,
        ]);
    }

    public function test_skips_fresh_intents_and_unlocked_reports(): void
    {
        $user = User::factory()->create();
        $fresh = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $this->abandonedIntent($user, $fresh, hoursAgo: 2);

        $other = User::factory()->create();
        $unlocked = AuditReport::factory()->create(['user_id' => $other->id, 'unlocked_at' => now()]);
        $staleIntent = $this->abandonedIntent($other, $unlocked);

        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();

        Mail::assertNotQueued(AuditUnlockReminder::class);
        $this->assertDatabaseMissing('user_parameters', ['id' => $staleIntent->id]); // stale intent cleaned up
    }
}
```

(If `AuditReport::factory()` has no default `unlocked_at`, the `->create(['unlocked_at' => now()])` override in the second test is what matters; the `locked()` state exists per `AuditReportUnlockTest`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SendAuditUnlockRemindersTest`
Expected: FAIL — command/mailable not found.

- [ ] **Step 3: Implement**

`backend/app/Mail/Audit/AuditUnlockReminder.php`:

```php
<?php

namespace App\Mail\Audit;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditUnlockReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $unlockUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your full codebase report is one click away'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.unlock-reminder',
        );
    }
}
```

`backend/resources/views/emails/audit/unlock-reminder.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your full codebase report is one click away') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $report->auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You started unlocking the full audit report for :repo but didn\'t finish checkout.', ['repo' => $report->auditRequest->repo_url]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('The full report includes the evidence behind every risk, a prioritized fix-first plan, and a PDF export — for $5.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $unlockUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Finish unlocking my report') }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
```

`backend/app/Console/Commands/SendAuditUnlockReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Mail\Audit\AuditUnlockReminder;
use App\Models\AuditReport;
use App\Models\UserParameter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendAuditUnlockReminders extends Command
{
    public const REMINDER_PARAM = 'audit_unlock_reminder_sent';

    protected $signature = 'app:send-audit-unlock-reminders';

    protected $description = 'Remind users who started a $5 report unlock but abandoned checkout';

    public function handle(): int
    {
        $sent = 0;

        $intents = UserParameter::query()
            ->where('name', HandleAuditUnlockOrder::INTENT_PARAM)
            ->where('updated_at', '<=', now()->subDay())
            ->get();

        foreach ($intents as $intent) {
            $report = AuditReport::query()->where('uuid', $intent->value)->first();

            if ($report === null || $report->unlocked_at !== null) {
                $intent->delete();

                continue;
            }

            $alreadyReminded = UserParameter::query()
                ->where('user_id', $intent->user_id)
                ->where('name', self::REMINDER_PARAM)
                ->where('value', $report->uuid)
                ->exists();

            if ($alreadyReminded) {
                continue;
            }

            $unlockUrl = URL::temporarySignedRoute('reports.unlock', now()->addDays(7), ['auditReport' => $report->uuid]);
            Mail::to($report->auditRequest->email)->send(new AuditUnlockReminder($report, $unlockUrl));

            UserParameter::create([
                'user_id' => $intent->user_id,
                'name' => self::REMINDER_PARAM,
                'value' => $report->uuid,
            ]);
            $sent++;
        }

        $this->info("Sent {$sent} unlock reminders.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add:

```php
Schedule::command('app:send-audit-unlock-reminders')->dailyAt('09:05');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=SendAuditUnlockRemindersTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Mail app/Console resources/views routes/console.php tests
git commit -m "feat(backend): abandoned-checkout reminder for \$5 report unlocks"
```

---

### Task 10: Dashboard nav visible for entitled subscribers

**Files:**
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php:30-33` (`shouldRegisterNavigation`)
- Test: `backend/tests/Feature/Filament/AuditReportsPageTest.php` (add one test; file exists — match its setup helpers)

**Interfaces:**
- Consumes: `AuditEntitlementService::subscriptionAllowance(Tenant): int` (existing).

Currently the nav item is hidden until the user *has* a report — a fresh subscriber can't find the page to run their first audit. Show it when the tenant has any subscription allowance.

- [ ] **Step 1: Write the failing test** (append to the existing `AuditReportsPageTest`, matching its tenant/`Filament::setTenant` setup style)

```php
public function test_navigation_registers_for_subscribed_tenant_without_reports(): void
{
    $tenant = \App\Models\Tenant::factory()->create();
    $user = $this->createUser($tenant);
    $this->actingAs($user);
    \Filament\Facades\Filament::setTenant($tenant);

    $this->mock(\App\Services\AuditReport\AuditEntitlementService::class, function ($mock) {
        $mock->shouldReceive('subscriptionAllowance')->andReturn(5);
    });

    $this->assertTrue(\App\Filament\Dashboard\Pages\AuditReports::shouldRegisterNavigation());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_navigation_registers_for_subscribed_tenant`
Expected: FAIL — returns false (no reports exist).

- [ ] **Step 3: Implement**

Replace `shouldRegisterNavigation()`:

```php
public static function shouldRegisterNavigation(): bool
{
    if (! auth()->check()) {
        return false;
    }

    if (auth()->user()->auditReports()->exists()) {
        return true;
    }

    $tenant = Filament::getTenant();

    return $tenant !== null && app(AuditEntitlementService::class)->subscriptionAllowance($tenant) > 0;
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=AuditReportsPageTest`
Expected: PASS (all, including pre-existing tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament tests
git commit -m "fix(backend): show audit nav to entitled subscribers before their first report"
```

---

### Task 11: "Repository facts" section in the report (web + PDF + sample)

**Files:**
- Modify: `backend/resources/views/reports/audit-web.blade.php` (new card after "Health scores")
- Modify: `backend/resources/views/reports/audit.blade.php` (PDF — new facts table)
- Modify: `backend/resources/data/sample-audit-report.json` (add `metrics`)
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (`sample()` passes metrics)
- Test: `backend/tests/Feature/Http/Controllers/AuditReportFactsTest.php`

**Interfaces:**
- Consumes: `audit_requests.metrics` JSON written by `MetricsCollector` (keys: `files_total`, `loc_total`, `languages`, `largest_files`, `duplication_pct`, `test_files`, `test_ratio_pct`, `has_ci`, `has_readme`, `manifests`, `secret_findings`, `git`).
- Produces: a **free** (never locked) facts card. Every row is `??`-guarded so reports generated before this task (or before Tasks 12/14/16 add keys) still render. Tasks 12, 14, 16 append rows to this card.

These numbers are already measured on every audit and shown nowhere. Real, hard numbers in the free teaser are the cheapest credibility upgrade the report can get.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportFactsTest extends FeatureTest
{
    private function metrics(): array
    {
        return [
            'files_total' => 412,
            'loc_total' => 68450,
            'languages' => ['ts' => ['files' => 210, 'loc' => 41200], 'php' => ['files' => 88, 'loc' => 15300]],
            'largest_files' => [['path' => 'src/services/PaymentService.ts', 'loc' => 2412]],
            'duplication_pct' => 26.4,
            'test_files' => 9,
            'test_ratio_pct' => 2.2,
            'has_ci' => false,
            'has_readme' => true,
            'manifests' => ['package.json' => ['dependencies' => 64, 'dev_dependencies' => 21, 'lockfile' => true]],
            'secret_findings' => ['generic_api_key' => ['count' => 3, 'files' => ['src/config.ts']]],
            'git' => ['default_branch' => 'main', 'last_commit_at' => '2026-06-28T14:12:00+00:00'],
        ];
    }

    public function test_report_page_shows_repository_facts(): void
    {
        $request = AuditRequest::factory()->verified()->create(['metrics' => $this->metrics()]);
        $report = AuditReport::factory()->locked()->create(['audit_request_id' => $request->id]);

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertSee(__('Repository facts'))
            ->assertSee('68,450')
            ->assertSee('src/services/PaymentService.ts')
            ->assertSee('26.4%');
    }

    public function test_report_without_metrics_still_renders(): void
    {
        $request = AuditRequest::factory()->verified()->create(['metrics' => null]);
        $report = AuditReport::factory()->locked()->create(['audit_request_id' => $request->id]);

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertDontSee(__('Repository facts'));
    }

    public function test_sample_report_shows_repository_facts(): void
    {
        $this->get(route('reports.sample'))
            ->assertOk()
            ->assertSee(__('Repository facts'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditReportFactsTest`
Expected: FAIL — "Repository facts" not on the page.

- [ ] **Step 3: Implement the web card**

In `audit-web.blade.php`, insert directly **after** the closing `</div>` of the "Health scores" card (line 74):

```blade
    @php($metrics = $report->auditRequest->metrics)
    @if (is_array($metrics) && $metrics !== [])
        <div class="card">
            <h2>{{ __('Repository facts') }}</h2>
            <div class="scores-grid">
                <div class="score-tile"><div class="value">{{ number_format($metrics['files_total'] ?? 0) }}</div><div class="label">{{ __('source files') }}</div></div>
                <div class="score-tile"><div class="value">{{ number_format($metrics['loc_total'] ?? 0) }}</div><div class="label">{{ __('lines of code') }}</div></div>
                <div class="score-tile"><div class="value">{{ $metrics['duplication_pct'] ?? 0 }}%</div><div class="label">{{ __('duplicated lines') }}</div></div>
                <div class="score-tile"><div class="value">{{ $metrics['test_ratio_pct'] ?? 0 }}%</div><div class="label">{{ __('test file ratio') }}</div></div>
                <div class="score-tile"><div class="value">{{ ($metrics['has_ci'] ?? false) ? __('yes') : __('no') }}</div><div class="label">{{ __('CI configured') }}</div></div>
                <div class="score-tile"><div class="value">{{ array_sum(array_column($metrics['secret_findings'] ?? [], 'count')) }}</div><div class="label">{{ __('potential secrets') }}</div></div>
            </div>
            @php($langs = collect($metrics['languages'] ?? [])->sortByDesc('loc')->take(5))
            @if ($langs->isNotEmpty())
                <p class="muted" style="margin-top: 14px;">
                    {{ __('Languages') }}:
                    {{ $langs->map(fn ($stats, $ext) => strtoupper($ext).' '.number_format($stats['loc']).' loc')->implode(' · ') }}
                </p>
            @endif
            @php($largest = array_slice($metrics['largest_files'] ?? [], 0, 5))
            @if ($largest !== [])
                <table style="margin-top: 12px;">
                    <tr><th>{{ __('Largest files') }}</th><th>{{ __('Lines') }}</th></tr>
                    @foreach ($largest as $file)
                        <tr><td>{{ $file['path'] }}</td><td>{{ number_format($file['loc']) }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if (($metrics['git']['last_commit_at'] ?? null) !== null)
                <p class="muted" style="margin-top: 12px;">
                    {{ __('Last commit') }}: {{ \Illuminate\Support\Carbon::parse($metrics['git']['last_commit_at'])->format('Y-m-d') }}
                </p>
            @endif
        </div>
    @endif
```

- [ ] **Step 4: Implement the PDF table**

In `resources/views/reports/audit.blade.php`, add after the health-scores table (open the file first; it is a table-per-section DomPDF layout — mirror its section markup):

```blade
@php($metrics = $report->auditRequest->metrics)
@if (is_array($metrics) && $metrics !== [])
    <h2>{{ __('Repository facts') }}</h2>
    <table>
        <tr><th>{{ __('Fact') }}</th><th>{{ __('Value') }}</th></tr>
        <tr><td>{{ __('Source files') }}</td><td>{{ number_format($metrics['files_total'] ?? 0) }}</td></tr>
        <tr><td>{{ __('Lines of code') }}</td><td>{{ number_format($metrics['loc_total'] ?? 0) }}</td></tr>
        <tr><td>{{ __('Duplicated lines') }}</td><td>{{ $metrics['duplication_pct'] ?? 0 }}%</td></tr>
        <tr><td>{{ __('Test file ratio') }}</td><td>{{ $metrics['test_ratio_pct'] ?? 0 }}%</td></tr>
        <tr><td>{{ __('CI configured') }}</td><td>{{ ($metrics['has_ci'] ?? false) ? __('yes') : __('no') }}</td></tr>
        <tr><td>{{ __('Potential secrets') }}</td><td>{{ array_sum(array_column($metrics['secret_findings'] ?? [], 'count')) }}</td></tr>
    </table>
@endif
```

- [ ] **Step 5: Add metrics to the sample fixture and controller**

In `resources/data/sample-audit-report.json`, add a top-level `"metrics"` key (sibling of `repo_url`/`percentile`/`payload`) — values must stay consistent with the sample's narrative (low testing, duplication, committed secrets):

```json
"metrics": {
    "files_total": 412,
    "loc_total": 68450,
    "languages": {
        "ts": {"files": 210, "loc": 41200},
        "tsx": {"files": 88, "loc": 15300},
        "js": {"files": 54, "loc": 6900},
        "css": {"files": 25, "loc": 3050},
        "json": {"files": 35, "loc": 2000}
    },
    "largest_files": [
        {"path": "src/services/PaymentService.ts", "loc": 2412},
        {"path": "src/screens/Checkout.tsx", "loc": 1876},
        {"path": "src/api/handlers.ts", "loc": 1544},
        {"path": "src/state/store.ts", "loc": 1102},
        {"path": "src/utils/helpers.ts", "loc": 987}
    ],
    "duplication_pct": 26.4,
    "test_files": 9,
    "test_ratio_pct": 2.2,
    "has_ci": false,
    "has_readme": true,
    "manifests": {"package.json": {"dependencies": 64, "dev_dependencies": 21, "lockfile": true}},
    "secret_findings": {"generic_api_key": {"count": 3, "files": ["src/config.ts", ".env.production"]}},
    "git": {"default_branch": "main", "last_commit_at": "2026-06-28T14:12:00+00:00"}
}
```

In `AuditReportController::sample()`, change the request construction to:

```php
$request = new AuditRequest(['repo_url' => $fixture['repo_url'], 'metrics' => $fixture['metrics'] ?? null]);
```

- [ ] **Step 6: Run tests and commit**

Run: `php artisan test --compact --filter="AuditReportFactsTest|AuditReportControllerTest|Audit"`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add resources app/Http tests
git commit -m "feat(backend): surface measured repository facts in web, PDF and sample reports"
```

---

### Task 12: Dependency vulnerability audit via OSV.dev

**Files:**
- Create: `backend/app/Services/AuditReport/DependencyAuditor.php`
- Modify: `backend/app/Services/AuditReport/MetricsCollector.php` (constructor + `dependency_audit` key)
- Modify: `backend/config/audit.php` (`osv_endpoint`)
- Modify: `backend/resources/views/reports/audit-web.blade.php` (facts card row)
- Test: `backend/tests/Feature/Services/DependencyAuditorTest.php`

**Interfaces:**
- Produces: `DependencyAuditor::audit(string $repoPath): array` returning `['packages_scanned' => int, 'vulnerable_count' => int, 'vulnerabilities' => [['package','version','ecosystem','vulns' => [ids]]]]`, plus `'error' => 'osv_unreachable'` when OSV is down (pipeline must never fail because of OSV). Stored under `metrics['dependency_audit']`. Task 15's `ScoreCalculator` consumes `vulnerable_count`/`error`.
- Consumes: lockfiles inside the cloned repo (`composer.lock`, `package-lock.json`); OSV batch API `POST https://api.osv.dev/v1/querybatch` (results array is index-aligned with the queries array; each result may contain `vulns: [{id, ...}]`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\DependencyAuditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class DependencyAuditorTest extends FeatureTest
{
    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->repoPath = storage_path('framework/testing/dep-audit-'.uniqid());
        File::ensureDirectoryExists($this->repoPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    private function writeLockfiles(): void
    {
        File::put($this->repoPath.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/http', 'version' => 'v1.2.3']],
            'packages-dev' => [['name' => 'acme/testkit', 'version' => '2.0.0']],
        ]));
        File::put($this->repoPath.'/package-lock.json', json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'root'],
                'node_modules/leftpad' => ['version' => '9.9.9'],
            ],
        ]));
    }

    public function test_flags_vulnerable_packages_from_osv(): void
    {
        $this->writeLockfiles();
        Http::fake([
            'api.osv.dev/*' => Http::response(['results' => [
                ['vulns' => [['id' => 'GHSA-xxxx-yyyy-zzzz']]],
                [],
                [],
            ]]),
        ]);

        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame(3, $result['packages_scanned']);
        $this->assertSame(1, $result['vulnerable_count']);
        $this->assertSame('acme/http', $result['vulnerabilities'][0]['package']);
        $this->assertSame('1.2.3', $result['vulnerabilities'][0]['version']); // leading "v" stripped
        $this->assertSame(['GHSA-xxxx-yyyy-zzzz'], $result['vulnerabilities'][0]['vulns']);
    }

    public function test_returns_error_marker_when_osv_is_unreachable(): void
    {
        $this->writeLockfiles();
        Http::fake(['api.osv.dev/*' => Http::response(null, 500)]);

        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame('osv_unreachable', $result['error']);
        $this->assertSame(3, $result['packages_scanned']);
        $this->assertSame(0, $result['vulnerable_count']);
    }

    public function test_repo_without_lockfiles_makes_no_http_calls(): void
    {
        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame(['packages_scanned' => 0, 'vulnerable_count' => 0, 'vulnerabilities' => []], $result);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DependencyAuditorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`config/audit.php` — add: `'osv_endpoint' => 'https://api.osv.dev/v1/querybatch',`

`backend/app/Services/AuditReport/DependencyAuditor.php`:

```php
<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Facades\Http;
use Throwable;

class DependencyAuditor
{
    public function audit(string $repoPath): array
    {
        $packages = array_merge($this->composerPackages($repoPath), $this->npmPackages($repoPath));

        if ($packages === []) {
            return ['packages_scanned' => 0, 'vulnerable_count' => 0, 'vulnerabilities' => []];
        }

        try {
            $vulnerable = [];

            foreach (array_chunk($packages, 500) as $chunk) {
                $response = Http::timeout(15)->connectTimeout(5)->retry(2, 500)
                    ->post((string) config('audit.osv_endpoint'), [
                        'queries' => array_map(fn (array $package) => [
                            'package' => ['name' => $package['name'], 'ecosystem' => $package['ecosystem']],
                            'version' => $package['version'],
                        ], $chunk),
                    ])->throw();

                foreach ($response->json('results', []) as $i => $result) {
                    $vulnIds = array_column($result['vulns'] ?? [], 'id');
                    if ($vulnIds !== []) {
                        $vulnerable[] = [
                            'package' => $chunk[$i]['name'],
                            'version' => $chunk[$i]['version'],
                            'ecosystem' => $chunk[$i]['ecosystem'],
                            'vulns' => $vulnIds,
                        ];
                    }
                }
            }

            return [
                'packages_scanned' => count($packages),
                'vulnerable_count' => count($vulnerable),
                'vulnerabilities' => array_slice($vulnerable, 0, 25),
            ];
        } catch (Throwable) {
            return [
                'packages_scanned' => count($packages),
                'vulnerable_count' => 0,
                'vulnerabilities' => [],
                'error' => 'osv_unreachable',
            ];
        }
    }

    private function composerPackages(string $repoPath): array
    {
        $lock = $repoPath.'/composer.lock';
        if (! is_file($lock)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($lock), true) ?? [];
        $packages = [];

        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
            if (isset($package['name'], $package['version'])) {
                $packages[] = [
                    'name' => $package['name'],
                    'version' => ltrim((string) $package['version'], 'v'),
                    'ecosystem' => 'Packagist',
                ];
            }
        }

        return $packages;
    }

    private function npmPackages(string $repoPath): array
    {
        $lock = $repoPath.'/package-lock.json';
        if (! is_file($lock)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($lock), true) ?? [];
        $packages = [];

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $path => $info) {
                if ($path === '' || ! isset($info['version'])) {
                    continue;
                }
                $name = $info['name'] ?? (str_contains($path, 'node_modules/')
                    ? substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'))
                    : null);
                if ($name === null) {
                    continue;
                }
                $packages[] = ['name' => $name, 'version' => $info['version'], 'ecosystem' => 'npm'];
            }

            return $packages;
        }

        foreach ($data['dependencies'] ?? [] as $name => $info) {
            if (isset($info['version'])) {
                $packages[] = ['name' => $name, 'version' => $info['version'], 'ecosystem' => 'npm'];
            }
        }

        return $packages;
    }
}
```

`MetricsCollector` — add constructor and metrics key:

```php
public function __construct(
    private DependencyAuditor $dependencyAuditor,
) {}
```

and in the `$metrics = [...]` array, after `'manifests' => ...,` add:

```php
'dependency_audit' => $this->dependencyAuditor->audit($repoPath),
```

`audit-web.blade.php` facts card — add a tile inside the `scores-grid` div (after the "potential secrets" tile):

```blade
@if (isset($metrics['dependency_audit']) && ! isset($metrics['dependency_audit']['error']))
    <div class="score-tile"><div class="value">{{ $metrics['dependency_audit']['vulnerable_count'] }}</div><div class="label">{{ __('vulnerable dependencies') }}</div></div>
@endif
```

- [ ] **Step 4: Guard existing MetricsCollector tests**

Run: `php artisan test --compact --filter=MetricsCollectorTest`
If any fixture repo in that test contains a lockfile, add `Http::fake(['api.osv.dev/*' => Http::response(['results' => []])]);` to its `setUp()`. Otherwise no change (no lockfile → no HTTP call).

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test --compact --filter="DependencyAuditorTest|MetricsCollectorTest"`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add app/Services config resources/views tests
git commit -m "feat(backend): real dependency vulnerability audit via OSV.dev batch API"
```

---

### Task 13: Expanded secret-detection patterns

**Files:**
- Modify: `backend/app/Services/AuditReport/MetricsCollector.php:15-19` (`SECRET_PATTERNS`)
- Test: `backend/tests/Feature/Services/MetricsCollectorTest.php` (add one test)

**Interfaces:**
- Produces: additional keys in `metrics['secret_findings']` (same `{count, files[]}` shape). No consumer changes needed — the facts card and `ScoreCalculator` already sum all findings.

Note: the clone is `--depth 1`, so this scans HEAD only (no history scanning) — that stays true; don't promise otherwise in copy.

- [ ] **Step 1: Write the failing test** (append to `MetricsCollectorTest`, using its existing temp-repo helper if present; otherwise create files with `File::put` in a scratch dir like `DependencyAuditorTest` does)

```php
public function test_detects_modern_provider_token_formats(): void
{
    $path = storage_path('framework/testing/secrets-'.uniqid());
    \Illuminate\Support\Facades\File::ensureDirectoryExists($path);
    \Illuminate\Support\Facades\File::put($path.'/config.js', implode("\n", [
        'const gh = "ghp_'.str_repeat('a', 36).'";',
        'const stripe = "sk_live_'.str_repeat('b', 24).'";',
        'const anthropic = "sk-ant-'.str_repeat('c', 40).'";',
        'const db = "postgres://admin:hunter2@db.internal:5432/app";',
        'const slack = "xoxb-1234567890-abcdefghij";',
    ]));

    $metrics = app(\App\Services\AuditReport\MetricsCollector::class)->collect($path)['metrics'];
    \Illuminate\Support\Facades\File::deleteDirectory($path);

    $findings = $metrics['secret_findings'];
    $this->assertArrayHasKey('github_token', $findings);
    $this->assertArrayHasKey('stripe_live_key', $findings);
    $this->assertArrayHasKey('anthropic_key', $findings);
    $this->assertArrayHasKey('credentialed_url', $findings);
    $this->assertArrayHasKey('slack_token', $findings);
}
```

(If `MetricsCollectorTest` already fakes `git`/process calls in setUp, keep that arrangement — `collect()` runs `git` commands that return empty output in a non-repo dir, which is fine.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_detects_modern_provider_token_formats`
Expected: FAIL — keys missing.

- [ ] **Step 3: Implement**

Replace the `SECRET_PATTERNS` const with:

```php
private const SECRET_PATTERNS = [
    'aws_access_key' => '/AKIA[0-9A-Z]{16}/',
    'private_key_block' => '/-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    'generic_api_key' => '/(api[_-]?key|secret[_-]?key|access[_-]?token)["\']?\s*[:=>]+\s*["\'][A-Za-z0-9_\-]{16,}["\']/i',
    'github_token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36,}\b/',
    'github_fine_grained_pat' => '/\bgithub_pat_[A-Za-z0-9_]{60,}\b/',
    'gitlab_pat' => '/\bglpat-[A-Za-z0-9\-_]{20,}\b/',
    'slack_token' => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
    'stripe_live_key' => '/\b(?:sk|rk)_live_[A-Za-z0-9]{20,}\b/',
    'sendgrid_key' => '/\bSG\.[A-Za-z0-9_\-]{22}\.[A-Za-z0-9_\-]{43}\b/',
    'google_api_key' => '/\bAIza[0-9A-Za-z\-_]{35}\b/',
    'openai_key' => '/\bsk-[A-Za-z0-9]{20}T3BlbkFJ[A-Za-z0-9]{20}\b/',
    'anthropic_key' => '/\bsk-ant-[A-Za-z0-9\-_]{32,}\b/',
    'npm_token' => '/\bnpm_[A-Za-z0-9]{36}\b/',
    'twilio_api_key' => '/\bSK[0-9a-f]{32}\b/',
    'credentialed_url' => '#\b[a-z][a-z0-9+.\-]*://[^/\s:@"\']{1,64}:[^/\s:@"\']{1,64}@#i',
];
```

(`credentialed_url` catches `scheme://user:password@host` — the "database password in the repo" class of leak.)

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=MetricsCollectorTest`
Expected: PASS — including pre-existing pattern tests. If the repo's own test fixtures trip a new pattern (e.g. a URL with credentials in an unrelated fixture), fix the fixture, not the pattern.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services tests
git commit -m "feat(backend): detect 12 more secret formats incl. provider tokens and credentialed URLs"
```

---

### Task 14: Tooling detection metrics

**Files:**
- Modify: `backend/app/Services/AuditReport/MetricsCollector.php` (new `tooling()` method + metrics key)
- Modify: `backend/resources/views/reports/audit-web.blade.php` (facts card row)
- Test: `backend/tests/Feature/Services/MetricsCollectorTest.php` (add one test)

**Interfaces:**
- Produces: `metrics['tooling']` = `['error_monitoring' => bool, 'linter' => bool, 'static_analysis' => bool, 'formatter' => bool, 'env_example' => bool, 'dockerized' => bool]`. The sample report's "no error monitoring" risk finally has a computed backing signal (feed flows to the LLM automatically via the metrics JSON).

- [ ] **Step 1: Write the failing test** (append to `MetricsCollectorTest`)

```php
public function test_detects_engineering_tooling_from_manifests_and_files(): void
{
    $path = storage_path('framework/testing/tooling-'.uniqid());
    \Illuminate\Support\Facades\File::ensureDirectoryExists($path);
    \Illuminate\Support\Facades\File::put($path.'/package.json', json_encode([
        'dependencies' => ['@sentry/node' => '^8.0.0'],
        'devDependencies' => ['eslint' => '^9.0.0', 'prettier' => '^3.0.0', 'typescript' => '^5.0.0'],
    ]));
    \Illuminate\Support\Facades\File::put($path.'/.env.example', 'APP_KEY=');
    \Illuminate\Support\Facades\File::put($path.'/Dockerfile', 'FROM node:22');

    $metrics = app(\App\Services\AuditReport\MetricsCollector::class)->collect($path)['metrics'];
    \Illuminate\Support\Facades\File::deleteDirectory($path);

    $this->assertSame([
        'error_monitoring' => true,
        'linter' => true,
        'static_analysis' => true,
        'formatter' => true,
        'env_example' => true,
        'dockerized' => true,
    ], $metrics['tooling']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_detects_engineering_tooling`
Expected: FAIL — `tooling` key missing.

- [ ] **Step 3: Implement**

Add to `MetricsCollector` (private method) and register `'tooling' => $this->tooling($repoPath),` in the `$metrics` array after `'manifests'`:

```php
private function tooling(string $repoPath): array
{
    $deps = [];

    foreach (['composer.json', 'package.json'] as $manifest) {
        if (! file_exists($repoPath.'/'.$manifest)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($repoPath.'/'.$manifest), true) ?? [];
        $deps = array_merge(
            $deps,
            array_keys($data['require'] ?? []),
            array_keys($data['require-dev'] ?? []),
            array_keys($data['dependencies'] ?? []),
            array_keys($data['devDependencies'] ?? []),
        );
    }

    $has = fn (array $names): bool => array_intersect($names, $deps) !== [];

    return [
        'error_monitoring' => $has(['sentry/sentry', 'sentry/sentry-laravel', '@sentry/browser', '@sentry/node', '@sentry/react', '@sentry/nextjs', '@sentry/vue', 'bugsnag/bugsnag', 'bugsnag/bugsnag-laravel', '@bugsnag/js', 'rollbar/rollbar', 'rollbar', 'honeybadger-io/honeybadger-php', '@honeybadger-io/js']),
        'linter' => $has(['laravel/pint', 'friendsofphp/php-cs-fixer', 'squizlabs/php_codesniffer', 'eslint', '@biomejs/biome', 'oxlint']),
        'static_analysis' => $has(['phpstan/phpstan', 'larastan/larastan', 'vimeo/psalm', 'typescript']),
        'formatter' => $has(['prettier', 'laravel/pint', '@biomejs/biome']),
        'env_example' => file_exists($repoPath.'/.env.example'),
        'dockerized' => file_exists($repoPath.'/Dockerfile') || file_exists($repoPath.'/docker-compose.yml') || file_exists($repoPath.'/compose.yaml'),
    ];
}
```

`audit-web.blade.php` facts card — add after the languages paragraph:

```blade
@isset($metrics['tooling'])
    <p class="muted" style="margin-top: 8px;">
        {{ __('Engineering setup') }}:
        {{ __('error monitoring') }} {{ $metrics['tooling']['error_monitoring'] ? '✓' : '✗' }} ·
        {{ __('linter') }} {{ $metrics['tooling']['linter'] ? '✓' : '✗' }} ·
        {{ __('static analysis') }} {{ $metrics['tooling']['static_analysis'] ? '✓' : '✗' }} ·
        {{ __('.env.example') }} {{ $metrics['tooling']['env_example'] ? '✓' : '✗' }} ·
        {{ __('Docker') }} {{ $metrics['tooling']['dockerized'] ? '✓' : '✗' }}
    </p>
@endisset
```

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test --compact --filter=MetricsCollectorTest`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add app/Services resources/views tests
git commit -m "feat(backend): detect error monitoring, linters and dev tooling per repo"
```

---

### Task 15: Deterministic health scores

**Files:**
- Create: `backend/app/Services/AuditReport/ScoreCalculator.php`
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php` (compute → feed to LLM → override payload)
- Modify: `backend/app/Services/AuditReport/ClaudeAnalyzer.php` (`SYSTEM_PROMPT` addition)
- Test: `backend/tests/Feature/Services/ScoreCalculatorTest.php`
- Modify: `backend/tests/Feature/Services/AuditPipelineTest.php` (score-override assertion)

**Interfaces:**
- Produces: `ScoreCalculator::calculate(array $metrics): array` → `['structure' => int, 'duplication' => int, 'testing' => int, 'dependencies' => int, 'security_hygiene' => int, 'overall' => int]` (each 0–100). The pipeline stores these into `metrics['computed_scores']` **and** overwrites `payload['scores']` with them. Task 17's deltas and the dashboard sparklines become trustworthy because the same repo now scores the same.
- Consumes: metric keys from Tasks 12/13/14 where present (all `??`-guarded so pre-existing metrics still score).

**Formulas (rationale in one line each):**
- `duplication = clamp(100 − 2.5 × duplication_pct)` — 40% duplicated lines → 0.
- `testing = clamp(min(90, 4.5 × test_ratio_pct) + (has_ci ? 10 : 0))` — a 20% test-file ratio maxes the ratio component; CI adds the last 10.
- `structure = clamp(100 − max(0, avg_loc_per_file − 120) × 0.25 − 8 × files≥1000loc − 3 × files 500–999loc)` — penalizes bloated averages and god-files (counted from `largest_files`, top-20 sample).
- `dependencies = clamp(100 − 20 per missing lockfile − 8 × vulnerable_count)`; if the OSV scan errored, cap at 70 (unknown ≠ healthy).
- `security_hygiene = clamp(100 − 15 × total secret findings)`.
- `overall = round(0.25·structure + 0.20·duplication + 0.25·testing + 0.15·dependencies + 0.15·security_hygiene)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\ScoreCalculator;
use Tests\Feature\FeatureTest;

class ScoreCalculatorTest extends FeatureTest
{
    private function metrics(): array
    {
        return [
            'files_total' => 100,
            'loc_total' => 20000, // avg 200 → structure base 80
            'largest_files' => [
                ['path' => 'a.php', 'loc' => 1200], // −8
                ['path' => 'b.php', 'loc' => 800],  // −3
                ['path' => 'c.php', 'loc' => 600],  // −3
            ],
            'duplication_pct' => 20.0, // 100 − 50 = 50
            'test_ratio_pct' => 10.0,  // min(90, 45) = 45
            'has_ci' => true,          // +10 → 55
            'manifests' => ['composer.json' => ['dependencies' => 10, 'dev_dependencies' => 5, 'lockfile' => true]],
            'dependency_audit' => ['packages_scanned' => 40, 'vulnerable_count' => 2, 'vulnerabilities' => []], // 100 − 16 = 84
            'secret_findings' => ['github_token' => ['count' => 1, 'files' => ['x']]], // 100 − 15 = 85
        ];
    }

    public function test_calculates_exact_scores_from_metrics(): void
    {
        $scores = app(ScoreCalculator::class)->calculate($this->metrics());

        $this->assertSame([
            'structure' => 66,
            'duplication' => 50,
            'testing' => 55,
            'dependencies' => 84,
            'security_hygiene' => 85,
            'overall' => 66, // 16.5 + 10 + 13.75 + 12.6 + 12.75 = 65.6 → 66
        ], $scores);
    }

    public function test_is_deterministic_and_clamped(): void
    {
        $calculator = app(ScoreCalculator::class);
        $extreme = ['duplication_pct' => 90, 'test_ratio_pct' => 0, 'has_ci' => false,
            'files_total' => 1, 'loc_total' => 5000, 'largest_files' => [],
            'manifests' => ['package.json' => ['dependencies' => 1, 'dev_dependencies' => 0, 'lockfile' => false]],
            'dependency_audit' => ['vulnerable_count' => 30, 'error' => 'osv_unreachable'],
            'secret_findings' => ['aws_access_key' => ['count' => 12, 'files' => []]],
        ];

        $first = $calculator->calculate($extreme);

        $this->assertSame($first, $calculator->calculate($extreme));
        foreach ($first as $score) {
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
        $this->assertSame(0, $first['duplication']);
        $this->assertLessThanOrEqual(70, $first['dependencies']); // errored scan caps at 70
    }

    public function test_handles_missing_keys_gracefully(): void
    {
        $scores = app(ScoreCalculator::class)->calculate([]);

        $this->assertSame(100, $scores['duplication']);
        $this->assertSame(0, $scores['testing']);
        $this->assertSame(100, $scores['security_hygiene']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ScoreCalculatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`backend/app/Services/AuditReport/ScoreCalculator.php`:

```php
<?php

namespace App\Services\AuditReport;

class ScoreCalculator
{
    /**
     * Deterministic 0-100 health scores computed from measured metrics.
     * The LLM narrates; these numbers are authoritative so repeat runs
     * of the same repo score identically (trends/deltas depend on this).
     */
    public function calculate(array $metrics): array
    {
        $duplication = $this->clamp(100 - 2.5 * (float) ($metrics['duplication_pct'] ?? 0));

        $testing = $this->clamp(
            min(90, 4.5 * (float) ($metrics['test_ratio_pct'] ?? 0))
            + (($metrics['has_ci'] ?? false) ? 10 : 0)
        );

        $files = max(1, (int) ($metrics['files_total'] ?? 1));
        $avgLoc = ((int) ($metrics['loc_total'] ?? 0)) / $files;
        $huge = count(array_filter($metrics['largest_files'] ?? [], fn (array $f) => ($f['loc'] ?? 0) >= 1000));
        $big = count(array_filter($metrics['largest_files'] ?? [], fn (array $f) => ($f['loc'] ?? 0) >= 500 && ($f['loc'] ?? 0) < 1000));
        $structure = $this->clamp(100 - max(0, $avgLoc - 120) * 0.25 - 8 * $huge - 3 * $big);

        $dependencies = 100;
        foreach (($metrics['manifests'] ?? []) as $manifest) {
            if (! ($manifest['lockfile'] ?? false)) {
                $dependencies -= 20;
            }
        }
        $audit = $metrics['dependency_audit'] ?? null;
        if (is_array($audit) && isset($audit['error'])) {
            $dependencies = min($dependencies, 70);
        } elseif (is_array($audit)) {
            $dependencies -= 8 * (int) ($audit['vulnerable_count'] ?? 0);
        }
        $dependencies = $this->clamp($dependencies);

        $secretCount = array_sum(array_column($metrics['secret_findings'] ?? [], 'count'));
        $securityHygiene = $this->clamp(100 - 15 * $secretCount);

        $overall = (int) round(
            0.25 * $structure
            + 0.20 * $duplication
            + 0.25 * $testing
            + 0.15 * $dependencies
            + 0.15 * $securityHygiene
        );

        return [
            'structure' => $structure,
            'duplication' => $duplication,
            'testing' => $testing,
            'dependencies' => $dependencies,
            'security_hygiene' => $securityHygiene,
            'overall' => $overall,
        ];
    }

    private function clamp(float $value): int
    {
        return (int) round(max(0, min(100, $value)));
    }
}
```

`AuditPipeline` — add `private ScoreCalculator $scoreCalculator,` to the constructor and replace the middle of `run()`'s try block with:

```php
$collected = $this->metricsCollector->collect($path);
$metrics = $collected['metrics'];
$scores = $this->scoreCalculator->calculate($metrics);
$metrics['computed_scores'] = $scores;
$auditRequest->update(['metrics' => $metrics]);

$payload = $this->analyzer->analyze($metrics, $collected['excerpts']);
$payload['scores'] = $scores;

$report = $this->reportService->create($auditRequest, $payload);
```

`ClaudeAnalyzer::SYSTEM_PROMPT` — append one sentence to the heredoc:

```
The metrics include computed_scores measured deterministically; treat them as authoritative — output them
verbatim as your scores and keep the summary, risks, and plan consistent with them.
```

- [ ] **Step 4: Update `AuditPipelineTest`**

Its fake analyzer returns a payload with arbitrary scores; the stored report must now carry calculator scores. Add/adjust an assertion:

```php
$expected = app(\App\Services\AuditReport\ScoreCalculator::class)
    ->calculate($auditRequest->refresh()->metrics);
$this->assertSame($expected, $auditRequest->report->payload['scores']);
```

(`calculate()` ignores the extra `computed_scores` key present in the stored metrics.)

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test --compact --filter="ScoreCalculatorTest|AuditPipelineTest"`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add app/Services tests
git commit -m "feat(backend): deterministic health scores computed from measured metrics"
```

---

### Task 16: Git insights — hotspots and bus factor

**Files:**
- Modify: `backend/config/audit.php` (`clone_depth`)
- Modify: `backend/app/Services/AuditReport/RepositoryCloner.php:31` (depth from config)
- Modify: `backend/app/Services/AuditReport/MetricsCollector.php` (`gitInfo` + `hotspots`)
- Modify: `backend/resources/views/reports/audit-web.blade.php` (facts card additions)
- Modify: `backend/resources/data/sample-audit-report.json` (add `hotspots` + git extras)
- Test: `backend/tests/Feature/Services/MetricsCollectorGitTest.php`

**Interfaces:**
- Produces: `metrics['git']` gains `commits_analyzed` (int), `contributors` (int), `top_contributor_pct` (int|null); new `metrics['hotspots']` = up to 10 of `['path' => string, 'changes' => int, 'loc' => int]` ordered by `changes × loc` desc (files still present in HEAD only, min 2 changes). Window = last `audit.clone_depth` commits (shallow clone).
- Consumes: nothing new; the LLM sees these via the metrics JSON.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\MetricsCollector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class MetricsCollectorGitTest extends FeatureTest
{
    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoPath = storage_path('framework/testing/git-insights-'.uniqid());
        File::ensureDirectoryExists($this->repoPath);

        Process::path($this->repoPath)->run(['git', 'init', '-q']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.email', 'a@example.com']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.name', 'A']);

        File::put($this->repoPath.'/churny.php', "<?php\n// v1 padding padding padding\n");
        File::put($this->repoPath.'/stable.php', "<?php\n// stable padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'add', '.']);
        Process::path($this->repoPath)->run(['git', 'commit', '-qm', 'c1']);

        File::put($this->repoPath.'/churny.php', "<?php\n// v2 padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'commit', '-aqm', 'c2']);

        Process::path($this->repoPath)->run(['git', 'config', 'user.email', 'b@example.com']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.name', 'B']);
        File::put($this->repoPath.'/churny.php', "<?php\n// v3 padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'commit', '-aqm', 'c3']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    public function test_collects_contributor_stats_and_hotspots(): void
    {
        $metrics = app(MetricsCollector::class)->collect($this->repoPath)['metrics'];

        $this->assertSame(3, $metrics['git']['commits_analyzed']);
        $this->assertSame(2, $metrics['git']['contributors']);
        $this->assertSame(67, $metrics['git']['top_contributor_pct']); // 2 of 3 commits

        $this->assertNotEmpty($metrics['hotspots']);
        $this->assertSame('churny.php', $metrics['hotspots'][0]['path']);
        $this->assertSame(3, $metrics['hotspots'][0]['changes']);
        $this->assertArrayNotHasKey('stable.php', array_column($metrics['hotspots'], 'changes', 'path')); // only 1 change
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MetricsCollectorGitTest`
Expected: FAIL — `commits_analyzed`/`hotspots` keys missing.

- [ ] **Step 3: Implement**

`config/audit.php` — add: `'clone_depth' => 200,`

`RepositoryCloner::clone` — change the clone command's depth argument:

```php
->run(['git', 'clone', '--depth', (string) config('audit.clone_depth'), '--no-tags', '--single-branch', $this->authenticatedUrl($url), $path]);
```

`MetricsCollector` — replace `gitInfo()` and add `hotspots()`; in `collect()` add `'hotspots' => $this->hotspots($repoPath, $fileStats),` after the `'git'` entry:

```php
private function gitInfo(string $repoPath): array
{
    $branch = Process::path($repoPath)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
    $lastCommit = Process::path($repoPath)->run(['git', 'log', '-1', '--format=%cI']);
    $authors = Process::path($repoPath)->run(['git', 'log', '--format=%ae']);

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

private function hotspots(string $repoPath, array $fileStats): array
{
    $log = Process::path($repoPath)->run(['git', 'log', '--name-only', '--format=']);
    $changes = array_count_values(array_filter(explode("\n", trim($log->output()))));
    $locByPath = array_column($fileStats, 'loc', 'path');

    $hotspots = [];
    foreach ($changes as $path => $count) {
        if ($count < 2 || ! isset($locByPath[$path])) {
            continue;
        }
        $hotspots[] = ['path' => $path, 'changes' => $count, 'loc' => $locByPath[$path]];
    }

    usort($hotspots, fn (array $a, array $b) => ($b['changes'] * $b['loc']) <=> ($a['changes'] * $a['loc']));

    return array_slice($hotspots, 0, 10);
}
```

- [ ] **Step 4: Display in the facts card**

`audit-web.blade.php` — inside the facts card, after the largest-files table:

```blade
@php($hotspots = array_slice($metrics['hotspots'] ?? [], 0, 5))
@if ($hotspots !== [])
    <table style="margin-top: 12px;">
        <tr><th>{{ __('Change hotspots (last :n commits)', ['n' => config('audit.clone_depth')]) }}</th><th>{{ __('Changes') }}</th><th>{{ __('Lines') }}</th></tr>
        @foreach ($hotspots as $spot)
            <tr><td>{{ $spot['path'] }}</td><td>{{ $spot['changes'] }}</td><td>{{ number_format($spot['loc']) }}</td></tr>
        @endforeach
    </table>
@endif
@if (($metrics['git']['contributors'] ?? 0) > 0)
    <p class="muted" style="margin-top: 12px;">
        {{ __(':c contributor(s) in the last :n commits — top contributor authored :p% of them.', [
            'c' => $metrics['git']['contributors'],
            'n' => $metrics['git']['commits_analyzed'],
            'p' => $metrics['git']['top_contributor_pct'],
        ]) }}
    </p>
@endif
```

Sample fixture — inside the `"metrics"` object added in Task 11, extend `"git"` and add `"hotspots"`:

```json
"git": {"default_branch": "main", "last_commit_at": "2026-06-28T14:12:00+00:00", "commits_analyzed": 200, "contributors": 2, "top_contributor_pct": 94},
"hotspots": [
    {"path": "src/services/PaymentService.ts", "changes": 41, "loc": 2412},
    {"path": "src/screens/Checkout.tsx", "changes": 28, "loc": 1876},
    {"path": "src/api/handlers.ts", "changes": 19, "loc": 1544}
]
```

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test --compact --filter="MetricsCollectorGitTest|MetricsCollectorTest|RepositoryClonerTest|AuditReportFactsTest"`
Expected: PASS (cloner tests assert command arrays — update the expected `--depth` value there if hardcoded).

```bash
vendor/bin/pint --dirty
git add config app/Services resources tests
git commit -m "feat(backend): churn hotspots and bus-factor insights from git history"
```

---

### Task 17: Score deltas — "since your last audit"

**Files:**
- Create: `backend/app/Services/AuditReport/AuditDeltaService.php`
- Modify: `backend/app/Http/Controllers/AuditReportController.php` (`show`/`sample` pass `$deltas`)
- Modify: `backend/resources/views/reports/audit-web.blade.php` (delta badges)
- Modify: `backend/app/Services/AuditReport/AuditReportService.php` (`send` passes deltas)
- Modify: `backend/app/Mail/Audit/AuditReportReady.php` + `backend/resources/views/emails/audit/report-ready.blade.php`
- Test: `backend/tests/Feature/Services/AuditDeltaServiceTest.php`

**Interfaces:**
- Produces: `AuditDeltaService::deltasFor(AuditReport $report): ?array` → `null` when no earlier report exists for the same email + repo URL, else `['previous_at' => Carbon, 'deltas' => ['structure' => int, 'duplication' => int, 'testing' => int, 'dependencies' => int, 'security_hygiene' => int, 'overall' => int]]` (current − previous). Repo URLs match with/without a trailing slash.
- Consumes: `payload['scores']` (works with both LLM-era and Task-15 deterministic scores).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditDeltaService;
use Tests\Feature\FeatureTest;

class AuditDeltaServiceTest extends FeatureTest
{
    private function reportWithOverall(string $email, string $repoUrl, int $overall): AuditReport
    {
        $request = AuditRequest::factory()->verified()->create(['email' => $email, 'repo_url' => $repoUrl]);
        $payload = AuditReport::factory()->raw()['payload'];
        $payload['scores'] = array_map(fn () => $overall, $payload['scores']);

        return AuditReport::factory()->locked()->create(['audit_request_id' => $request->id, 'payload' => $payload]);
    }

    public function test_deltas_compare_against_previous_report_of_same_email_and_repo(): void
    {
        $this->reportWithOverall('delta@example.com', 'https://github.com/acme/app', 40);
        $current = $this->reportWithOverall('delta@example.com', 'https://github.com/acme/app/', 55); // note trailing slash

        $result = app(AuditDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $this->assertSame(15, $result['deltas']['overall']);
        $this->assertSame(15, $result['deltas']['testing']);
    }

    public function test_first_report_for_a_repo_has_no_deltas(): void
    {
        $this->reportWithOverall('delta2@example.com', 'https://github.com/acme/other', 40);
        $current = $this->reportWithOverall('delta2@example.com', 'https://github.com/acme/app', 55);

        $this->assertNull(app(AuditDeltaService::class)->deltasFor($current));
    }

    public function test_other_users_reports_are_not_compared(): void
    {
        $this->reportWithOverall('someone-else@example.com', 'https://github.com/acme/app', 40);
        $current = $this->reportWithOverall('delta3@example.com', 'https://github.com/acme/app', 55);

        $this->assertNull(app(AuditDeltaService::class)->deltasFor($current));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuditDeltaServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

`backend/app/Services/AuditReport/AuditDeltaService.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditReport;

class AuditDeltaService
{
    private const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'];

    public function deltasFor(AuditReport $report): ?array
    {
        $repoUrl = rtrim((string) $report->auditRequest->repo_url, '/');

        if ($repoUrl === '') {
            return null;
        }

        $previous = AuditReport::query()
            ->whereHas('auditRequest', fn ($query) => $query
                ->where('email', $report->auditRequest->email)
                ->whereIn('repo_url', [$repoUrl, $repoUrl.'/']))
            ->where('id', '<', $report->id)
            ->latest('id')
            ->first();

        if ($previous === null) {
            return null;
        }

        $deltas = [];
        foreach (self::DIMENSIONS as $dimension) {
            $deltas[$dimension] = (int) data_get($report->payload, "scores.$dimension", 0)
                - (int) data_get($previous->payload, "scores.$dimension", 0);
        }

        return ['previous_at' => $previous->created_at, 'deltas' => $deltas];
    }
}
```

- [ ] **Step 4: Wire into the web report**

`AuditReportController::show` — add to the view data: `'deltas' => app(AuditDeltaService::class)->deltasFor($auditReport),` (import `App\Services\AuditReport\AuditDeltaService`). In `sample()` add `'deltas' => null,`.

`audit-web.blade.php` — in the hero card, after the percentile paragraph:

```blade
@if ($deltas !== null && ($deltas['deltas']['overall'] ?? 0) !== 0)
    <p class="muted" style="color: {{ $deltas['deltas']['overall'] > 0 ? '#4d7c0f' : '#b91c1c' }};">
        {{ sprintf('%+d', $deltas['deltas']['overall']) }}
        {{ __('since your previous audit on :date', ['date' => $deltas['previous_at']->format('Y-m-d')]) }}
    </p>
@endif
```

and inside each score tile (after the `label` div):

```blade
@if ($deltas !== null && ($deltas['deltas'][$dimension] ?? 0) !== 0)
    <div style="font-size: 11px; font-weight: bold; color: {{ $deltas['deltas'][$dimension] > 0 ? '#4d7c0f' : '#b91c1c' }};">
        {{ sprintf('%+d', $deltas['deltas'][$dimension]) }}
    </div>
@endif
```

- [ ] **Step 5: Wire into the report-ready email**

`AuditReportReady` — add a third constructor property `public ?array $deltas = null` (after the existing report + URL properties; open the file and match its exact property names).

`AuditReportService` — add `private AuditDeltaService $deltaService,` to the constructor (alongside the Task 1 funnel) and change `send()`'s mail line to:

```php
Mail::to($report->auditRequest->email)
    ->send(new AuditReportReady($report, $this->signedUrl($report), $this->deltaService->deltasFor($report)));
```

`report-ready.blade.php` — after the main link paragraph (match the file's markup):

```blade
@if ($deltas !== null && ($deltas['deltas']['overall'] ?? 0) !== 0)
    <p style="margin: 16px 0 0; line-height: 24px">
        {{ $deltas['deltas']['overall'] > 0
            ? __('Good news: overall health is up :d points since your last audit of this repository.', ['d' => $deltas['deltas']['overall']])
            : __('Heads up: overall health is down :d points since your last audit of this repository.', ['d' => abs($deltas['deltas']['overall'])]) }}
    </p>
@endif
```

- [ ] **Step 6: Run tests and commit**

Run: `php artisan test --compact --filter="AuditDeltaServiceTest|AuditReportUnlockTest|Audit"`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add app/Services app/Http app/Mail resources/views tests
git commit -m "feat(backend): score deltas vs previous audit in report page and ready email"
```

---

### Task 18: Scheduled re-audits from the dashboard

**Files:**
- Create: `backend/database/migrations/2026_07_13_000003_create_audit_schedules_table.php`
- Create: `backend/app/Models/AuditSchedule.php`
- Create: `backend/database/factories/AuditScheduleFactory.php`
- Create: `backend/app/Console/Commands/RunScheduledAudits.php`
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php` (`setSchedule`, `getViewData`)
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php` (frequency select)
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/RunScheduledAuditsTest.php`, `backend/tests/Feature/Filament/AuditReportsPageTest.php` (one test)

**Interfaces:**
- Produces: `audit_schedules` table (`user_id`, `tenant_id`, `repo_url`, `frequency` `weekly|monthly`, `last_run_at`); `AuditReports::setSchedule(string $repoUrl, string $frequency)` accepting `off|weekly|monthly`; command `app:run-scheduled-audits` (daily 06:00, `withoutOverlapping`, `onOneServer`). Scheduled runs are ordinary `source = 'dashboard'` requests, so they consume the monthly allowance and their reports are auto-unlocked; the Task 17 delta email fires automatically via `AuditReportService::send`.
- Consumes: `AuditEntitlementService::remainingDashboardRuns(User, Tenant): int` (existing).

- [ ] **Step 1: Write the failing command test**

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class RunScheduledAuditsTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function allowRuns(int $remaining): void
    {
        $this->mock(AuditEntitlementService::class, function ($mock) use ($remaining) {
            $mock->shouldReceive('remainingDashboardRuns')->andReturn($remaining);
        });
    }

    public function test_due_weekly_schedule_dispatches_a_dashboard_audit(): void
    {
        $this->allowRuns(3);
        $schedule = AuditSchedule::factory()->create([
            'frequency' => 'weekly',
            'last_run_at' => now()->subDays(8),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $schedule->user_id)
            ->where('repo_url', $schedule->repo_url)->firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertTrue($schedule->refresh()->last_run_at->isCurrentDay());
    }

    public function test_not_yet_due_schedule_is_skipped(): void
    {
        $this->allowRuns(3);
        $schedule = AuditSchedule::factory()->create([
            'frequency' => 'weekly',
            'last_run_at' => now()->subDays(2),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['user_id' => $schedule->user_id, 'repo_url' => $schedule->repo_url]);
    }

    public function test_exhausted_allowance_skips_without_failing(): void
    {
        $this->allowRuns(0);
        $schedule = AuditSchedule::factory()->create(['frequency' => 'monthly', 'last_run_at' => null]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['user_id' => $schedule->user_id, 'repo_url' => $schedule->repo_url]);
        $this->assertNull($schedule->refresh()->last_run_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RunScheduledAuditsTest`
Expected: FAIL — table/model/command missing.

- [ ] **Step 3: Implement migration, model, factory, command**

Migration `2026_07_13_000003_create_audit_schedules_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('repo_url', 2048);
            $table->string('frequency', 10);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_schedules');
    }
};
```

`backend/app/Models/AuditSchedule.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSchedule extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'tenant_id', 'repo_url', 'frequency', 'last_run_at'];

    protected $casts = ['last_run_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

`backend/database/factories/AuditScheduleFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => Tenant::factory(),
            'repo_url' => 'https://github.com/acme/'.fake()->slug(2),
            'frequency' => 'weekly',
            'last_run_at' => null,
        ];
    }
}
```

`backend/app/Console/Commands/RunScheduledAudits.php`:

```php
<?php

namespace App\Console\Commands;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Console\Command;

class RunScheduledAudits extends Command
{
    protected $signature = 'app:run-scheduled-audits';

    protected $description = 'Dispatch dashboard audits for schedules that are due';

    public function handle(AuditEntitlementService $entitlements): int
    {
        $due = AuditSchedule::query()->with(['user', 'tenant'])->get()
            ->filter(fn (AuditSchedule $schedule) => $schedule->last_run_at === null
                || $schedule->last_run_at <= ($schedule->frequency === 'weekly' ? now()->subWeek() : now()->subMonth()));

        $started = 0;

        foreach ($due as $schedule) {
            if ($entitlements->remainingDashboardRuns($schedule->user, $schedule->tenant) < 1) {
                $this->warn("Skipping {$schedule->repo_url}: no analyses left for {$schedule->user->email}");

                continue;
            }

            $auditRequest = AuditRequest::create([
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'repo_url' => $schedule->repo_url,
                'status' => AuditRequestStatus::QUEUED->value,
                'email_verified_at' => now(),
                'source' => 'dashboard',
                'user_id' => $schedule->user->id,
            ]);

            GenerateAuditReport::dispatch($auditRequest);
            $schedule->update(['last_run_at' => now()]);
            $started++;
        }

        $this->info("Started {$started} scheduled audits.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add:

```php
Schedule::command('app:run-scheduled-audits')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
```

- [ ] **Step 4: Dashboard UI**

`AuditReports` page — add the method and view data (imports: `App\Models\AuditSchedule`):

```php
public function setSchedule(string $repoUrl, string $frequency): void
{
    $user = auth()->user();
    $tenant = Filament::getTenant();

    if ($tenant === null || ! in_array($frequency, ['off', 'weekly', 'monthly'], true)) {
        return;
    }

    $repoUrl = rtrim($repoUrl, '/');

    if ($frequency === 'off') {
        AuditSchedule::query()->where('user_id', $user->id)->where('repo_url', $repoUrl)->delete();
        Notification::make()->title(__('Scheduled audits turned off'))->success()->send();

        return;
    }

    AuditSchedule::updateOrCreate(
        ['user_id' => $user->id, 'repo_url' => $repoUrl],
        ['tenant_id' => $tenant->id, 'frequency' => $frequency],
    );

    Notification::make()->title(__('Audits scheduled :frequency', ['frequency' => __($frequency)]))->success()->send();
}
```

In `getViewData()`, add to the returned array:

```php
'schedules' => AuditSchedule::query()->where('user_id', $user->id)->pluck('frequency', 'repo_url'),
```

`audit-reports.blade.php` — in the repo-group section, inside the `flex items-center gap-3` div (before the Re-run button):

```blade
@if ($allowance > 0)
    <select
        class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
        wire:change="setSchedule('{{ $repoUrl }}', $event.target.value)"
    >
        @foreach (['off' => __('No schedule'), 'weekly' => __('Audit weekly'), 'monthly' => __('Audit monthly')] as $value => $optionLabel)
            <option value="{{ $value }}" @selected(($schedules[rtrim($repoUrl, '/')] ?? 'off') === $value)>{{ $optionLabel }}</option>
        @endforeach
    </select>
@endif
```

- [ ] **Step 5: Livewire test for setSchedule** (append to `AuditReportsPageTest`, reusing its tenant/Livewire arrangement)

```php
public function test_set_schedule_creates_and_removes_audit_schedules(): void
{
    $tenant = \App\Models\Tenant::factory()->create();
    $user = $this->createUser($tenant);
    $this->actingAs($user);
    \Filament\Facades\Filament::setTenant($tenant);

    \Livewire\Livewire::test(\App\Filament\Dashboard\Pages\AuditReports::class)
        ->call('setSchedule', 'https://github.com/acme/app/', 'weekly');

    $this->assertDatabaseHas('audit_schedules', [
        'user_id' => $user->id,
        'repo_url' => 'https://github.com/acme/app',
        'frequency' => 'weekly',
    ]);

    \Livewire\Livewire::test(\App\Filament\Dashboard\Pages\AuditReports::class)
        ->call('setSchedule', 'https://github.com/acme/app', 'off');

    $this->assertDatabaseMissing('audit_schedules', ['user_id' => $user->id]);
}
```

- [ ] **Step 6: Run tests and commit**

Run: `php artisan test --compact --filter="RunScheduledAuditsTest|AuditReportsPageTest"`
Expected: PASS

```bash
vendor/bin/pint --dirty
git add database app/Models app/Console app/Filament resources/views routes/console.php tests
git commit -m "feat(backend): scheduled weekly/monthly re-audits with allowance-aware runner"
```

---

### Task 19: Legal pages (privacy + terms) and consent links

**Files:**
- Create: `frontend/src/pages/privacy.astro`
- Create: `frontend/src/pages/terms.astro`
- Modify: `frontend/src/pages/index.astro` (footer INDEX column, ~line 907-912)
- Modify: `frontend/src/components/widgets/ContactModal.astro` (consent label links privacy)

**Interfaces:**
- Produces: routes `/privacy` and `/terms`. Both pages state plainly they collect name/email/repo URL and that code excerpts are sent to Anthropic's API for analysis — this must stay accurate to the backend pipeline.

**Note for the reviewer/operator:** this is honest baseline legal copy, not legal advice — have counsel review before public launch. It is far better than shipping a PII-collecting form with no policy at all.

- [ ] **Step 1: Create `frontend/src/pages/privacy.astro`**

```astro
---
import Layout from '~/layouts/Layout.astro';

const metadata = { title: 'Privacy Policy' };
---

<Layout metadata={metadata}>
  <main style="max-width: 720px; margin: 0 auto; padding: 80px 24px 96px; color: #e8e6de; font-size: 15px; line-height: 1.75;">
    <a href="/" style="color: #d4a853; text-decoration: none; font-size: 13px;">← flexpick.net</a>
    <h1 style="font-size: 32px; margin: 24px 0 8px; color: #f5f5f0;">Privacy Policy</h1>
    <p style="color: rgba(232,230,222,0.55); font-size: 13px;">Last updated: July 13, 2026</p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">What we collect</h2>
    <p>
      When you request an audit we collect your name, email address, an optional repository or product URL, and any
      message you include. We also record the IP address and browser user-agent of the request for abuse prevention.
      If you create an account, we store your account and billing details; card data is handled entirely by our
      payment providers and never touches our servers.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">How we handle your code</h2>
    <p>
      To produce an audit we clone your repository into an isolated working directory, compute metrics from it, and
      send limited file excerpts to Anthropic's API for analysis. The clone is deleted as soon as the analysis
      finishes. We do not use your code to train models, and we do not share it with anyone other than the analysis
      provider named above. We are happy to sign an NDA before you grant access.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">How we use your data</h2>
    <p>
      We use your contact details to deliver the audit and send transactional email about it (verification, status,
      report links, receipts). We only send marketing email if you ticked the consent box, and every such email has
      an unsubscribe link. Unverified audit requests are deleted after 7 days.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">Who we share it with</h2>
    <p>
      Only the processors needed to run the service: our hosting provider, our transactional email provider, our
      payment providers (e.g. Stripe, Paddle, Lemon Squeezy), and Anthropic for report analysis. We never sell your
      data.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">Your rights</h2>
    <p>
      You can request a copy of your data or ask us to delete it at any time — email
      <a href="mailto:hello@flexpick.net" style="color: #d4a853;">hello@flexpick.net</a> and we'll act within 30
      days. If you are in the EU/EEA, you additionally have the rights granted by the GDPR, including the right to
      lodge a complaint with your supervisory authority.
    </p>
  </main>
</Layout>
```

(Open `~/layouts/Layout.astro` first — if its metadata prop shape differs from `{ title: ... }`, match whatever `index.astro` passes.)

- [ ] **Step 2: Create `frontend/src/pages/terms.astro`**

```astro
---
import Layout from '~/layouts/Layout.astro';

const metadata = { title: 'Terms of Service' };
---

<Layout metadata={metadata}>
  <main style="max-width: 720px; margin: 0 auto; padding: 80px 24px 96px; color: #e8e6de; font-size: 15px; line-height: 1.75;">
    <a href="/" style="color: #d4a853; text-decoration: none; font-size: 13px;">← flexpick.net</a>
    <h1 style="font-size: 32px; margin: 24px 0 8px; color: #f5f5f0;">Terms of Service</h1>
    <p style="color: rgba(232,230,222,0.55); font-size: 13px;">Last updated: July 13, 2026</p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">The service</h2>
    <p>
      FlexPick produces automated codebase health reports and offers related engineering services. Free audits are
      limited to 3 per email address. Full report contents can be unlocked with a one-time payment, and subscriptions
      include a monthly allowance of audit runs as described on the pricing page.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">Your repository</h2>
    <p>
      You may only submit repositories you own or have the right to share with us. You grant us a limited license to
      clone and analyze the submitted code solely to produce your report. We delete the clone after analysis.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">Payments and cancellation</h2>
    <p>
      Payments are processed by our payment providers. Subscriptions renew monthly until cancelled from your
      dashboard; cancellation stops future charges and your allowance remains usable until the end of the paid
      period. If something went wrong with a purchase, email us — we'd rather fix it than argue about it.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">No warranties</h2>
    <p>
      Reports are produced by automated static analysis and AI-assisted review. They are an assessment, not a
      guarantee — they may miss issues or flag non-issues, and they are not a security certification. The service is
      provided "as is"; to the maximum extent permitted by law, our total liability for any claim is limited to the
      amount you paid us in the 12 months before the claim.
    </p>

    <h2 style="font-size: 20px; margin: 36px 0 12px; color: #f5f5f0;">Changes and contact</h2>
    <p>
      We may update these terms; material changes will be announced by email to account holders. Questions:
      <a href="mailto:hello@flexpick.net" style="color: #d4a853;">hello@flexpick.net</a>.
    </p>
  </main>
</Layout>
```

- [ ] **Step 3: Link from footer and modal**

`index.astro` footer INDEX column — after the FAQ link (line ~911) add:

```astro
<a href="/privacy" class="fp-footlink">Privacy</a>
<a href="/terms" class="fp-footlink">Terms</a>
```

`ContactModal.astro` consent label — change the `<span>` text to:

```astro
<span>
  Send me occasional tips and product updates. No spam, unsubscribe anytime. See our
  <a href="/privacy" style="color: #d4a853;">privacy policy</a>.
</span>
```

- [ ] **Step 4: Verify and commit**

Run (from `frontend/`): `npm run check && npm run build`
Expected: pass; `dist/privacy/index.html` and `dist/terms/index.html` exist.

```bash
git add src/pages/privacy.astro src/pages/terms.astro src/pages/index.astro src/components/widgets/ContactModal.astro
git commit -m "feat(frontend): privacy policy and terms pages with footer/consent links"
```

---

### Task 20: OG image, package identity, structured data

**Files:**
- Create: `frontend/scripts/generate-og.mjs`
- Create (generated): `frontend/src/assets/images/default.png`
- Modify: `frontend/package.json` (name/description + sharp devDependency)
- Modify: `frontend/src/pages/index.astro` (JSON-LD)

**Interfaces:**
- Consumes: `src/config.yaml` already points `openGraph.images[0].url` at `~/assets/images/default.png` — the file just doesn't exist, so every social share renders imageless. This task creates it.

- [ ] **Step 1: Fix package identity**

In `frontend/package.json` set:

```json
"name": "flexpick-site",
"description": "FlexPick marketing site — we rescue AI-built codebases.",
```

(The current values still describe the pre-pivot "Flexible Workforce Solutions" product.)

- [ ] **Step 2: Create the OG generator script**

Run: `npm install --save-dev sharp`

`frontend/scripts/generate-og.mjs`:

```js
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const svg = `<svg width="1200" height="630" xmlns="http://www.w3.org/2000/svg">
  <rect width="1200" height="630" fill="#0d0c0a"/>
  <rect x="0" y="622" width="1200" height="8" fill="#d4a853"/>
  <text x="80" y="270" font-family="Helvetica, Arial, sans-serif" font-size="76" font-weight="800" fill="#f5f5f0">Flex<tspan fill="#d4a853">Pick</tspan></text>
  <text x="80" y="366" font-family="Helvetica, Arial, sans-serif" font-size="42" fill="#e8e6de">We rescue AI-built codebases.</text>
  <text x="80" y="440" font-family="Helvetica, Arial, sans-serif" font-size="26" fill="#8a877d">Free codebase audit · flexpick.net</text>
</svg>`;

const outDir = fileURLToPath(new URL('../src/assets/images/', import.meta.url));
await mkdir(outDir, { recursive: true });
await sharp(Buffer.from(svg)).png().toFile(outDir + 'default.png');
console.log('Wrote src/assets/images/default.png');
```

Run: `node scripts/generate-og.mjs`
Expected: `Wrote src/assets/images/default.png`; verify the file is 1200×630 (`file src/assets/images/default.png`).

- [ ] **Step 3: Add JSON-LD to `index.astro`**

In the frontmatter (after the `PRODUCT_APP` import), add:

```ts
const structuredData = {
  '@context': 'https://schema.org',
  '@graph': [
    {
      '@type': 'Organization',
      name: 'FlexPick',
      url: 'https://flexpick.net',
      email: 'hello@flexpick.net',
      description: 'We rescue AI-built codebases — audits, stabilization, and engineering practices for vibecoded products.',
    },
    { '@type': 'WebSite', name: 'FlexPick', url: 'https://flexpick.net' },
    {
      '@type': 'FAQPage',
      mainEntity: [
        {
          '@type': 'Question',
          name: 'Will you rewrite everything from scratch?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'No — full rewrites are slow, expensive, and risky. We keep what works, refactor what is fragile, and rebuild only the parts that are genuinely beyond saving. Your product keeps running the whole time.',
          },
        },
        {
          '@type': 'Question',
          name: 'My product works fine. Why should I care?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'It works today. The pattern we see: features that took days start taking weeks, every change breaks something unrelated, and the whole product depends on one person who gets the code. Fixing that early is far cheaper than fixing it during a crisis.',
          },
        },
        {
          '@type': 'Question',
          name: 'Are you against AI-assisted development?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'The opposite — we use AI coding tools every day. They are genuinely great, but on a codebase without structure they duplicate code, miss context, and compound the mess. We fix the foundation and set up guardrails so AI makes your team faster, not more fragile.',
          },
        },
        {
          '@type': 'Question',
          name: 'What do you need from me to start?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Read access to your repository and about 30 minutes of your time to walk us through the product. That is enough for the free audit. We are happy to sign an NDA first.',
          },
        },
        {
          '@type': 'Question',
          name: 'What if the audit finds nothing serious?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Then we tell you exactly that, and you walk away with a free health report confirming your codebase is in good shape. We only take on projects where we are confident we can make a real difference.',
          },
        },
        {
          '@type': 'Question',
          name: 'What happens after the rescue?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Your team continues building — now on a stable foundation, with docs, tests, and AI guardrails in place. If you want us to stay involved for features, reviews, or support, we offer ongoing partnership. If not, everything is set up for you to run without us.',
          },
        },
      ],
    },
  ],
};
```

In the template, directly **before** the `<!-- ===== Footer ===== -->` comment (line ~872), add:

```astro
<script type="application/ld+json" set:html={JSON.stringify(structuredData)} />
```

- [ ] **Step 4: Verify and commit**

Run: `npm run check && npm run build`
Expected: pass. Then `grep -o 'og:image[^>]*' dist/index.html` shows an `/_astro/default.*.png` URL, and `grep -c 'FAQPage' dist/index.html` returns 1.

```bash
git add package.json package-lock.json scripts/generate-og.mjs src/assets/images/default.png src/pages/index.astro
git commit -m "feat(frontend): real OG image, FAQPage/Organization JSON-LD, package identity"
```

---

### Task 21: Landing "product path" section (positioning)

**Files:**
- Modify: `frontend/src/pages/index.astro` (new section before FAQ, footer link)

**Interfaces:**
- Consumes: `PRODUCT_APP.url` (backend app), existing `fp-card`/`fp-mono`/`fp-h2` classes and the `data-r="grid3"` responsive grid attribute used by the Services section.

The landing page currently sells only the high-touch service and never mentions that the audit is a self-serve product with a $5 full report and monitoring plans — `/pricing` is discoverable only *after* receiving a locked report. This section closes that gap without adding public per-plan pricing tables (spec guardrail: prices named are the two entry points only).

- [ ] **Step 1: Insert the section**

In `index.astro`, directly **before** the `<!-- ===== FAQ ===== -->` comment (line ~739), add:

```astro
<!-- ===== Product path ===== -->
<section
  id="offer"
  style="position: relative; padding: 100px 32px; border-top: 1px solid rgba(255,255,255,0.05); scroll-margin-top: 80px;"
>
  <div style="max-width: 1140px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 64px;">
      <p class="fp-mono" style="margin: 0 0 12px; font-size: 12px; letter-spacing: 0.16em; color: #d4a853;">
        THE AUDIT, AS A PRODUCT
      </p>
      <h2 class="fp-h2">One audit. Three ways to use it.</h2>
    </div>
    <div data-r="grid3" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
      <div class="fp-card" style="border: 1px solid rgba(255,255,255,0.07); border-radius: 10px; padding: 28px; background: rgba(255,255,255,0.015);">
        <p class="fp-mono fp-card-tag"><span style="color: #d4a853;">01</span> · free/</p>
        <h3 class="fp-card-title">Free health check</h3>
        <p class="fp-card-body">
          Submit a repo, confirm your email, and get a real report: overall health score, five sub-scores, measured
          repository facts, and every risk we found. First 3 audits per email are on us.
        </p>
      </div>
      <div class="fp-card" style="border: 1px solid rgba(255,255,255,0.07); border-radius: 10px; padding: 28px; background: rgba(255,255,255,0.015);">
        <p class="fp-mono fp-card-tag"><span style="color: #d4a853;">02</span> · full-report/</p>
        <h3 class="fp-card-title">Full report — $5</h3>
        <p class="fp-card-body">
          Unlock the evidence behind every risk, a prioritized fix-first plan your team can start on today, and a PDF
          you can share. One click from your report page.
        </p>
      </div>
      <div class="fp-card" style="border: 1px solid rgba(255,255,255,0.07); border-radius: 10px; padding: 28px; background: rgba(255,255,255,0.015);">
        <p class="fp-mono fp-card-tag"><span style="color: #d4a853;">03</span> · monitor/</p>
        <h3 class="fp-card-title">Track it — from $10/mo</h3>
        <p class="fp-card-body">
          Re-audit on a schedule, watch your health score trend as you ship, and catch new risks before they compound.
          Plans include 5 to 50 audits a month.
        </p>
      </div>
    </div>
    <div style="text-align: center; margin-top: 40px; display: flex; gap: 28px; justify-content: center; flex-wrap: wrap;">
      <a href={`${PRODUCT_APP.url}/reports/sample`} target="_blank" rel="noopener" class="fp-footlink" style="color: #d4a853;">
        See a sample report →
      </a>
      <a href={`${PRODUCT_APP.url}/pricing`} rel="nofollow" class="fp-footlink" style="color: #d4a853;">
        View plans →
      </a>
    </div>
  </div>
</section>
```

(Copy discipline: the only numbers on this section are the real seeded facts — 3 free audits, $5 unlock, $10/mo entry, 5–50 analyses. Do not add testimonials, customer counts, or outcome claims.)

- [ ] **Step 2: Add footer link**

In the footer INDEX column, after `The audit`:

```astro
<a href="#offer" class="fp-footlink">Pricing</a>
```

- [ ] **Step 3: Verify and commit**

Run: `npm run check && npm run build`
Expected: pass. `npm run dev` → the section renders between Trust and FAQ, grid collapses on mobile (the `data-r="grid3"` handler already manages it).

```bash
git add src/pages/index.astro
git commit -m "feat(frontend): product-path section connecting free audit to \$5 report and plans"
```

---

## Final verification (after all tasks)

- [ ] Backend: `php artisan test --compact` — full suite green.
- [ ] Backend: `vendor/bin/pint` — clean.
- [ ] Backend: `php artisan migrate:fresh --seed` on the dev DB, run one end-to-end web audit against a small public repo with Horizon running; confirm the status page updates, the report shows facts/hotspots/scores, and the funnel page counts the stages.
- [ ] Frontend: `npm run check && npm run build` — clean.
- [ ] Operator follow-ups (not code): paste the GA4 measurement ID into `frontend/src/config.yaml`; have counsel review `/privacy` and `/terms`; deploy backend `.env` unchanged (no new secrets required — OSV is unauthenticated).

## Self-review notes

- **Spec coverage:** analytics (T1–T3), unlock auth wall (T4), status page (T6), recovery emails (T8–T9, plus quota-exhausted prepaid offer T5), surfacing real metrics (T11), dependency/secret audits (T12–T13), tooling (T14), deterministic scores (T15), git insights (T16), deltas + scheduled re-audits (T17–T18), legal/OG/JSON-LD/positioning (T19–T21), retry + nav polish (T7, T10). GitHub App and badges intentionally out of scope.
- **Type consistency:** `AuditFunnelRecorder::record(string, ?AuditRequest, array)` used identically in T1/T4/T5; `AuditGuestAccountService::resolveUser(AuditRequest): ?User` shared by T4/T5; `ScoreCalculator::calculate(array): array` keys match `ReportPayload` score keys and `AuditDeltaService::DIMENSIONS`; `dependency_audit` shape produced in T12 matches what T15 reads.
- **Known judgment calls:** signed-URL login for brand-new accounts only (never existing ones); dangling unlock intents short-circuit instead of falling back; OSV failures degrade to a capped dependencies score rather than failing the pipeline; scores changing from LLM-judged to deterministic will shift historical trend lines once — acceptable, and the reason T15 ships before T17/T18 matter.

