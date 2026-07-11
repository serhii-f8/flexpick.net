# Audit Intake + Automated Repository Health Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The landing's audit-request modal posts to a real backend endpoint; a queued pipeline clones the repo, collects metrics, gets a schema-enforced Claude analysis, renders a PDF, and emails it to the client with a signed web link — fully automated, with a `needs_followup` fallback for inaccessible repos.

**Architecture:** One queued job (`GenerateAuditReport`, on an `audit` Horizon queue) drives an `AuditPipeline` service that calls four stage services: `RepositoryCloner` → `MetricsCollector` → `AiAnalyzer` (Claude via the official `anthropic-ai/sdk` PHP package, structured outputs) → `AuditReportService` (dompdf render + mail). Statuses live on `AuditRequest`; failures branch to `needs_followup` (repo inaccessible/oversized — automated "share access" email) or `failed` (soft "we hit a snag" email + admin notification). *(Deviation from spec: a single job calling stage services replaces the 4-job chain — a chain can't cleanly halt on the `needs_followup` branch without misreporting failure; semantics are identical and each stage is still independently unit-tested.)*

**Tech Stack:** Laravel 13, Horizon, `anthropic-ai/sdk` (PHP), `barryvdh/laravel-dompdf` (already installed transitively via `saasykit/laravel-invoices` — verify with `composer show barryvdh/laravel-dompdf`; if absent, `composer require barryvdh/laravel-dompdf`), Filament 5, Astro (modal wiring only).

**Spec:** `docs/superpowers/specs/2026-07-11-audit-report-pipeline-design.md`
**Depends on:** Plan `2026-07-11-monorepo-split-docker.md` Task 1 (`PRODUCT_APP` frontend config) for Task 13 only; all backend tasks are independent of it.

## Global Constraints

- All backend work in `backend/`, PHP 8.4 / Laravel 13. Tests: `php artisan test --compact --filter=<Name>`; full gate `php artisan test --compact` + `vendor/bin/phpstan analyse`; format `vendor/bin/pint --dirty`.
- **Cloned code is never executed** — no `composer install`/`npm install`/scripts in the workdir; static reads only.
- Hard limits (all in `config/audit.php`): clone timeout 120 s, preflight timeout 30 s, max repo size 500 MB, ≤ 50 excerpt files, ≤ 6 000 bytes/excerpt. Workdirs under `storage/app/audit-workdirs/{uuid}`, deleted in `finally`.
- AI model: `claude-opus-4-8` by default (env `AUDIT_AI_MODEL`). *(Deviation from spec's `claude-sonnet-5`: skill guidance is to default to the most capable Opus and let the user downgrade deliberately — it's env-configurable.)* Secret-pattern findings: report counts + file paths only, never matched values.
- Codebase conventions: backed enums in `app/Constants`, badge mappers in `app/Mapper`, services with constructor promotion in `app/Services`, Mailables `implements ShouldQueue` with views under `resources/views/emails/audit/`, Filament v5 (`Filament\Schemas\Schema`, `->recordActions()`, `Filament\Actions\*`), statuses stored as `->value` strings (no enum casts), anonymous-class migrations.
- Commit after each task.

---

### Task 1: Status enum + badge mapper

**Files:**
- Create: `backend/app/Constants/AuditRequestStatus.php`
- Create: `backend/app/Mapper/AuditRequestStatusMapper.php`
- Test: `backend/tests/Unit/AuditRequestStatusMapperTest.php`

**Interfaces:**
- Produces: `App\Constants\AuditRequestStatus` (string-backed enum, cases below) and `App\Mapper\AuditRequestStatusMapper` with `mapForDisplay(string): string` and `mapColor(string): string`. Every later task references these exact names. `HANDLED` supplements the spec enum — it's the terminal state for the admin "Mark handled" action (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Mapper\AuditRequestStatusMapper;
use PHPUnit\Framework\TestCase;

class AuditRequestStatusMapperTest extends TestCase
{
    public function test_every_case_has_display_and_color(): void
    {
        $mapper = new AuditRequestStatusMapper;

        foreach (AuditRequestStatus::cases() as $case) {
            $this->assertNotSame('', $mapper->mapForDisplay($case->value));
            $this->assertContains($mapper->mapColor($case->value), ['gray', 'info', 'warning', 'success', 'danger']);
        }
    }
}
```

(Pure `PHPUnit\Framework\TestCase` — no app boot needed; `__()` is unavailable there, so the mapper test asserts non-empty strings only if `mapForDisplay` uses `__()`. If the test errors on `__()`, extend `Tests\TestCase` instead.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=AuditRequestStatusMapperTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`backend/app/Constants/AuditRequestStatus.php`:

```php
<?php

namespace App\Constants;

enum AuditRequestStatus: string
{
    case NEW = 'new';
    case QUEUED = 'queued';
    case ANALYZING = 'analyzing';
    case REPORT_READY = 'report_ready';
    case SENT = 'sent';
    case FAILED = 'failed';
    case NEEDS_FOLLOWUP = 'needs_followup';
    case HANDLED = 'handled';
}
```

`backend/app/Mapper/AuditRequestStatusMapper.php` (mirror `OrderStatusMapper` style):

```php
<?php

namespace App\Mapper;

use App\Constants\AuditRequestStatus;

class AuditRequestStatusMapper
{
    public function mapForDisplay(string $status): string
    {
        return match ($status) {
            AuditRequestStatus::NEW->value => __('New'),
            AuditRequestStatus::QUEUED->value => __('Queued'),
            AuditRequestStatus::ANALYZING->value => __('Analyzing'),
            AuditRequestStatus::REPORT_READY->value => __('Report ready'),
            AuditRequestStatus::SENT->value => __('Sent'),
            AuditRequestStatus::FAILED->value => __('Failed'),
            AuditRequestStatus::NEEDS_FOLLOWUP->value => __('Needs follow-up'),
            AuditRequestStatus::HANDLED->value => __('Handled'),
            default => $status,
        };
    }

    public function mapColor(string $status): string
    {
        return match ($status) {
            AuditRequestStatus::SENT->value, AuditRequestStatus::HANDLED->value => 'success',
            AuditRequestStatus::FAILED->value => 'danger',
            AuditRequestStatus::NEEDS_FOLLOWUP->value => 'warning',
            AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::ANALYZING->value, AuditRequestStatus::QUEUED->value => 'info',
            default => 'gray',
        };
    }
}
```

- [ ] **Step 4: Run test → PASS, then commit**

```bash
php artisan test --compact --filter=AuditRequestStatusMapperTest
git add backend/app/Constants/AuditRequestStatus.php backend/app/Mapper/AuditRequestStatusMapper.php backend/tests/Unit/AuditRequestStatusMapperTest.php
git commit -m "feat(backend): audit request status enum and badge mapper"
```

---

### Task 2: Models, migrations, factories

**Files:**
- Create: `backend/database/migrations/2026_07_11_000001_create_audit_requests_table.php`
- Create: `backend/database/migrations/2026_07_11_000002_create_audit_reports_table.php`
- Create: `backend/app/Models/AuditRequest.php`, `backend/app/Models/AuditReport.php`
- Modify: `backend/app/Models/User.php` (add `auditReports()` relation)
- Create: `backend/database/factories/AuditRequestFactory.php`, `backend/database/factories/AuditReportFactory.php`
- Test: `backend/tests/Feature/Models/AuditModelsTest.php`

**Interfaces:**
- Produces: `AuditRequest` (`$fillable`: name, email, repo_url, message, status, failure_reason, meta, metrics; `meta`/`metrics` JSON arrays via accessors below; uuid auto-filled; `report()` HasOne, route key `uuid`) and `AuditReport` (`$fillable`: audit_request_id, user_id, payload, pdf_path; `payload` array; uuid auto-filled; `auditRequest()` BelongsTo, `user()` BelongsTo, route key `uuid`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditModelsTest extends FeatureTest
{
    public function test_audit_request_gets_uuid_and_has_report(): void
    {
        $request = AuditRequest::factory()->create();
        $report = AuditReport::factory()->create(['audit_request_id' => $request->id]);

        $this->assertNotEmpty($request->uuid);
        $this->assertNotEmpty($report->uuid);
        $this->assertTrue($request->report->is($report));
        $this->assertTrue($report->auditRequest->is($request));
        $this->assertSame('uuid', $report->getRouteKeyName());
        $this->assertIsArray($report->payload);
    }
}
```

- [ ] **Step 2: Run → FAIL (table/class missing)**

Run: `php artisan test --compact --filter=AuditModelsTest`

- [ ] **Step 3: Migrations**

`2026_07_11_000001_create_audit_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->index();
            $table->string('repo_url', 2048)->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('meta')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_requests');
    }
};
```

`2026_07_11_000002_create_audit_reports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('audit_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('pdf_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
    }
};
```

- [ ] **Step 4: Models**

`backend/app/Models/AuditRequest.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'email', 'repo_url', 'message', 'status', 'failure_reason', 'meta', 'metrics',
    ];

    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function report(): HasOne
    {
        return $this->hasOne(AuditReport::class);
    }
}
```

`backend/app/Models/AuditReport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['audit_request_id', 'user_id', 'payload', 'pdf_path'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

In `backend/app/Models/User.php` add (with the other relations):

```php
public function auditReports(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(AuditReport::class);
}
```

- [ ] **Step 5: Factories**

`backend/database/factories/AuditRequestFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Constants\AuditRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'repo_url' => 'https://github.com/example/repo',
            'message' => $this->faker->sentence(),
            'status' => AuditRequestStatus::NEW->value,
        ];
    }
}
```

`backend/database/factories/AuditReportFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AuditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_request_id' => AuditRequest::factory(),
            'user_id' => null,
            'payload' => [
                'summary' => 'Fixture summary.',
                'scores' => ['structure' => 60, 'duplication' => 50, 'testing' => 20, 'dependencies' => 70, 'security_hygiene' => 80, 'overall' => 55],
                'risks' => [['title' => 'No tests', 'impact' => 'high', 'evidence' => '0 test files', 'recommendation' => 'Add a smoke suite']],
                'fix_first_plan' => [['step' => 'Add CI', 'why' => 'Catch regressions', 'effort' => 'S']],
            ],
            'pdf_path' => 'audit-reports/fixture.pdf',
        ];
    }
}
```

- [ ] **Step 6: Run test → PASS, then commit**

```bash
php artisan test --compact --filter=AuditModelsTest
git add backend/database backend/app/Models
git add backend/tests/Feature/Models/AuditModelsTest.php
git commit -m "feat(backend): audit request/report models, migrations, factories"
```

---

### Task 3: Dependencies + configuration (SDK, audit config, CORS, Horizon queue, env)

**Files:**
- Modify: `backend/composer.json` (via composer)
- Create: `backend/config/audit.php`, `backend/config/cors.php`
- Modify: `backend/config/services.php`, `backend/config/horizon.php`, `backend/.env.example`

**Interfaces:**
- Produces: `config('audit.*')` keys (`queue`, `admin_email`, `clone_timeout`, `preflight_timeout`, `max_repo_size_mb`, `max_excerpt_files`, `max_excerpt_bytes`, `report_link_days`, `workdir`, `reports_dir`), `config('services.anthropic.api_key'|'model')`, CORS for the intake path, `audit` queue on Horizon.

- [ ] **Step 1: Install the official Anthropic PHP SDK**

```bash
cd backend && composer require "anthropic-ai/sdk"
composer show barryvdh/laravel-dompdf
```

Expected: both succeed. If the dompdf line errors, also run `composer require barryvdh/laravel-dompdf`.

- [ ] **Step 2: `backend/config/audit.php`**

```php
<?php

return [
    'queue' => 'audit',
    'admin_email' => env('AUDIT_ADMIN_EMAIL'),
    'clone_timeout' => 120,
    'preflight_timeout' => 30,
    'max_repo_size_mb' => 500,
    'max_excerpt_files' => 50,
    'max_excerpt_bytes' => 6000,
    'report_link_days' => 30,
    'workdir' => storage_path('app/audit-workdirs'),
    'reports_dir' => 'audit-reports',
];
```

- [ ] **Step 3: `backend/config/services.php` — add at the end of the returned array**

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('AUDIT_AI_MODEL', 'claude-opus-4-8'),
],
```

- [ ] **Step 4: `backend/config/cors.php` (new — Laravel's built-in HandleCors reads it)**

```php
<?php

return [
    'paths' => ['api/audit-requests'],
    'allowed_methods' => ['POST'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://flexpick.net,http://localhost:4321'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
```

- [ ] **Step 5: Horizon queue** — in `backend/config/horizon.php`, change the supervisor default:

```php
'queue' => ['default', 'audit'],
```

(One line change inside `'defaults' => ['supervisor-1' => [...]]`.)

- [ ] **Step 6: `.env.example` additions (at the end)**

```
ANTHROPIC_API_KEY=
AUDIT_AI_MODEL=claude-opus-4-8
AUDIT_ADMIN_EMAIL=
CORS_ALLOWED_ORIGINS=https://flexpick.net,http://localhost:4321
```

- [ ] **Step 7: Verify config loads + commit**

```bash
php artisan tinker --execute="var_dump(config('audit.queue'), config('services.anthropic.model'), config('cors.paths'));"
git add backend/composer.json backend/composer.lock backend/config backend/.env.example
git commit -m "feat(backend): audit pipeline configuration, CORS, Anthropic SDK"
```

Expected tinker output: `"audit"`, `"claude-opus-4-8"`, `["api/audit-requests"]`.

---

### Task 4: Mailables + email views

**Files:**
- Create: `backend/app/Mail/Audit/AuditRequestReceived.php`, `AuditRepoAccessNeeded.php`, `AuditReportReady.php`, `AuditRequestFailed.php`, `NewAuditRequestAdminNotification.php` (all in `backend/app/Mail/Audit/`)
- Create: `backend/resources/views/emails/audit/received.blade.php`, `access-needed.blade.php`, `report-ready.blade.php`, `failed.blade.php`, `admin-new-request.blade.php`
- Test: `backend/tests/Feature/Mail/AuditMailablesTest.php`

**Interfaces:**
- Consumes: `AuditRequest`, `AuditReport` (Task 2).
- Produces: five Mailables, all `implements ShouldQueue`. `AuditReportReady::__construct(public AuditReport $report, public string $signedUrl)` — attaches the PDF. Others take `public AuditRequest $auditRequest`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Mail;

use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditMailablesTest extends FeatureTest
{
    public function test_received_mailable_renders(): void
    {
        $request = AuditRequest::factory()->create();

        $mailable = new AuditRequestReceived($request);
        $mailable->assertSeeInHtml($request->name);
    }

    public function test_report_ready_attaches_pdf_and_links(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 fixture');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf']);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertHasAttachment(
            \Illuminate\Mail\Mailables\Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')
        );
    }
}
```

- [ ] **Step 2: Run → FAIL (classes missing)**

Run: `php artisan test --compact --filter=AuditMailablesTest`

- [ ] **Step 3: Implement the Mailables**

All five follow the existing `App\Mail\Order\Ordered` pattern. `AuditRequestReceived`:

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

class AuditRequestReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('We received your audit request'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.received',
        );
    }
}
```

`AuditRepoAccessNeeded` — identical shape, subject `__('One more step for your codebase audit')`, view `emails.audit.access-needed`.

`AuditRequestFailed` — identical shape, subject `__('About your codebase audit')`, view `emails.audit.failed`.

`NewAuditRequestAdminNotification` — identical shape, subject `__('New audit request: :email', ['email' => $this->auditRequest->email])` (build the subject in `envelope()`), view `emails.audit.admin-new-request`.

`AuditReportReady`:

```php
<?php

namespace App\Mail\Audit;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditReportReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $signedUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your codebase health report is ready'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.report-ready',
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->report->pdf_path)
                ->as('codebase-health-report.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
```

- [ ] **Step 4: Views**

Mirror the wrapper used by `resources/views/emails/order/ordered.blade.php` (open it and copy its `<x-layouts.email>` / preview-slot structure — keep whatever slot names it uses). Content per view:

`received.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">{{ __('We received your audit request') }}</x-slot>

    <p>{{ __('Hi :name,', ['name' => $auditRequest->name]) }}</p>
    <p>{{ __('Thanks for requesting a free codebase audit. Our analysis usually completes within the hour — your health report will land in this inbox.') }}</p>
    <p>{{ __('No charge, no strings, honest verdict.') }}</p>
</x-layouts.email>
```

`access-needed.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">{{ __('One more step for your codebase audit') }}</x-slot>

    <p>{{ __('Hi :name,', ['name' => $auditRequest->name]) }}</p>
    @if ($auditRequest->repo_url)
        <p>{{ __("We couldn't access :url — it may be private, or too large for automated analysis.", ['url' => $auditRequest->repo_url]) }}</p>
    @else
        <p>{{ __("You didn't include a repository link, so we couldn't start the automated analysis.") }}</p>
    @endif
    <p>{{ __('Reply to this email with a public repository URL, or grant read access to our review account, and we\'ll take it from there. Happy to sign an NDA first.') }}</p>
</x-layouts.email>
```

`report-ready.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">{{ __('Your codebase health report is ready') }}</x-slot>

    <p>{{ __('Hi :name,', ['name' => $report->auditRequest->name]) }}</p>
    <p>{{ __('Your automated codebase health report is attached as a PDF. You can also view it online:') }}</p>
    <p><a href="{{ $signedUrl }}">{{ __('View your report') }}</a> ({{ __('link valid for :days days', ['days' => config('audit.report_link_days')]) }})</p>
    <p>{{ __('This report was generated by automated analysis — scores and findings are derived from measured repository metrics. Reply to this email and a human will walk you through it.') }}</p>
</x-layouts.email>
```

`failed.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">{{ __('About your codebase audit') }}</x-slot>

    <p>{{ __('Hi :name,', ['name' => $auditRequest->name]) }}</p>
    <p>{{ __('Our automated analysis hit a snag with your repository. A human is on it — we\'ll follow up personally within one business day.') }}</p>
</x-layouts.email>
```

`admin-new-request.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">{{ __('New audit request') }}</x-slot>

    <p><strong>{{ $auditRequest->name }}</strong> &lt;{{ $auditRequest->email }}&gt;</p>
    <p>{{ __('Repo:') }} {{ $auditRequest->repo_url ?? __('(none)') }}</p>
    <p>{{ __('Message:') }} {{ $auditRequest->message ?? __('(none)') }}</p>
    <p>{{ __('Status:') }} {{ $auditRequest->status }}</p>
</x-layouts.email>
```

- [ ] **Step 5: Run test → PASS, then commit**

```bash
php artisan test --compact --filter=AuditMailablesTest
git add backend/app/Mail/Audit backend/resources/views/emails/audit backend/tests/Feature/Mail
git commit -m "feat(backend): audit pipeline mailables and email views"
```

---

### Task 5: Intake endpoint

**Files:**
- Create: `backend/app/Http/Requests/StoreAuditRequestRequest.php`
- Create: `backend/app/Http/Controllers/AuditRequestController.php`
- Create: `backend/app/Jobs/GenerateAuditReport.php` (shell — `handle()` filled in Task 10)
- Create: `backend/app/Services/AuditRequestService.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Http/Controllers/AuditRequestControllerTest.php`

**Interfaces:**
- Consumes: models (T2), config (T3), Mailables (T4).
- Produces: `POST /api/audit-requests` (name `audit-requests.store`, `throttle:5,1`); `AuditRequestService::submit(array $data, array $meta): AuditRequest` and `markNeedsFollowup(AuditRequest, string $reason)` / `markFailed(AuditRequest, string $reason)` (used by Tasks 10, 12); `GenerateAuditReport::__construct(public AuditRequest $auditRequest)` dispatched on the `audit` queue.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class AuditRequestControllerTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withExceptionHandling(); // FeatureTest disables it; we need real 422/429 JSON
        Mail::fake();
        Queue::fake();
    }

    public function test_valid_submission_creates_request_and_dispatches_pipeline(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'repo_url' => 'https://github.com/example/repo',
            'message' => 'Everything is on fire.',
            'website' => '',
        ]);

        $response->assertStatus(201)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('audit_requests', [
            'email' => 'ada@example.com',
            'status' => AuditRequestStatus::QUEUED->value,
        ]);
        Queue::assertPushedOn('audit', GenerateAuditReport::class);
        Mail::assertQueued(AuditRequestReceived::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_submission_without_repo_goes_to_followup(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada2@example.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('audit_requests', [
            'email' => 'ada2@example.com',
            'status' => AuditRequestStatus::NEEDS_FOLLOWUP->value,
        ]);
        Queue::assertNothingPushed();
        Mail::assertQueued(AuditRepoAccessNeeded::class, fn ($mail) => $mail->hasTo('ada2@example.com'));
    }

    public function test_honeypot_rejects(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'website' => 'http://spam.example',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('audit_requests', ['email' => 'bot@example.com']);
    }

    public function test_validation_errors(): void
    {
        $this->postJson(route('audit-requests.store'), ['name' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'A', 'email' => 'a@example.com', 'repo_url' => 'not a url',
        ])->assertStatus(422)->assertJsonValidationErrors(['repo_url']);
    }

    public function test_duplicate_email_within_window_is_rejected(): void
    {
        AuditRequest::factory()->create(['email' => 'dup@example.com', 'created_at' => now()->subMinutes(2)]);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'Dup', 'email' => 'dup@example.com',
            'repo_url' => 'https://github.com/example/repo',
        ])->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run → FAIL (route not defined)**

Run: `php artisan test --compact --filter=AuditRequestControllerTest`

- [ ] **Step 3: FormRequest**

`backend/app/Http/Requests/StoreAuditRequestRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuditRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'repo_url' => ['nullable', 'url', 'max:2048'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'], // honeypot — humans never fill it
        ];
    }
}
```

- [ ] **Step 4: Job shell**

`backend/app/Jobs/GenerateAuditReport.php`:

```php
<?php

namespace App\Jobs;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAuditReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {
        $this->onQueue(config('audit.queue'));
    }

    public function handle(): void
    {
        // Implemented in the pipeline task.
    }
}
```

- [ ] **Step 5: Service**

`backend/app/Services/AuditRequestService.php`:

```php
<?php

namespace App\Services;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\AuditRequestReceived;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuditRequestService
{
    public function submit(array $data, array $meta = []): AuditRequest
    {
        $recentDuplicate = AuditRequest::query()
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentDuplicate) {
            throw new TooManyRequestsHttpException(600, __('We already received a request from this email. Give us a few minutes.'));
        }

        $auditRequest = AuditRequest::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'repo_url' => $data['repo_url'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => AuditRequestStatus::NEW->value,
            'meta' => $meta,
        ]);

        Mail::to($auditRequest->email)->send(new AuditRequestReceived($auditRequest));
        $this->notifyAdmin($auditRequest);

        if ($auditRequest->repo_url !== null) {
            $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);
            GenerateAuditReport::dispatch($auditRequest);
        } else {
            $this->markNeedsFollowup($auditRequest, 'No repository URL provided');
        }

        return $auditRequest;
    }

    public function markNeedsFollowup(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::NEEDS_FOLLOWUP->value,
            'failure_reason' => $reason,
        ]);

        Mail::to($auditRequest->email)->send(new AuditRepoAccessNeeded($auditRequest));
    }

    public function markFailed(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::FAILED->value,
            'failure_reason' => $reason,
        ]);

        Mail::to($auditRequest->email)->send(new AuditRequestFailed($auditRequest));
        $this->notifyAdmin($auditRequest);
    }

    private function notifyAdmin(AuditRequest $auditRequest): void
    {
        $adminEmail = config('audit.admin_email');

        if ($adminEmail) {
            Mail::to($adminEmail)->send(new NewAuditRequestAdminNotification($auditRequest));
        }
    }
}
```

- [ ] **Step 6: Controller + route**

`backend/app/Http/Controllers/AuditRequestController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuditRequestRequest;
use App\Services\AuditRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditRequestController extends Controller
{
    public function store(
        StoreAuditRequestRequest $request,
        AuditRequestService $auditRequestService,
    ): JsonResponse {
        $auditRequest = $auditRequestService->submit($request->validated(), [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return response()->json(['id' => $auditRequest->uuid], 201);
    }
}
```

In `backend/routes/api.php`, add before the webhook routes:

```php
use App\Http\Controllers\AuditRequestController;

Route::post('/audit-requests', [AuditRequestController::class, 'store'])
    ->name('audit-requests.store')
    ->middleware('throttle:5,1');
```

- [ ] **Step 7: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=AuditRequestControllerTest
git add backend/app/Http backend/app/Jobs backend/app/Services/AuditRequestService.php backend/routes/api.php backend/tests/Feature/Http/Controllers/AuditRequestControllerTest.php
git commit -m "feat(backend): audit request intake endpoint with honeypot, throttle, dedupe"
```

---

### Task 6: RepositoryCloner

**Files:**
- Create: `backend/app/Exceptions/AuditNotAnalyzableException.php`
- Create: `backend/app/Services/AuditReport/RepositoryCloner.php`
- Test: `backend/tests/Feature/Services/RepositoryClonerTest.php`

**Interfaces:**
- Produces: `RepositoryCloner::preflight(string $url): void` (throws `AuditNotAnalyzableException` on unreachable/private repos), `clone(string $url, string $uuid): string` (returns workdir path; throws on failure/oversize), `cleanup(string $uuid): void` (idempotent). Exception message is safe to store as `failure_reason`.

- [ ] **Step 1: Write the failing test (uses a local fixture git repo — no network)**

```php
<?php

namespace Tests\Feature\Services;

use App\Exceptions\AuditNotAnalyzableException;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class RepositoryClonerTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');

        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            File::put($this->fixtureRepo.'/index.php', "<?php\necho 'hi';\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    public function test_clones_a_reachable_repo_shallow(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-'.uniqid();

        $cloner->preflight('file://'.$this->fixtureRepo);
        $path = $cloner->clone('file://'.$this->fixtureRepo, $uuid);

        $this->assertFileExists($path.'/README.md');
        $log = Process::path($path)->run('git rev-list --count HEAD')->throw();
        $this->assertSame('1', trim($log->output())); // depth 1

        $cloner->cleanup($uuid);
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_preflight_rejects_unreachable_repo(): void
    {
        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->preflight('file:///nonexistent/definitely-not-a-repo');
    }

    public function test_cleanup_is_idempotent(): void
    {
        app(RepositoryCloner::class)->cleanup('never-existed');
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run → FAIL (class not found)**

Run: `php artisan test --compact --filter=RepositoryClonerTest`

- [ ] **Step 3: Implement**

`backend/app/Exceptions/AuditNotAnalyzableException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class AuditNotAnalyzableException extends Exception {}
```

`backend/app/Services/AuditReport/RepositoryCloner.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RepositoryCloner
{
    public function preflight(string $url): void
    {
        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', '--exit-code', $url, 'HEAD']);

        if (! $result->successful()) {
            throw new AuditNotAnalyzableException(
                'Repository is not publicly accessible: '.$url
            );
        }
    }

    public function clone(string $url, string $uuid): string
    {
        $path = $this->workdirPath($uuid);
        File::ensureDirectoryExists(dirname($path));

        $result = Process::timeout(config('audit.clone_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'clone', '--depth', '1', '--no-tags', '--single-branch', $url, $path]);

        if (! $result->successful()) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException('Repository could not be cloned: '.$url);
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

    public function cleanup(string $uuid): void
    {
        File::deleteDirectory($this->workdirPath($uuid));
    }

    private function workdirPath(string $uuid): string
    {
        return rtrim(config('audit.workdir'), '/').'/'.$uuid;
    }

    private function directorySizeMb(string $path): int
    {
        $result = Process::run(['du', '-sm', $path]);

        return (int) strtok(trim($result->output()), "\t ");
    }
}
```

- [ ] **Step 4: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=RepositoryClonerTest
git add backend/app/Exceptions/AuditNotAnalyzableException.php backend/app/Services/AuditReport/RepositoryCloner.php backend/tests/Feature/Services/RepositoryClonerTest.php
git commit -m "feat(backend): repository cloner with preflight, size limit, cleanup"
```

---

### Task 7: MetricsCollector

**Files:**
- Create: `backend/app/Services/AuditReport/MetricsCollector.php`
- Test: `backend/tests/Feature/Services/MetricsCollectorTest.php`

**Interfaces:**
- Produces: `MetricsCollector::collect(string $repoPath): array` returning `['metrics' => array, 'excerpts' => array<int, ['path' => string, 'content' => string]>]`. Metrics keys: `files_total`, `loc_total`, `languages` (ext → ['files','loc']), `largest_files` (top 20: path, loc), `duplication_pct`, `test_files`, `test_ratio_pct`, `has_ci`, `has_readme`, `manifests` (name → ['dependencies','dev_dependencies','lockfile']), `secret_findings` (pattern name → ['count','files']), `git` (['default_branch','last_commit_at']).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\MetricsCollector;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class MetricsCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = storage_path('framework/testing/metrics-fixture');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/src');
        File::ensureDirectoryExists($this->repo.'/tests');
        File::ensureDirectoryExists($this->repo.'/vendor/lib');
        File::ensureDirectoryExists($this->repo.'/.github/workflows');

        File::put($this->repo.'/README.md', "# Fixture\n");
        File::put($this->repo.'/src/a.php', "<?php\n".str_repeat("function f() { return 1; }\n", 30));
        File::put($this->repo.'/src/b.php', "<?php\n".str_repeat("function f() { return 1; }\n", 30)); // duplicate of a.php
        File::put($this->repo.'/src/c.js', "const key = 'x';\n".str_repeat("console.log(1);\n", 5));
        File::put($this->repo.'/src/leak.php', "<?php\n\$key = 'AKIAIOSFODNN7EXAMPLE';\n");
        File::put($this->repo.'/tests/aTest.php', "<?php\n// test\n");
        File::put($this->repo.'/vendor/lib/ignored.php', "<?php\n// must be skipped\n");
        File::put($this->repo.'/.github/workflows/ci.yml', "on: push\n");
        File::put($this->repo.'/composer.json', json_encode([
            'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ]));
    }

    public function test_collects_expected_metrics(): void
    {
        $result = app(MetricsCollector::class)->collect($this->repo);
        $metrics = $result['metrics'];

        $this->assertArrayHasKey('php', $metrics['languages']);
        $this->assertGreaterThan(0, $metrics['loc_total']);
        $this->assertGreaterThan(20, $metrics['duplication_pct']); // a.php duplicates b.php
        $this->assertSame(1, $metrics['test_files']);
        $this->assertTrue($metrics['has_ci']);
        $this->assertTrue($metrics['has_readme']);
        $this->assertSame(2, $metrics['manifests']['composer.json']['dependencies']);
        $this->assertSame(1, $metrics['manifests']['composer.json']['dev_dependencies']);
        $this->assertGreaterThanOrEqual(1, $metrics['secret_findings']['aws_access_key']['count']);
        $this->assertStringNotContainsString('AKIA', json_encode($metrics)); // never the value itself
    }

    public function test_excerpts_skip_vendor_and_respect_limits(): void
    {
        $result = app(MetricsCollector::class)->collect($this->repo);

        $paths = array_column($result['excerpts'], 'path');
        $this->assertNotEmpty($paths);
        $this->assertLessThanOrEqual(config('audit.max_excerpt_files'), count($paths));
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('vendor/', $path);
        }
        foreach ($result['excerpts'] as $excerpt) {
            $this->assertLessThanOrEqual(config('audit.max_excerpt_bytes'), strlen($excerpt['content']));
        }
    }
}
```

- [ ] **Step 2: Run → FAIL**

Run: `php artisan test --compact --filter=MetricsCollectorTest`

- [ ] **Step 3: Implement**

`backend/app/Services/AuditReport/MetricsCollector.php`:

```php
<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class MetricsCollector
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', 'dist', 'build', '.git', 'storage', 'public/build', '.next', 'coverage'];

    private const SOURCE_EXTENSIONS = ['php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'go', 'java', 'cs', 'vue', 'astro', 'blade.php', 'css', 'scss', 'html', 'sql', 'sh', 'yml', 'yaml', 'json'];

    private const SECRET_PATTERNS = [
        'aws_access_key' => '/AKIA[0-9A-Z]{16}/',
        'private_key_block' => '/-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'generic_api_key' => '/(api[_-]?key|secret[_-]?key|access[_-]?token)["\']?\s*[:=>]+\s*["\'][A-Za-z0-9_\-]{16,}["\']/i',
    ];

    public function collect(string $repoPath): array
    {
        $files = iterator_to_array($this->sourceFiles($repoPath), false);

        $languages = [];
        $fileStats = [];
        $lineHashes = [];
        $duplicateLines = 0;
        $totalHashedLines = 0;
        $secretFindings = [];

        foreach ($files as $file) {
            $content = $file->getContents();
            $loc = substr_count($content, "\n") + 1;
            $ext = strtolower($file->getExtension());
            $relative = $file->getRelativePathname();

            $languages[$ext]['files'] = ($languages[$ext]['files'] ?? 0) + 1;
            $languages[$ext]['loc'] = ($languages[$ext]['loc'] ?? 0) + $loc;
            $fileStats[] = ['path' => $relative, 'loc' => $loc, 'bytes' => $file->getSize()];

            foreach (explode("\n", $content) as $line) {
                $normalized = trim($line);
                if (strlen($normalized) < 12) {
                    continue;
                }
                $hash = md5($normalized);
                $totalHashedLines++;
                if (isset($lineHashes[$hash]) && $lineHashes[$hash] !== $relative) {
                    $duplicateLines++;
                } else {
                    $lineHashes[$hash] = $relative;
                }
            }

            foreach (self::SECRET_PATTERNS as $name => $pattern) {
                $count = preg_match_all($pattern, $content);
                if ($count > 0) {
                    $secretFindings[$name]['count'] = ($secretFindings[$name]['count'] ?? 0) + $count;
                    $secretFindings[$name]['files'][] = $relative;
                }
            }
        }

        usort($fileStats, fn ($a, $b) => $b['loc'] <=> $a['loc']);

        $testFiles = count(array_filter($fileStats, fn ($f) => preg_match('#(^|/)(tests?|spec|__tests__)/#i', $f['path']) || preg_match('/(Test|\.test|\.spec)\.[a-z]+$/i', $f['path'])));

        $metrics = [
            'files_total' => count($fileStats),
            'loc_total' => array_sum(array_column($fileStats, 'loc')),
            'languages' => $languages,
            'largest_files' => array_map(fn ($f) => ['path' => $f['path'], 'loc' => $f['loc']], array_slice($fileStats, 0, 20)),
            'duplication_pct' => $totalHashedLines > 0 ? round($duplicateLines / $totalHashedLines * 100, 1) : 0.0,
            'test_files' => $testFiles,
            'test_ratio_pct' => count($fileStats) > 0 ? round($testFiles / count($fileStats) * 100, 1) : 0.0,
            'has_ci' => is_dir($repoPath.'/.github/workflows') || file_exists($repoPath.'/.gitlab-ci.yml') || file_exists($repoPath.'/bitbucket-pipelines.yml'),
            'has_readme' => count(glob($repoPath.'/README*') ?: []) > 0,
            'manifests' => $this->manifests($repoPath),
            'secret_findings' => array_map(fn ($f) => ['count' => $f['count'], 'files' => array_values(array_unique($f['files']))], $secretFindings),
            'git' => $this->gitInfo($repoPath),
        ];

        return [
            'metrics' => $metrics,
            'excerpts' => $this->excerpts($repoPath, $fileStats),
        ];
    }

    /** @return iterable<SplFileInfo> */
    private function sourceFiles(string $repoPath): iterable
    {
        return (new Finder)
            ->files()
            ->in($repoPath)
            ->exclude(self::EXCLUDED_DIRS)
            ->ignoreDotFiles(false)
            ->size('< 2M')
            ->name(array_map(fn ($ext) => '*.'.$ext, self::SOURCE_EXTENSIONS));
    }

    private function manifests(string $repoPath): array
    {
        $manifests = [];

        foreach (['composer.json' => 'composer.lock', 'package.json' => 'package-lock.json'] as $manifest => $lock) {
            if (! file_exists($repoPath.'/'.$manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($repoPath.'/'.$manifest), true) ?? [];
            $manifests[$manifest] = [
                'dependencies' => count($data['require'] ?? $data['dependencies'] ?? []),
                'dev_dependencies' => count($data['require-dev'] ?? $data['devDependencies'] ?? []),
                'lockfile' => file_exists($repoPath.'/'.$lock),
            ];
        }

        return $manifests;
    }

    private function gitInfo(string $repoPath): array
    {
        $branch = Process::path($repoPath)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $lastCommit = Process::path($repoPath)->run(['git', 'log', '-1', '--format=%cI']);

        return [
            'default_branch' => trim($branch->output()) ?: null,
            'last_commit_at' => trim($lastCommit->output()) ?: null,
        ];
    }

    private function excerpts(string $repoPath, array $fileStats): array
    {
        $maxFiles = config('audit.max_excerpt_files');
        $maxBytes = config('audit.max_excerpt_bytes');
        $excerpts = [];

        foreach (array_slice($fileStats, 0, $maxFiles) as $file) {
            $content = (string) file_get_contents($repoPath.'/'.$file['path'], length: $maxBytes);
            $excerpts[] = ['path' => $file['path'], 'content' => $content];
        }

        return $excerpts;
    }
}
```

Note: `file_get_contents(..., length: $maxBytes)` needs positional nulls before `length` in PHP — use `file_get_contents($path, false, null, 0, $maxBytes)` if the named-argument form errors.

- [ ] **Step 4: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=MetricsCollectorTest
git add backend/app/Services/AuditReport/MetricsCollector.php backend/tests/Feature/Services/MetricsCollectorTest.php
git commit -m "feat(backend): static metrics collector with duplication and secret heuristics"
```

---

### Task 8: AI analyzer (Claude, structured outputs) + fake

**Files:**
- Create: `backend/app/Services/AuditReport/AiAnalyzer.php` (interface)
- Create: `backend/app/Services/AuditReport/ClaudeAnalyzer.php`
- Create: `backend/app/Services/AuditReport/ReportPayload.php` (defensive validation)
- Create: `backend/app/Exceptions/AiAnalysisException.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (bind interface)
- Create: `backend/tests/Support/FakeAiAnalyzer.php`
- Test: `backend/tests/Unit/ReportPayloadTest.php`

**Interfaces:**
- Produces: `AiAnalyzer::analyze(array $metrics, array $excerpts): array` (returns the payload shape stored in `audit_reports.payload`: `summary`, `scores{structure,duplication,testing,dependencies,security_hygiene,overall}`, `risks[{title,impact,evidence,recommendation}]`, `fix_first_plan[{step,why,effort}]`), throws `AiAnalysisException`. `Tests\Support\FakeAiAnalyzer` used by Tasks 10-12 tests.

- [ ] **Step 1: Write the failing test for payload validation**

`backend/tests/Unit/ReportPayloadTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\ReportPayload;
use PHPUnit\Framework\TestCase;

class ReportPayloadTest extends TestCase
{
    private function valid(): array
    {
        return [
            'summary' => 'ok',
            'scores' => ['structure' => 1, 'duplication' => 2, 'testing' => 3, 'dependencies' => 4, 'security_hygiene' => 5, 'overall' => 3],
            'risks' => [['title' => 't', 'impact' => 'high', 'evidence' => 'e', 'recommendation' => 'r']],
            'fix_first_plan' => [['step' => 's', 'why' => 'w', 'effort' => 'S']],
        ];
    }

    public function test_accepts_valid_payload(): void
    {
        $this->assertSame($this->valid(), ReportPayload::validate($this->valid()));
    }

    public function test_rejects_missing_scores(): void
    {
        $payload = $this->valid();
        unset($payload['scores']['overall']);

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload);
    }

    public function test_rejects_bad_impact(): void
    {
        $payload = $this->valid();
        $payload['risks'][0]['impact'] = 'catastrophic';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload);
    }
}
```

- [ ] **Step 2: Run → FAIL**

Run: `php artisan test --compact --filter=ReportPayloadTest`

- [ ] **Step 3: Implement**

`backend/app/Exceptions/AiAnalysisException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class AiAnalysisException extends Exception {}
```

`backend/app/Services/AuditReport/AiAnalyzer.php`:

```php
<?php

namespace App\Services\AuditReport;

interface AiAnalyzer
{
    /**
     * @param  array  $metrics  output of MetricsCollector ['metrics']
     * @param  array<int, array{path: string, content: string}>  $excerpts
     * @return array validated report payload (see ReportPayload)
     *
     * @throws \App\Exceptions\AiAnalysisException
     */
    public function analyze(array $metrics, array $excerpts): array;
}
```

`backend/app/Services/AuditReport/ReportPayload.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;

class ReportPayload
{
    public static function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new AiAnalysisException('Analysis payload is not an object');
        }

        if (! is_string($payload['summary'] ?? null)) {
            throw new AiAnalysisException('Missing summary');
        }

        $scores = $payload['scores'] ?? null;
        foreach (['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'] as $key) {
            if (! is_int($scores[$key] ?? null)) {
                throw new AiAnalysisException("Missing or non-integer score: {$key}");
            }
        }

        foreach ($payload['risks'] ?? [] as $risk) {
            if (! in_array($risk['impact'] ?? null, ['high', 'medium', 'low'], true)
                || ! is_string($risk['title'] ?? null)
                || ! is_string($risk['evidence'] ?? null)
                || ! is_string($risk['recommendation'] ?? null)) {
                throw new AiAnalysisException('Malformed risk entry');
            }
        }
        if (! is_array($payload['risks'] ?? null)) {
            throw new AiAnalysisException('Missing risks');
        }

        foreach ($payload['fix_first_plan'] ?? [] as $step) {
            if (! is_string($step['step'] ?? null)
                || ! is_string($step['why'] ?? null)
                || ! in_array($step['effort'] ?? null, ['S', 'M', 'L'], true)) {
                throw new AiAnalysisException('Malformed fix_first_plan entry');
            }
        }
        if (! is_array($payload['fix_first_plan'] ?? null)) {
            throw new AiAnalysisException('Missing fix_first_plan');
        }

        return $payload;
    }
}
```

`backend/app/Services/AuditReport/ClaudeAnalyzer.php` (official PHP SDK; structured outputs via `outputConfig` guarantee schema-valid JSON, so no corrective-retry loop is needed — `ReportPayload::validate` stays as a cheap safety net):

```php
<?php

namespace App\Services\AuditReport;

use Anthropic\Client;
use App\Exceptions\AiAnalysisException;

class ClaudeAnalyzer implements AiAnalyzer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor producing a codebase health report for a prospective client.
You are given repository metrics measured by static analysis, plus excerpts of the largest files.
Ground every score, risk, and recommendation in the provided metrics and excerpts — never invent
facts about code you have not seen. Frame findings as assessment based on automated analysis,
not guarantees. Scores are 0-100 (higher is healthier). Rank risks by impact. The fix-first plan
must be concrete and ordered by leverage.
PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'scores' => [
                'type' => 'object',
                'properties' => [
                    'structure' => ['type' => 'integer'],
                    'duplication' => ['type' => 'integer'],
                    'testing' => ['type' => 'integer'],
                    'dependencies' => ['type' => 'integer'],
                    'security_hygiene' => ['type' => 'integer'],
                    'overall' => ['type' => 'integer'],
                ],
                'required' => ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'],
                'additionalProperties' => false,
            ],
            'risks' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'impact' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'evidence' => ['type' => 'string'],
                        'recommendation' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'impact', 'evidence', 'recommendation'],
                    'additionalProperties' => false,
                ],
            ],
            'fix_first_plan' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'step' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                    ],
                    'required' => ['step', 'why', 'effort'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['summary', 'scores', 'risks', 'fix_first_plan'],
        'additionalProperties' => false,
    ];

    public function analyze(array $metrics, array $excerpts): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 16000,
            thinking: ['type' => 'adaptive'],
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $this->buildPrompt($metrics, $excerpts)]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new AiAnalysisException('Analysis stopped early: '.$message->stopReason);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return ReportPayload::validate(json_decode($block->text, true));
            }
        }

        throw new AiAnalysisException('Analysis returned no text content');
    }

    private function buildPrompt(array $metrics, array $excerpts): string
    {
        $excerptText = '';
        foreach ($excerpts as $excerpt) {
            $excerptText .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        return "Repository metrics (JSON):\n"
            .json_encode($metrics, JSON_PRETTY_PRINT)
            ."\n\nFile excerpts (largest files, truncated):\n"
            .$excerptText
            ."\n\nProduce the codebase health report.";
    }
}
```

Bind in `backend/app/Providers/AppServiceProvider.php` `register()`:

```php
$this->app->bind(\App\Services\AuditReport\AiAnalyzer::class, \App\Services\AuditReport\ClaudeAnalyzer::class);
```

`backend/tests/Support/FakeAiAnalyzer.php`:

```php
<?php

namespace Tests\Support;

use App\Services\AuditReport\AiAnalyzer;

class FakeAiAnalyzer implements AiAnalyzer
{
    public ?array $receivedMetrics = null;

    public function __construct(
        public ?\Throwable $throws = null,
    ) {}

    public function analyze(array $metrics, array $excerpts): array
    {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;

        return [
            'summary' => 'Fake analysis summary.',
            'scores' => ['structure' => 62, 'duplication' => 40, 'testing' => 15, 'dependencies' => 70, 'security_hygiene' => 55, 'overall' => 48],
            'risks' => [['title' => 'Low test coverage', 'impact' => 'high', 'evidence' => 'test ratio', 'recommendation' => 'Add smoke tests']],
            'fix_first_plan' => [['step' => 'Set up CI', 'why' => 'Catch regressions early', 'effort' => 'S']],
        ];
    }
}
```

Check `backend/composer.json` `autoload-dev` includes `"Tests\\": "tests/"` (Laravel default) so `Tests\Support` autoloads.

- [ ] **Step 4: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=ReportPayloadTest
git add backend/app/Services/AuditReport backend/app/Exceptions/AiAnalysisException.php backend/app/Providers/AppServiceProvider.php backend/tests/Support backend/tests/Unit/ReportPayloadTest.php
git commit -m "feat(backend): Claude analyzer with structured outputs + fake for tests"
```

---

### Task 9: Report rendering + signed web copy

**Files:**
- Create: `backend/resources/views/reports/audit.blade.php`, `backend/resources/views/reports/link-expired.blade.php`
- Create: `backend/app/Services/AuditReport/AuditReportService.php`
- Create: `backend/app/Http/Controllers/AuditReportController.php`
- Modify: `backend/routes/web.php` (report routes), `backend/bootstrap/app.php` (friendly 403 for expired signatures)
- Test: `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php`

**Interfaces:**
- Consumes: models (T2), `AuditReportReady` mailable (T4).
- Produces: `AuditReportService::create(AuditRequest, array $payload): AuditReport` (links user by email, renders + stores PDF, sets `report_ready`), `send(AuditReport): void` (mails signed URL + attachment, sets `sent`), `signedUrl(AuditReport): string`. Routes: `GET /reports/{auditReport:uuid}` (name `reports.view`, `signed`), `GET /reports/{auditReport:uuid}/download` (name `reports.download`, `auth` + ownership).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportControllerTest extends FeatureTest
{
    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }

    public function test_create_renders_pdf_and_links_existing_user(): void
    {
        $user = $this->createUser();
        $request = AuditRequest::factory()->create(['email' => $user->email]);

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertSame($user->id, $report->user_id);
        $this->assertSame(AuditRequestStatus::REPORT_READY->value, $request->fresh()->status);
        Storage::disk('local')->assertExists($report->pdf_path);
    }

    public function test_send_mails_report_and_marks_sent(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create();
        $service = app(AuditReportService::class);
        $report = $service->create($request, $this->payload());

        $service->send($report);

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        Mail::assertQueued(AuditReportReady::class, fn ($mail) => $mail->hasTo($request->email));
    }

    public function test_signed_url_shows_web_report(): void
    {
        $report = AuditReport::factory()->create();

        $url = app(AuditReportService::class)->signedUrl($report);
        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertSee('Fixture summary.');
    }

    public function test_unsigned_url_is_rejected_with_friendly_page(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->create();

        $response = $this->get(route('reports.view', $report));

        $response->assertStatus(403);
        $response->assertSee(__('This report link has expired'));
    }

    public function test_download_requires_ownership(): void
    {
        $this->withExceptionHandling();
        Storage::disk('local')->put('audit-reports/owned.pdf', '%PDF-1.4');
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $report = AuditReport::factory()->create(['user_id' => $owner->id, 'pdf_path' => 'audit-reports/owned.pdf']);

        $this->actingAs($stranger)->get(route('reports.download', $report))->assertStatus(403);
        $this->actingAs($owner)->get(route('reports.download', $report))->assertStatus(200);
    }
}
```

- [ ] **Step 2: Run → FAIL**

Run: `php artisan test --compact --filter=AuditReportControllerTest`

- [ ] **Step 3: Report view (shared by web + PDF — self-contained inline styles, dompdf-safe: no flexbox/grid)**

`backend/resources/views/reports/audit.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Codebase Health Report') }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; margin: 32px; font-size: 13px; }
        h1 { font-size: 22px; margin-bottom: 2px; }
        h2 { font-size: 15px; margin-top: 26px; border-bottom: 1px solid #d6d3d1; padding-bottom: 4px; }
        .muted { color: #78716c; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
        .impact-high { color: #b91c1c; font-weight: bold; }
        .impact-medium { color: #b45309; font-weight: bold; }
        .impact-low { color: #4d7c0f; font-weight: bold; }
        .score { font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>
    @php($payload = $report->payload)

    <h1>{{ __('Codebase Health Report') }}</h1>
    <p class="muted">
        {{ $report->auditRequest->repo_url }} ·
        {{ __('Generated :date by FlexPick automated analysis', ['date' => $report->created_at->format('Y-m-d')]) }}
    </p>

    <h2>{{ __('Summary') }}</h2>
    <p>{{ $payload['summary'] }}</p>

    <h2>{{ __('Health scores') }} <span class="muted">(0–100, {{ __('higher is healthier') }})</span></h2>
    <table>
        <tr>
            @foreach ($payload['scores'] as $dimension => $score)
                <th>{{ str_replace('_', ' ', $dimension) }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($payload['scores'] as $score)
                <td class="score">{{ $score }}</td>
            @endforeach
        </tr>
    </table>

    <h2>{{ __('Risks, ranked by impact') }}</h2>
    <table>
        <tr><th>{{ __('Risk') }}</th><th>{{ __('Impact') }}</th><th>{{ __('Evidence') }}</th><th>{{ __('Recommendation') }}</th></tr>
        @foreach (collect($payload['risks'])->sortBy(fn ($r) => array_search($r['impact'], ['high', 'medium', 'low'])) as $risk)
            <tr>
                <td>{{ $risk['title'] }}</td>
                <td class="impact-{{ $risk['impact'] }}">{{ strtoupper($risk['impact']) }}</td>
                <td>{{ $risk['evidence'] }}</td>
                <td>{{ $risk['recommendation'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('What to fix first') }}</h2>
    <table>
        <tr><th>#</th><th>{{ __('Step') }}</th><th>{{ __('Why') }}</th><th>{{ __('Effort') }}</th></tr>
        @foreach ($payload['fix_first_plan'] as $i => $step)
            <tr><td>{{ $i + 1 }}</td><td>{{ $step['step'] }}</td><td>{{ $step['why'] }}</td><td>{{ $step['effort'] }}</td></tr>
        @endforeach
    </table>

    <p class="muted" style="margin-top: 28px;">
        {{ __('Scores and findings are derived from automated static analysis of the repository at the time of generation. Reply to your report email to discuss any finding with an engineer.') }}
    </p>
</body>
</html>
```

`backend/resources/views/reports/link-expired.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Link expired') }}</title></head>
<body style="font-family: sans-serif; margin: 64px auto; max-width: 480px; text-align: center;">
    <h1>{{ __('This report link has expired') }}</h1>
    <p>{{ __('Report links are valid for :days days. Reply to your report email and we\'ll send you a fresh one.', ['days' => config('audit.report_link_days')]) }}</p>
</body>
</html>
```

- [ ] **Step 4: Service**

`backend/app/Services/AuditReport/AuditReportService.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AuditReportService
{
    public function create(AuditRequest $auditRequest, array $payload): AuditReport
    {
        $report = new AuditReport([
            'audit_request_id' => $auditRequest->id,
            'user_id' => User::where('email', $auditRequest->email)->value('id'),
            'payload' => $payload,
            'pdf_path' => 'pending',
        ]);
        $report->save();

        $pdfPath = config('audit.reports_dir').'/'.$report->uuid.'.pdf';
        $pdf = Pdf::loadView('reports.audit', ['report' => $report]);
        Storage::disk('local')->put($pdfPath, $pdf->output());

        $report->update(['pdf_path' => $pdfPath]);
        $auditRequest->update(['status' => AuditRequestStatus::REPORT_READY->value]);

        return $report;
    }

    public function send(AuditReport $report): void
    {
        Mail::to($report->auditRequest->email)
            ->send(new AuditReportReady($report, $this->signedUrl($report)));

        $report->auditRequest->update(['status' => AuditRequestStatus::SENT->value]);
    }

    public function signedUrl(AuditReport $report): string
    {
        return URL::temporarySignedRoute(
            'reports.view',
            now()->addDays((int) config('audit.report_link_days')),
            ['auditReport' => $report->uuid],
        );
    }
}
```

- [ ] **Step 5: Controller, routes, friendly 403**

`backend/app/Http/Controllers/AuditReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use Illuminate\Support\Facades\Storage;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport)
    {
        return view('reports.audit', ['report' => $auditReport]);
    }

    public function download(AuditReport $auditReport)
    {
        abort_unless($auditReport->user_id === auth()->id(), 403);

        return Storage::disk('local')->download($auditReport->pdf_path, 'codebase-health-report.pdf');
    }
}
```

In `backend/routes/web.php` (add near the invoice routes, with the import at the top):

```php
use App\Http\Controllers\AuditReportController;

Route::get('/reports/{auditReport:uuid}', [AuditReportController::class, 'show'])
    ->name('reports.view')
    ->middleware('signed');

Route::get('/reports/{auditReport:uuid}/download', [AuditReportController::class, 'download'])
    ->name('reports.download')
    ->middleware('auth');
```

In `backend/bootstrap/app.php`, fill the empty `withExceptions` closure:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, \Illuminate\Http\Request $request) {
        if ($request->routeIs('reports.view')) {
            return response()->view('reports.link-expired', [], 403);
        }
    });
})->create();
```

- [ ] **Step 6: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=AuditReportControllerTest
git add backend/app/Services/AuditReport/AuditReportService.php backend/app/Http/Controllers/AuditReportController.php backend/resources/views/reports backend/routes/web.php backend/bootstrap/app.php backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(backend): report PDF rendering, signed web copy, owner download"
```

---

### Task 10: Pipeline orchestration

**Files:**
- Create: `backend/app/Services/AuditReport/AuditPipeline.php`
- Modify: `backend/app/Jobs/GenerateAuditReport.php` (fill `handle()` + `failed()`)
- Test: `backend/tests/Feature/Services/AuditPipelineTest.php`

**Interfaces:**
- Consumes: everything from Tasks 5-9.
- Produces: `AuditPipeline::run(AuditRequest): void` — the end-to-end flow. `GenerateAuditReport` becomes functional.

- [ ] **Step 1: Write the failing tests (fixture repo + FakeAiAnalyzer, real render)**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AiAnalysisException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestFailed;
use App\Models\AuditRequest;
use App\Services\AuditReport\AiAnalyzer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeAiAnalyzer;

class AuditPipelineTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');

        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            File::put($this->fixtureRepo.'/index.php', "<?php\necho 'hi';\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    public function test_happy_path_produces_and_sends_report(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(\App\Services\AuditReport\AuditPipeline::class));

        $request->refresh();
        $this->assertSame(AuditRequestStatus::SENT->value, $request->status);
        $this->assertNotNull($request->report);
        $this->assertNotNull($request->metrics);
        Storage::disk('local')->assertExists($request->report->pdf_path);
        Mail::assertQueued(AuditReportReady::class, fn ($mail) => $mail->hasTo($request->email));
        $this->assertDirectoryDoesNotExist(config('audit.workdir').'/'.$request->uuid); // workdir cleaned
    }

    public function test_inaccessible_repo_goes_to_followup(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file:///nonexistent/nope',
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(\App\Services\AuditReport\AuditPipeline::class));

        $this->assertSame(AuditRequestStatus::NEEDS_FOLLOWUP->value, $request->fresh()->status);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }

    public function test_ai_failure_marks_failed_and_notifies(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer(throws: new AiAnalysisException('boom')));
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        $job = new GenerateAuditReport($request);
        try {
            $job->handle(app(\App\Services\AuditReport\AuditPipeline::class));
            $this->fail('Expected AiAnalysisException');
        } catch (AiAnalysisException) {
            $job->failed(new AiAnalysisException('boom')); // what the queue worker would do
        }

        $this->assertSame(AuditRequestStatus::FAILED->value, $request->fresh()->status);
        $this->assertSame('boom', $request->fresh()->failure_reason);
        Mail::assertQueued(AuditRequestFailed::class);
        $this->assertDirectoryDoesNotExist(config('audit.workdir').'/'.$request->uuid); // cleanup ran in finally
    }
}
```

- [ ] **Step 2: Run → FAIL**

Run: `php artisan test --compact --filter=AuditPipelineTest`

- [ ] **Step 3: Implement the pipeline**

`backend/app/Services/AuditReport/AuditPipeline.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;

class AuditPipeline
{
    public function __construct(
        private RepositoryCloner $cloner,
        private MetricsCollector $metricsCollector,
        private AiAnalyzer $analyzer,
        private AuditReportService $reportService,
        private AuditRequestService $requestService,
    ) {}

    public function run(AuditRequest $auditRequest): void
    {
        $auditRequest->update(['status' => AuditRequestStatus::ANALYZING->value]);

        try {
            $this->cloner->preflight($auditRequest->repo_url);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid);

            $collected = $this->metricsCollector->collect($path);
            $auditRequest->update(['metrics' => $collected['metrics']]);

            $payload = $this->analyzer->analyze($collected['metrics'], $collected['excerpts']);

            $report = $this->reportService->create($auditRequest, $payload);
            $this->reportService->send($report);
        } catch (AuditNotAnalyzableException $e) {
            $this->requestService->markNeedsFollowup($auditRequest, $e->getMessage());
        } finally {
            $this->cloner->cleanup($auditRequest->uuid);
        }
    }
}
```

Update `backend/app/Jobs/GenerateAuditReport.php` — replace the placeholder `handle()` and add `failed()`:

```php
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditRequestService;
use Throwable;

// ...

    public function handle(AuditPipeline $pipeline): void
    {
        $pipeline->run($this->auditRequest);
    }

    public function failed(?Throwable $exception): void
    {
        app(AuditRequestService::class)->markFailed(
            $this->auditRequest,
            $exception?->getMessage() ?? 'Unknown pipeline failure',
        );
    }
```

(Any exception other than `AuditNotAnalyzableException` — clone races, AI errors, PDF failures — escapes `run()`, fails the job once (`tries = 1`), and the queue worker invokes `failed()` → soft client email + admin notification, matching the spec.)

- [ ] **Step 4: Run tests → PASS, then the full suite, then commit**

```bash
php artisan test --compact --filter=AuditPipelineTest
php artisan test --compact
git add backend/app/Services/AuditReport/AuditPipeline.php backend/app/Jobs/GenerateAuditReport.php backend/tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(backend): end-to-end audit pipeline job with followup/failure branches"
```

---

### Task 11: User linking on registration + dashboard page

**Files:**
- Create: `backend/app/Listeners/User/LinkAuditReportsToUser.php`
- Create: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Create: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Test: `backend/tests/Feature/Listeners/LinkAuditReportsToUserTest.php`

**Interfaces:**
- Consumes: `AuditReport` (T2), `AuditReportService::signedUrl` (T9), `Illuminate\Auth\Events\Registered` (auto-discovered listener — no registration needed).
- Produces: reports auto-link on registration; a Dashboard-panel page "Audit reports" listing the current user's reports.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Listeners;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Auth\Events\Registered;
use Tests\Feature\FeatureTest;

class LinkAuditReportsToUserTest extends FeatureTest
{
    public function test_reports_matching_email_are_linked_on_registration(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'newuser@example.com']);
        $report = AuditReport::factory()->create(['audit_request_id' => $request->id, 'user_id' => null]);
        $other = AuditReport::factory()->create(['user_id' => null]); // different email

        $user = $this->createUser();
        $user->update(['email' => 'newuser@example.com']);

        event(new Registered($user));

        $this->assertSame($user->id, $report->fresh()->user_id);
        $this->assertNull($other->fresh()->user_id);
    }
}
```

- [ ] **Step 2: Run → FAIL (listener missing, no linking happens)**

Run: `php artisan test --compact --filter=LinkAuditReportsToUserTest`
Expected: FAIL on the first assertion (`user_id` still null).

- [ ] **Step 3: Listener (auto-discovered via the `handle()` type-hint, like `CreateTenantIfNeeded`)**

`backend/app/Listeners/User/LinkAuditReportsToUser.php`:

```php
<?php

namespace App\Listeners\User;

use App\Models\AuditReport;
use Illuminate\Auth\Events\Registered;

class LinkAuditReportsToUser
{
    public function handle(Registered $event): void
    {
        AuditReport::query()
            ->whereNull('user_id')
            ->whereHas('auditRequest', fn ($query) => $query->where('email', $event->user->email))
            ->update(['user_id' => $event->user->getAuthIdentifier()]);
    }
}
```

- [ ] **Step 4: Dashboard page**

`backend/app/Filament/Dashboard/Pages/AuditReports.php` (mirrors `TenantSettings` shape):

```php
<?php

namespace App\Filament\Dashboard\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

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

    public function getViewData(): array
    {
        return [
            'reports' => auth()->user()->auditReports()->with('auditRequest')->latest()->get(),
        ];
    }
}
```

`backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`:

```blade
<x-filament-panels::page>
    <div class="space-y-4">
        @forelse ($reports as $report)
            <div class="rounded-lg border p-4">
                <div class="font-medium">{{ $report->auditRequest->repo_url ?? __('Repository audit') }}</div>
                <div class="text-sm text-gray-500">{{ $report->created_at->format(config('app.datetime_format', 'd/m/Y H:i')) }}</div>
                <div class="mt-2 flex gap-4">
                    <a class="text-primary-600 underline" href="{{ route('reports.download', $report) }}">{{ __('Download PDF') }}</a>
                    <a class="text-primary-600 underline" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">{{ __('View online') }}</a>
                </div>
            </div>
        @empty
            <p>{{ __('No audit reports yet.') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
```

(Filament auto-discovers Dashboard pages via the panel provider's `discoverPages`. The page is tenant-scoped by URL like every Dashboard page but lists user-owned reports; check the Blade component name `<x-filament-panels::page>` against another page view under `resources/views/filament/dashboard/pages/` and match whatever wrapper that file uses.)

- [ ] **Step 5: Run tests → PASS, then commit**

```bash
php artisan test --compact --filter=LinkAuditReportsToUserTest
git add backend/app/Listeners/User/LinkAuditReportsToUser.php backend/app/Filament/Dashboard/Pages/AuditReports.php backend/resources/views/filament/dashboard/pages/audit-reports.blade.php backend/tests/Feature/Listeners
git commit -m "feat(backend): link reports to users on registration + dashboard reports page"
```

---

### Task 12: Filament admin resource (observability + retry)

**Files:**
- Create: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`
- Create: `backend/app/Filament/Admin/Resources/AuditRequests/Pages/ListAuditRequests.php`, `.../Pages/ViewAuditRequest.php`
- Test: `backend/tests/Feature/Filament/Admin/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: enum + mapper (T1), model (T2), job (T5/T10), `AuditRequestService` (T5).
- Produces: read-mostly admin resource with status badges, a **Retry pipeline** action (repo_url present, status failed/needs_followup/report_ready) and **Mark handled** (status needs_followup → handled).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    public function test_admin_can_list_audit_requests(): void
    {
        $admin = $this->createAdminUser();
        AuditRequest::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get(AuditRequestResource::getUrl('index', panel: 'admin'));

        $response->assertStatus(200);
    }
}
```

(Look at an existing test under `tests/Feature/Filament/` first — if the codebase pattern passes the panel differently, e.g. `AuditRequestResource::getUrl('index')` with Filament's current-panel helper, copy that pattern.)

- [ ] **Step 2: Run → FAIL**

Run: `php artisan test --compact --filter=AuditRequestResourceTest`

- [ ] **Step 3: Implement the resource (copy Filament v5 conventions from `OrderResource` — verify imports/section shapes against it while writing)**

`backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Jobs\GenerateAuditReport;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditRequestResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Audit Request');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Request'))->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('repo_url'),
                TextEntry::make('message'),
                TextEntry::make('status'),
                TextEntry::make('failure_reason'),
                TextEntry::make('report.uuid')->label(__('Report')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('repo_url')->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper) => $mapper->mapForDisplay($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')
                    ->label(__('Retry pipeline'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::FAILED->value,
                        AuditRequestStatus::NEEDS_FOLLOWUP->value,
                        AuditRequestStatus::REPORT_READY->value,
                    ], true))
                    ->action(function (AuditRequest $record): void {
                        $record->update(['status' => AuditRequestStatus::QUEUED->value, 'failure_reason' => null]);
                        GenerateAuditReport::dispatch($record);
                    }),
                Action::make('markHandled')
                    ->label(__('Mark handled'))
                    ->visible(fn (AuditRequest $record): bool => $record->status === AuditRequestStatus::NEEDS_FOLLOWUP->value)
                    ->action(fn (AuditRequest $record) => $record->update(['status' => AuditRequestStatus::HANDLED->value])),
            ]);
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

`Pages/ListAuditRequests.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;

class ListAuditRequests extends ListRecords
{
    use ListDefaults;

    protected static string $resource = AuditRequestResource::class;
}
```

`Pages/ViewAuditRequest.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;
}
```

If Filament import paths differ (v5 moves things), open `app/Filament/Admin/Resources/Orders/OrderResource.php` and mirror its exact `use` statements for `Schema`, `Section`, `TextEntry`, `Table`, actions, and any `$navigationGroup`/method conventions.

- [ ] **Step 4: Run test → PASS, then commit**

```bash
php artisan test --compact --filter=AuditRequestResourceTest
git add backend/app/Filament/Admin/Resources/AuditRequests backend/tests/Feature/Filament/Admin/AuditRequestResourceTest.php
git commit -m "feat(backend): Filament admin resource for audit requests with retry action"
```

---

### Task 13: Frontend — wire the ContactModal to the endpoint

**Files:**
- Modify: `frontend/src/components/widgets/ContactModal.astro` (frontmatter, form markup, submit handler lines 146-166)

**Interfaces:**
- Consumes: `PRODUCT_APP` from `flexpick:config` (Plan 1 Task 1 — must be merged first) and the Task 5 endpoint.
- Produces: real submissions; success keeps the existing "sent" state; failure shows an inline error with the form intact.

- [ ] **Step 1: Frontmatter — replace the stub comment**

Replace lines 1-4 of `ContactModal.astro`:

```astro
---
// Audit request modal — matches the "Stabilize" landing redesign.
// Submits to the backend audit-requests endpoint (see PRODUCT_APP config).
import { PRODUCT_APP } from 'flexpick:config';
---
```

- [ ] **Step 2: Markup — endpoint attribute, honeypot, error element**

Change the form open tag (line ~28) to carry the endpoint:

```html
<form class="fp-form" novalidate data-modal-form-el data-endpoint={`${PRODUCT_APP.url}/api/audit-requests`}>
```

Immediately after the form open tag, add the honeypot (bots fill it; the API rejects with 422):

```html
<input
  type="text"
  name="website"
  tabindex="-1"
  autocomplete="off"
  aria-hidden="true"
  style="position: absolute; left: -9999px; height: 0; width: 0; opacity: 0;"
/>
```

Before the submit button, add the error element:

```html
<p class="fp-mono" data-modal-error hidden style="color: #dc6b5a; font-size: 12px; margin: 0 0 10px;">
  Something went wrong — please try again, or email hello@flexpick.net.
</p>
```

- [ ] **Step 3: Replace the localStorage stub (lines 146-166) with a real submit**

Replace the `// Submit → localStorage stub` block with:

```ts
    // Submit → backend audit-requests endpoint
    const errorEl = modal.querySelector('[data-modal-error]') as HTMLElement;
    const submitBtn = form.querySelector('button[type="submit"]') as HTMLButtonElement;

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
          }),
        });
        if (!res.ok) throw new Error(String(res.status));
        formWrap.hidden = true;
        sentWrap.hidden = false;
      } catch {
        errorEl.hidden = false;
      } finally {
        submitBtn.disabled = false;
      }
    });
```

- [ ] **Step 4: Verify**

```bash
cd frontend && npm run check
```

Expected: PASS. Then manual end-to-end (requires the Docker environment + Horizon running and Plan 1 merged):

1. `docker compose up -d` at repo root; `docker compose exec laravel.test php artisan horizon` in a second terminal.
2. Open `http://localhost:4321`, click "Get a free audit", submit with a small public repo URL.
3. Modal flips to "Request received"; watch Horizon process the `audit` queue; open Mailpit (`http://localhost:8025`) — confirmation email, then the report email with PDF attachment and a working "View your report" link.
4. Submit again with the same email within 10 minutes → inline error shows (429).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/widgets/ContactModal.astro
git commit -m "feat(frontend): wire audit modal to the backend intake endpoint"
```

---

## Final verification

- [ ] `cd backend && php artisan test --compact` — full suite green.
- [ ] `cd backend && vendor/bin/phpstan analyse` — no new errors; `vendor/bin/pint --dirty` clean.
- [ ] `cd frontend && npm run check` — green.
- [ ] Manual Docker run per Task 13 Step 4, including one real Claude call (set `ANTHROPIC_API_KEY` in `backend/.env`) against a small public repo to validate the live `ClaudeAnalyzer` path end-to-end.
- [ ] Grep guardrails: `grep -rn "composer install\|npm install\|npm ci" backend/app/Services/AuditReport/` returns nothing (cloned code never executed).
