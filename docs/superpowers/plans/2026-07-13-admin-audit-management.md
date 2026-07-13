# Admin Audit Management Implementation Plan (Workstream A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins edit audit requests (status, input data, per-audit context), manage the global analysis prompt template, override generated results, see processing logs/timing, and monitor audit stats on the admin dashboard.

**Architecture:** Four new columns on `audit_requests` (`admin_context`, `pipeline_log`, `analysis_started_at`, `analysis_completed_at`); a `PromptComposer` service that resolves the template (ConfigService override or built-in default) and composes the final prompt — used by `ClaudeAnalyzer` and the admin prompt preview; an Edit page on the existing admin `AuditRequestResource`; an Audit Settings page following the `ReferralSettings` Livewire pattern; auto-discovered admin widgets.

**Tech Stack:** Laravel 13, Filament 5 (Schemas API), Livewire 4, `ConfigService` settings persistence, Redis queue depth via `Queue::connection('redis-audit')->size()`.

**Spec:** `docs/superpowers/specs/2026-07-13-admin-audit-management-design.md` (Workstream A). Workstream B (Mailcoach) is a separate plan; the "email failures" stat reads `audit_email_logs` and must render 0 when the table doesn't exist yet.

## Global Constraints

- Backend commands run from the repo root via `docker compose exec laravel.test <cmd>`. Tests: `docker compose exec laravel.test php artisan test --compact --filter=<Name>`. Format PHP with `docker compose exec laravel.test vendor/bin/pint <files>` before committing.
- Tests extend `Tests\Feature\FeatureTest`. Admin Filament tests act as `$this->createAdminUser()` (helper in FeatureTest).
- Prompt template placeholders are exactly `{metrics}` and `{excerpts}`; saving a template missing either is rejected.
- Existing admin resource actions (retry, launch, grant unlock, mark handled) must keep working unchanged.
- Status display/colors reuse `App\Mapper\AuditRequestStatusMapper` — never duplicate its match tables.
- The `AiAnalyzer` interface changes signature (adds `?string $adminContext = null`); every implementation (`ClaudeAnalyzer`, `Tests\Support\FakeAiAnalyzer`) and call site (`AuditPipeline`) updates in the same task (Task 2) — the suite must never be red between tasks.

---

### Task 1: Audit request columns for context, logs, and timing

**Files:**
- Create: `backend/database/migrations/2026_07_13_100000_add_admin_fields_to_audit_requests_table.php`
- Modify: `backend/app/Models/AuditRequest.php` (fillable, casts, `appendPipelineLog`)
- Test: `backend/tests/Feature/Models/AuditRequestAdminFieldsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: columns `admin_context` (nullable text), `pipeline_log` (nullable json), `analysis_started_at` / `analysis_completed_at` (nullable timestamps); model method `appendPipelineLog(string $step, string $message): void` (persists immediately). Tasks 2, 3, 5, 6 use these.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Models/AuditRequestAdminFieldsTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestAdminFieldsTest extends FeatureTest
{
    public function test_admin_fields_are_persistable(): void
    {
        $request = AuditRequest::factory()->create([
            'admin_context' => 'Focus on the payment module.',
            'analysis_started_at' => now()->subMinutes(5),
            'analysis_completed_at' => now(),
        ]);

        $fresh = $request->fresh();
        $this->assertSame('Focus on the payment module.', $fresh->admin_context);
        $this->assertNotNull($fresh->analysis_started_at);
        $this->assertNotNull($fresh->analysis_completed_at);
    }

    public function test_append_pipeline_log_accumulates_entries(): void
    {
        $request = AuditRequest::factory()->create();

        $request->appendPipelineLog('clone', 'Repository cloned');
        $request->appendPipelineLog('metrics', 'Metrics collected');

        $log = $request->fresh()->pipeline_log;
        $this->assertCount(2, $log);
        $this->assertSame('clone', $log[0]['step']);
        $this->assertSame('Repository cloned', $log[0]['message']);
        $this->assertArrayHasKey('at', $log[0]);
        $this->assertSame('metrics', $log[1]['step']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditRequestAdminFieldsTest`
Expected: FAIL — unknown column `admin_context` / undefined method `appendPipelineLog`.

- [ ] **Step 3: Implement migration and model changes**

Create `backend/database/migrations/2026_07_13_100000_add_admin_fields_to_audit_requests_table.php`:

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
            $table->text('admin_context')->nullable()->after('failure_reason');
            $table->json('pipeline_log')->nullable()->after('admin_context');
            $table->timestamp('analysis_started_at')->nullable()->after('pipeline_log');
            $table->timestamp('analysis_completed_at')->nullable()->after('analysis_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn(['admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at']);
        });
    }
};
```

In `backend/app/Models/AuditRequest.php`:

Extend `$fillable`:

```php
    protected $fillable = [
        'name', 'email', 'repo_url', 'message', 'status', 'failure_reason', 'meta', 'metrics',
        'email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'source', 'user_id', 'prepaid',
        'admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at',
    ];
```

Extend `$casts`:

```php
    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
        'email_verified_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'consented_at' => 'datetime',
        'free_run' => 'boolean',
        'prepaid' => 'boolean',
        'pipeline_log' => 'array',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
    ];
```

Add the method (after the relations):

```php
    public function appendPipelineLog(string $step, string $message): void
    {
        $log = $this->pipeline_log ?? [];
        $log[] = ['step' => $step, 'message' => $message, 'at' => now()->toIso8601String()];

        $this->forceFill(['pipeline_log' => $log])->save();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditRequestAdminFieldsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint database/migrations/2026_07_13_100000_add_admin_fields_to_audit_requests_table.php app/Models/AuditRequest.php tests/Feature/Models/AuditRequestAdminFieldsTest.php
git add backend/database/migrations/2026_07_13_100000_add_admin_fields_to_audit_requests_table.php backend/app/Models/AuditRequest.php backend/tests/Feature/Models/AuditRequestAdminFieldsTest.php
git commit -m "feat(backend): admin context, pipeline log, and analysis timing columns on audit requests"
```

---

### Task 2: PromptComposer and analyzer integration

**Files:**
- Create: `backend/app/Services/AuditReport/PromptComposer.php`
- Modify: `backend/app/Services/AuditReport/AiAnalyzer.php` (interface signature)
- Modify: `backend/app/Services/AuditReport/ClaudeAnalyzer.php` (use composer; drop `buildPrompt`)
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php:36` (pass admin context)
- Modify: `backend/tests/Support/FakeAiAnalyzer.php` (new signature, capture context)
- Test: `backend/tests/Feature/Services/PromptComposerTest.php`

**Interfaces:**
- Consumes: `ConfigService::get(string $key, ?string $default = null)`; `admin_context` from Task 1.
- Produces: `PromptComposer` with:
  - `const DEFAULT_TEMPLATE` (contains `{metrics}` and `{excerpts}`)
  - `template(): string` — ConfigService `audit.prompt_template` override when non-empty, else default
  - `compose(array $metrics, array $excerpts, ?string $adminContext = null): string`
  - `preview(AuditRequest $request): string` — composed prompt using stored metrics (or a note) and an `[file excerpts are computed at run time]` marker
  - `templateIsValid(string $template): bool` — both placeholders present
- `AiAnalyzer::analyze(array $metrics, array $excerpts, ?string $adminContext = null): array`. Tasks 4 and 5 call `template()`, `templateIsValid()`, and `preview()`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Services/PromptComposerTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Tests\Feature\FeatureTest;

class PromptComposerTest extends FeatureTest
{
    public function test_uses_built_in_template_by_default(): void
    {
        $composer = app(PromptComposer::class);

        $prompt = $composer->compose(['files' => 3], [['path' => 'a.php', 'content' => 'echo 1;']]);

        $this->assertStringContainsString('Repository metrics (JSON):', $prompt);
        $this->assertStringContainsString('"files": 3', $prompt);
        $this->assertStringContainsString('===== a.php =====', $prompt);
        $this->assertStringContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_setting_overrides_template(): void
    {
        app(ConfigService::class)->set('audit.prompt_template', "CUSTOM HEADER\n{metrics}\nMIDDLE\n{excerpts}\nCUSTOM FOOTER");

        $prompt = app(PromptComposer::class)->compose(['files' => 1], []);

        $this->assertStringContainsString('CUSTOM HEADER', $prompt);
        $this->assertStringContainsString('CUSTOM FOOTER', $prompt);
        $this->assertStringNotContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_admin_context_is_appended(): void
    {
        $prompt = app(PromptComposer::class)->compose(['files' => 1], [], 'Pay attention to the auth module.');

        $this->assertStringContainsString('Additional context from the audit team:', $prompt);
        $this->assertStringContainsString('Pay attention to the auth module.', $prompt);
    }

    public function test_template_validation_requires_both_placeholders(): void
    {
        $composer = app(PromptComposer::class);

        $this->assertTrue($composer->templateIsValid("x {metrics} y {excerpts} z"));
        $this->assertFalse($composer->templateIsValid('missing everything'));
        $this->assertFalse($composer->templateIsValid('only {metrics}'));
    }

    public function test_preview_uses_stored_metrics_and_marks_excerpts(): void
    {
        $request = AuditRequest::factory()->create([
            'metrics' => ['files' => 42],
            'admin_context' => 'Preview context.',
        ]);

        $preview = app(PromptComposer::class)->preview($request);

        $this->assertStringContainsString('"files": 42', $preview);
        $this->assertStringContainsString('[file excerpts are computed at run time]', $preview);
        $this->assertStringContainsString('Preview context.', $preview);
    }
}
```

(`ConfigService::set()` persists into the `configs` table and is read back by `get()` — the test DB isolates this per run; no cleanup needed because `set` is idempotent per key.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PromptComposerTest`
Expected: FAIL — `Class "App\Services\AuditReport\PromptComposer" not found`.

- [ ] **Step 3: Implement PromptComposer and rewire the analyzer**

Create `backend/app/Services/AuditReport/PromptComposer.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Services\ConfigService;

class PromptComposer
{
    public const DEFAULT_TEMPLATE = <<<'TEMPLATE'
Repository metrics (JSON):
{metrics}

File excerpts (largest files, truncated):
{excerpts}

Produce the codebase health report.
TEMPLATE;

    public function __construct(
        private ConfigService $configService,
    ) {}

    public function template(): string
    {
        $override = (string) $this->configService->get('audit.prompt_template', '');

        return trim($override) !== '' ? $override : self::DEFAULT_TEMPLATE;
    }

    public function templateIsValid(string $template): bool
    {
        return str_contains($template, '{metrics}') && str_contains($template, '{excerpts}');
    }

    /**
     * @param  array<int, array{path: string, content: string}>  $excerpts
     */
    public function compose(array $metrics, array $excerpts, ?string $adminContext = null): string
    {
        $excerptText = '';
        foreach ($excerpts as $excerpt) {
            $excerptText .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        $prompt = str_replace(
            ['{metrics}', '{excerpts}'],
            [json_encode($metrics, JSON_PRETTY_PRINT), $excerptText],
            $this->template(),
        );

        if ($adminContext !== null && trim($adminContext) !== '') {
            $prompt .= "\n\nAdditional context from the audit team:\n".trim($adminContext);
        }

        return $prompt;
    }

    /**
     * The prompt the next run would use — stored metrics if any, excerpts marked
     * as runtime-computed (they are never persisted).
     */
    public function preview(AuditRequest $request): string
    {
        $metrics = $request->metrics ?? ['note' => 'metrics are collected at run time'];

        $prompt = str_replace(
            ['{metrics}', '{excerpts}'],
            [json_encode($metrics, JSON_PRETTY_PRINT), "\n[file excerpts are computed at run time]\n"],
            $this->template(),
        );

        if ($request->admin_context !== null && trim($request->admin_context) !== '') {
            $prompt .= "\n\nAdditional context from the audit team:\n".trim($request->admin_context);
        }

        return $prompt;
    }
}
```

In `backend/app/Services/AuditReport/AiAnalyzer.php`, change the method signature:

```php
    public function analyze(array $metrics, array $excerpts, ?string $adminContext = null): array;
```

In `backend/app/Services/AuditReport/ClaudeAnalyzer.php`:
- Add a constructor: `public function __construct(private PromptComposer $promptComposer) {}`
- Change `analyze` signature to `analyze(array $metrics, array $excerpts, ?string $adminContext = null): array` and replace the message content line with:

```php
            messages: [['role' => 'user', 'content' => $this->promptComposer->compose($metrics, $excerpts, $adminContext)]],
```

- Delete the private `buildPrompt()` method entirely.

In `backend/app/Services/AuditReport/AuditPipeline.php`, change the analyze call (line ~36):

```php
            $payload = $this->analyzer->analyze($metrics, $collected['excerpts'], $auditRequest->admin_context);
```

In `backend/tests/Support/FakeAiAnalyzer.php`, update the signature and capture the context:

```php
    public ?string $receivedAdminContext = null;

    public function analyze(array $metrics, array $excerpts, ?string $adminContext = null): array
    {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;
        $this->receivedAdminContext = $adminContext;
        // ... (return array unchanged)
    }
```

- [ ] **Step 4: Run the composer test and the pipeline suite**

Run: `docker compose exec laravel.test php artisan test --compact --filter="PromptComposerTest|AuditPipelineTest"`
Expected: PASS — composer tests green; existing pipeline tests unaffected by the signature change.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Services/AuditReport/PromptComposer.php app/Services/AuditReport/AiAnalyzer.php app/Services/AuditReport/ClaudeAnalyzer.php app/Services/AuditReport/AuditPipeline.php tests/Support/FakeAiAnalyzer.php tests/Feature/Services/PromptComposerTest.php
git add backend/app/Services/AuditReport backend/tests/Support/FakeAiAnalyzer.php backend/tests/Feature/Services/PromptComposerTest.php
git commit -m "feat(backend): configurable prompt template with per-audit admin context"
```

---

### Task 3: Pipeline logging and timing

**Files:**
- Modify: `backend/app/Services/AuditReport/AuditPipeline.php`
- Modify: `backend/app/Services/AuditRequestService.php` (`markFailed`, ~line 130)
- Test: `backend/tests/Feature/Services/AuditPipelineTest.php` (extend)

**Interfaces:**
- Consumes: `appendPipelineLog(string, string)`, timestamp columns from Task 1.
- Produces: every pipeline run writes `analysis_started_at`, step log entries, and `analysis_completed_at` (on success); `markFailed` appends a `failed` log entry. Task 6's average-processing-time stat depends on the timestamps.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Services/AuditPipelineTest.php` (inside the class — the file already wires `FakeAiAnalyzer` and a local fixture repo in `setUp`):

```php
    public function test_pipeline_records_log_and_timing(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $request->refresh();
        $this->assertNotNull($request->analysis_started_at);
        $this->assertNotNull($request->analysis_completed_at);

        $steps = array_column($request->pipeline_log, 'step');
        $this->assertSame(['started', 'cloned', 'metrics', 'analyzed', 'report'], $steps);
    }

    public function test_failed_run_appends_failure_log_entry(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer(throws: new AiAnalysisException('boom')));
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        try {
            (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));
        } catch (AiAnalysisException) {
            // the job's failed() handler runs markFailed in production; simulate it
            app(\App\Services\AuditRequestService::class)->markFailed($request, 'boom');
        }

        $request->refresh();
        $log = $request->pipeline_log;
        $last = end($log);
        $this->assertSame('failed', $last['step']);
        $this->assertStringContainsString('boom', $last['message']);
        $this->assertNull($request->analysis_completed_at);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditPipelineTest`
Expected: FAIL — `pipeline_log` is null / timestamps null. Pre-existing tests still pass.

- [ ] **Step 3: Implement logging in the pipeline and markFailed**

In `backend/app/Services/AuditReport/AuditPipeline.php`, replace the `run` method:

```php
    public function run(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now(),
        ]);
        $auditRequest->appendPipelineLog('started', 'Analysis started');

        try {
            $this->cloner->preflight($auditRequest->repo_url);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid);
            $auditRequest->appendPipelineLog('cloned', 'Repository cloned');

            $collected = $this->metricsCollector->collect($path);
            $metrics = $collected['metrics'];
            $scores = $this->scoreCalculator->calculate($metrics);
            $metrics['computed_scores'] = $scores;
            $auditRequest->update(['metrics' => $metrics]);
            $auditRequest->appendPipelineLog('metrics', 'Metrics collected and scored');

            $payload = $this->analyzer->analyze($metrics, $collected['excerpts'], $auditRequest->admin_context);
            $payload['scores'] = $scores;
            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            $report = $this->reportService->create($auditRequest, $payload);
            $this->reportService->send($report);
            $auditRequest->update(['analysis_completed_at' => now()]);
            $auditRequest->appendPipelineLog('report', 'Report stored and sent');
        } catch (AuditNotAnalyzableException $e) {
            $auditRequest->appendPipelineLog('not_analyzable', $e->getMessage());
            $this->requestService->markNeedsFollowup($auditRequest, $e->getMessage());
        } finally {
            $this->cloner->cleanup($auditRequest->uuid);
        }
    }
```

In `backend/app/Services/AuditRequestService.php`, at the top of `markFailed()` (before the `update`):

```php
        $auditRequest->appendPipelineLog('failed', $reason);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditPipelineTest`
Expected: PASS (all, including the 2 new tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Services/AuditReport/AuditPipeline.php app/Services/AuditRequestService.php tests/Feature/Services/AuditPipelineTest.php
git add backend/app/Services/AuditReport/AuditPipeline.php backend/app/Services/AuditRequestService.php backend/tests/Feature/Services/AuditPipelineTest.php
git commit -m "feat(backend): pipeline step logging and analysis timing on audit requests"
```

---

### Task 4: Audit Settings admin page (prompt template)

**Files:**
- Create: `backend/app/Filament/Admin/Pages/AuditSettings.php`
- Create: `backend/app/Livewire/Filament/AuditSettings.php`
- Create: `backend/resources/views/filament/admin/pages/audit-settings.blade.php`
- Create: `backend/resources/views/livewire/filament/audit-settings.blade.php`
- Test: `backend/tests/Feature/Filament/Admin/Page/AuditSettingsTest.php`

**Interfaces:**
- Consumes: `PromptComposer::DEFAULT_TEMPLATE`, `templateIsValid()`, `ConfigService::get/set` with key `audit.prompt_template`.
- Produces: admin Settings page persisting `audit.prompt_template`. No other task depends on it beyond the key already read by `PromptComposer`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Admin/Page/AuditSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin\Page;

use App\Livewire\Filament\AuditSettings;
use App\Services\ConfigService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditSettingsTest extends FeatureTest
{
    public function test_admin_can_save_valid_template(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => "HEAD\n{metrics}\n{excerpts}\nTAIL"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame("HEAD\n{metrics}\n{excerpts}\nTAIL", app(ConfigService::class)->get('audit.prompt_template'));
    }

    public function test_template_missing_placeholders_is_rejected(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => 'no placeholders here'])
            ->call('save')
            ->assertHasFormErrors(['prompt_template']);
    }

    public function test_blank_template_is_allowed_and_means_default(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditSettingsTest`
Expected: FAIL — `Class "App\Livewire\Filament\AuditSettings" not found`.

- [ ] **Step 3: Implement page, component, and views**

Create `backend/app/Filament/Admin/Pages/AuditSettings.php`:

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Services\ConfigService;
use Filament\Pages\Page;

class AuditSettings extends Page
{
    protected string $view = 'filament.admin.pages.audit-settings';

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit Settings');
    }

    public static function canAccess(): bool
    {
        $configService = app()->make(ConfigService::class);

        return $configService->isAdminSettingsEnabled()
            && auth()->user()
            && auth()->user()->hasPermissionTo('update settings');
    }
}
```

Create `backend/resources/views/filament/admin/pages/audit-settings.blade.php`:

```blade
<x-filament-panels::page>
    @livewire('filament.audit-settings')
</x-filament-panels::page>
```

Create `backend/app/Livewire/Filament/AuditSettings.php`:

```php
<?php

namespace App\Livewire\Filament;

use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

class AuditSettings extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    private ConfigService $configService;

    public function render()
    {
        return view('livewire.filament.audit-settings');
    }

    public function boot(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    public function mount(): void
    {
        $this->form->fill([
            'prompt_template' => $this->configService->get('audit.prompt_template', ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Analysis Prompt'))
                    ->description(__('Template for the AI analysis prompt. Leave blank to use the built-in default shown below. Must contain the {metrics} and {excerpts} placeholders.'))
                    ->schema([
                        Textarea::make('prompt_template')
                            ->label(__('Prompt template'))
                            ->rows(12)
                            ->helperText(__('Built-in default:').' '.PromptComposer::DEFAULT_TEMPLATE)
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                    if (trim((string) $value) !== '' && ! app(PromptComposer::class)->templateIsValid((string) $value)) {
                                        $fail(__('The template must contain both {metrics} and {excerpts} placeholders.'));
                                    }
                                },
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->configService->set('audit.prompt_template', $data['prompt_template'] ?? '');

        Notification::make()->title(__('Audit settings saved'))->success()->send();
    }
}
```

Create `backend/resources/views/livewire/filament/audit-settings.blade.php`:

```blade
<div>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="pt-4 flex gap-4">
            <x-filament::button type="submit" class="mt-4">
                <x-filament::loading-indicator class="h-5 w-5 inline" wire:loading />
                {{ __('Save Changes') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditSettingsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Admin/Pages/AuditSettings.php app/Livewire/Filament/AuditSettings.php tests/Feature/Filament/Admin/Page/AuditSettingsTest.php
git add backend/app/Filament/Admin/Pages/AuditSettings.php backend/app/Livewire/Filament/AuditSettings.php backend/resources/views/filament/admin/pages/audit-settings.blade.php backend/resources/views/livewire/filament/audit-settings.blade.php backend/tests/Feature/Filament/Admin/Page/AuditSettingsTest.php
git commit -m "feat(backend): audit settings admin page with validated prompt template"
```

---

### Task 5: Admin resource — edit page, prompt preview, pipeline log, results override

**Files:**
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`
- Create: `backend/app/Filament/Admin/Resources/AuditRequests/Pages/EditAuditRequest.php`
- Test: `backend/tests/Feature/Filament/Admin/Resources/AuditRequestAdminEditTest.php`

**Interfaces:**
- Consumes: `admin_context`/`pipeline_log` (Task 1), `PromptComposer::preview()` (Task 2), `AuditRequestStatusMapper`.
- Produces: admin edit page at resource route `edit`; view-page action `editResults`. Nothing downstream depends on new symbols.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Filament/Admin/Resources/AuditRequestAdminEditTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestAdminEditTest extends FeatureTest
{
    public function test_admin_can_edit_status_input_data_and_context(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create(['status' => AuditRequestStatus::NEEDS_FOLLOWUP->value]);

        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(EditAuditRequest::class, ['record' => $audit->uuid])
            ->fillForm([
                'status' => AuditRequestStatus::HANDLED->value,
                'repo_url' => 'https://github.com/acme/corrected',
                'admin_context' => 'Client says the payment module matters most.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $audit->fresh();
        $this->assertSame(AuditRequestStatus::HANDLED->value, $fresh->status);
        $this->assertSame('https://github.com/acme/corrected', $fresh->repo_url);
        $this->assertSame('Client says the payment module matters most.', $fresh->admin_context);
    }

    public function test_view_page_shows_prompt_preview_and_pipeline_log(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create([
            'metrics' => ['files' => 7],
            'admin_context' => 'Look at auth.',
        ]);
        $audit->appendPipelineLog('cloned', 'Repository cloned');

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('Look at auth.')
            ->assertSee('Repository cloned');
    }

    public function test_results_override_rejects_invalid_payload_and_saves_valid_one(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create();
        $report = AuditReport::factory()->create(['audit_request_id' => $audit->id]);

        $this->actingAs($admin);

        // invalid JSON rejected
        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => 'not json'])
            ->assertHasActionErrors(['payload']);

        // missing scores.overall rejected
        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => json_encode(['summary' => 'x'])])
            ->assertHasActionErrors(['payload']);

        // valid payload saved
        $valid = $report->payload;
        $valid['summary'] = 'Corrected by admin.';

        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => json_encode($valid)])
            ->assertHasNoActionErrors();

        $this->assertSame('Corrected by admin.', $report->fresh()->payload['summary']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditRequestAdminEditTest`
Expected: FAIL — `Class ... Pages\EditAuditRequest not found`.

- [ ] **Step 3: Implement edit page, form, view additions**

Create `backend/app/Filament/Admin/Resources/AuditRequests/Pages/EditAuditRequest.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use Filament\Resources\Pages\EditRecord;

class EditAuditRequest extends EditRecord
{
    protected static string $resource = AuditRequestResource::class;
}
```

In `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`:

1. Add imports:

```php
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Services\AuditReport\PromptComposer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
```

2. Replace `canEdit`:

```php
    public static function canEdit($record): bool
    {
        return true;
    }
```

3. Add the form schema (new method):

```php
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Audit request'))->schema([
                Select::make('status')
                    ->options(
                        collect(AuditRequestStatus::cases())
                            ->mapWithKeys(fn (AuditRequestStatus $status) => [$status->value => app(AuditRequestStatusMapper::class)->mapForDisplay($status->value)])
                            ->all()
                    )
                    ->required(),
                TextInput::make('repo_url')->url()->maxLength(2048),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                Textarea::make('message')->rows(4),
                Textarea::make('admin_context')
                    ->label(__('Additional analysis context'))
                    ->helperText(__('Appended to the AI prompt on the next run of this audit.'))
                    ->rows(4),
            ]),
        ]);
    }
```

4. In `infolist()`, append these entries to the existing `Section::make(__('Request'))` schema array:

```php
                TextEntry::make('tenants')
                    ->label(__('Company / workspaces'))
                    ->state(fn (AuditRequest $record): string => $record->user?->tenants()->pluck('name')->implode(', ') ?: '—'),
                TextEntry::make('admin_context')
                    ->label(__('Additional analysis context'))
                    ->placeholder('—'),
```

and add two new sections after the Request section:

```php
            Section::make(__('Next-run prompt preview'))->collapsed()->schema([
                TextEntry::make('prompt_preview')
                    ->label('')
                    ->state(fn (AuditRequest $record): string => app(PromptComposer::class)->preview($record))
                    ->markdown(false)
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;']),
            ]),
            Section::make(__('Processing log'))->schema([
                TextEntry::make('pipeline_log')
                    ->label('')
                    ->state(function (AuditRequest $record): string {
                        $log = $record->pipeline_log ?? [];

                        if ($log === []) {
                            return __('No processing activity recorded yet.');
                        }

                        return collect($log)
                            ->map(fn (array $entry): string => "[{$entry['at']}] {$entry['step']}: {$entry['message']}")
                            ->implode("\n");
                    })
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;']),
                TextEntry::make('analysis_started_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('analysis_completed_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
            ]),
```

5. Register the edit page in `getPages()`:

```php
    public static function getPages(): array
    {
        return [
            'index' => ListAuditRequests::route('/'),
            'view' => ViewAuditRequest::route('/{record}'),
            'edit' => EditAuditRequest::route('/{record}/edit'),
        ];
    }
```

6. Add `repo_url` search on the list (change the existing column): `TextColumn::make('repo_url')->limit(40)->searchable()`, and add filters after `->defaultSort(...)`:

```php
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->multiple()
                    ->options(
                        collect(AuditRequestStatus::cases())
                            ->mapWithKeys(fn (AuditRequestStatus $status) => [$status->value => app(AuditRequestStatusMapper::class)->mapForDisplay($status->value)])
                            ->all()
                    ),
                \Filament\Tables\Filters\Filter::make('submitted')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('submitted_from')->label(__('Submitted from')),
                        \Filament\Forms\Components\DatePicker::make('submitted_until')->label(__('Submitted until')),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when($data['submitted_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['submitted_until'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
```

(If `Filter::schema()` fails on the installed Filament build, use `Filter::form()` — same components.)

7. Add an `EditAction` to `recordActions` (before `ViewAction::make()`): `\Filament\Actions\EditAction::make(),`

In `backend/app/Filament/Admin/Resources/AuditRequests/Pages/ViewAuditRequest.php`, add the results-override header action:

```php
<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('editResults')
                ->label(__('Edit results'))
                ->visible(fn (): bool => $this->record->report !== null)
                ->schema([
                    Textarea::make('payload')
                        ->label(__('Report payload (JSON)'))
                        ->helperText(__('The hosted web report reads this live. The PDF stays unchanged until the audit is re-run.'))
                        ->rows(20)
                        ->default(fn (): string => json_encode($this->record->report->payload, JSON_PRETTY_PRINT))
                        ->required()
                        ->rules([
                            function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    $decoded = json_decode((string) $value, true);

                                    if (! is_array($decoded)) {
                                        $fail(__('The payload must be valid JSON.'));

                                        return;
                                    }

                                    if (! is_int(data_get($decoded, 'scores.overall'))) {
                                        $fail(__('The payload must keep an integer scores.overall value.'));
                                    }
                                };
                            },
                        ]),
                ])
                ->action(function (array $data): void {
                    $this->record->report->update(['payload' => json_decode($data['payload'], true)]);

                    \Filament\Notifications\Notification::make()
                        ->title(__('Report payload updated'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
```

(This replaces the file's existing contents if it currently only sets `$resource` — preserve any existing header actions by merging them into the returned array. If `Action::schema()` fails on the installed Filament build, use `Action::form()`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditRequestAdminEditTest`
Expected: PASS (3 tests).

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Filament/Admin`
Expected: PASS — existing admin resource/page tests unaffected.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Admin/Resources/AuditRequests tests/Feature/Filament/Admin/Resources/AuditRequestAdminEditTest.php
git add backend/app/Filament/Admin/Resources/AuditRequests backend/tests/Feature/Filament/Admin/Resources/AuditRequestAdminEditTest.php
git commit -m "feat(backend): admin audit editing, prompt preview, processing log, results override"
```

---

### Task 6: Admin dashboard widgets

**Files:**
- Create: `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`
- Create: `backend/app/Filament/Admin/Widgets/AuditsByPlanWidget.php`
- Test: `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php`

**Interfaces:**
- Consumes: `AuditRequest` statuses + timing columns (Tasks 1, 3); `Queue::connection('redis-audit')->size(config('audit.queue'))`; `audit_email_logs` table (Workstream B — may not exist yet: guard with `Schema::hasTable`).
- Produces: two auto-discovered admin widgets (panel already calls `discoverWidgets(in: app_path('Filament/Admin/Widgets'), ...)`).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
use App\Filament\Admin\Widgets\AuditsByPlanWidget;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminWidgetsTest extends FeatureTest
{
    public function test_stats_widget_counts_statuses_and_average_processing_time(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'analysis_started_at' => now()->subMinutes(10),
            'analysis_completed_at' => now()->subMinutes(6),
        ]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::FAILED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Total audits'))
            ->assertSee(__('Analyzing'))
            ->assertSee(__('Needs manual action'))
            ->assertSee('4m'); // average processing time
    }

    public function test_by_plan_widget_groups_current_month_audits(): void
    {
        $admin = $this->createAdminUser();

        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);
        $product = Product::factory()->create(['name' => 'Audit Growth', 'metadata' => ['audit_analyses_per_month' => 20]]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Audit Growth Monthly']);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
        ]);
        AuditRequest::factory()->count(2)->create(['user_id' => $user->id]);
        AuditRequest::factory()->create(); // no subscription → free

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class)
            ->assertSee('Audit Growth Monthly')
            ->assertSee(__('Free / no plan'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditAdminWidgetsTest`
Expected: FAIL — widget classes not found.

- [ ] **Step 3: Implement the widgets**

Create `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`:

```php
<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class AuditAdminStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $pending = [AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value, AuditRequestStatus::PENDING_VERIFICATION->value];
        $completed = [AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value];
        $manual = [AuditRequestStatus::NEEDS_FOLLOWUP->value, AuditRequestStatus::AWAITING_ACCESS->value, AuditRequestStatus::AWAITING_PAYMENT->value];

        return [
            Stat::make(__('Total audits'), AuditRequest::count())
                ->description(__(':today today · :week this week · :month this month', [
                    'today' => AuditRequest::whereDate('created_at', today())->count(),
                    'week' => AuditRequest::where('created_at', '>=', now()->startOfWeek())->count(),
                    'month' => AuditRequest::where('created_at', '>=', now()->startOfMonth())->count(),
                ])),
            Stat::make(__('Pending'), AuditRequest::whereIn('status', $pending)->count())->color('gray'),
            Stat::make(__('Analyzing'), AuditRequest::where('status', AuditRequestStatus::ANALYZING->value)->count())->color('info'),
            Stat::make(__('Completed'), AuditRequest::whereIn('status', $completed)->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::where('status', AuditRequestStatus::FAILED->value)->count())->color('danger'),
            Stat::make(__('Needs manual action'), AuditRequest::whereIn('status', $manual)->count())->color('warning'),
            Stat::make(__('Avg processing time'), $this->averageProcessingTime())
                ->description(__('From analysis start to report')),
            Stat::make(__('Email failures'), $this->emailFailures())->color('danger'),
            Stat::make(__('Audit queue depth'), $this->queueDepth())
                ->description(__('Jobs waiting on the audit queue'))
                ->url('/horizon', shouldOpenInNewTab: true),
        ];
    }

    private function averageProcessingTime(): string
    {
        $seconds = AuditRequest::whereNotNull('analysis_started_at')
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

    private function emailFailures(): int
    {
        // audit_email_logs ships with the Mailcoach workstream; render 0 until it lands
        if (! Schema::hasTable('audit_email_logs')) {
            return 0;
        }

        return (int) DB::table('audit_email_logs')->where('status', 'failed')->count();
    }

    private function queueDepth(): int|string
    {
        try {
            return Queue::connection('redis-audit')->size((string) config('audit.queue'));
        } catch (\Throwable) {
            return '—';
        }
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
```

Create `backend/app/Filament/Admin/Widgets/AuditsByPlanWidget.php`:

```php
<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditsByPlanWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $audits = AuditRequest::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->with(['user.subscriptions' => fn ($query) => $query
                ->where('status', SubscriptionStatus::ACTIVE->value)
                ->where('ends_at', '>', now())
                ->with('plan'), ])
            ->get();

        $byPlan = $audits
            ->groupBy(function (AuditRequest $audit): string {
                $plan = $audit->user?->subscriptions->first()?->plan;

                return $plan?->name ?? __('Free / no plan');
            })
            ->map->count()
            ->sortDesc();

        if ($byPlan->isEmpty()) {
            return [Stat::make(__('Audits by plan (this month)'), 0)];
        }

        return $byPlan
            ->map(fn (int $count, string $planName): Stat => Stat::make($planName, $count)
                ->description(__('audits this month')))
            ->values()
            ->all();
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditAdminWidgetsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Admin/Widgets/AuditAdminStatsWidget.php app/Filament/Admin/Widgets/AuditsByPlanWidget.php tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php
git add backend/app/Filament/Admin/Widgets backend/tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php
git commit -m "feat(backend): admin dashboard audit statistics and by-plan widgets"
```

---

### Task 7: Full regression gate

**Files:** none (verification only).

- [ ] **Step 1: Full suite + static analysis**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS, 0 failures.

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 2: Manual verification at `http://localhost:8080/admin` (admin@admin.com / admin after demo seed)**

1. Audits list: search by email/repo, filter by status and date range.
2. Open an audit → view page shows company, prompt preview (reflects the template + context), processing log; Edit → change status/context, save.
3. Settings → Audit Settings: save a custom template (validation rejects one without placeholders); the prompt preview on an audit reflects it.
4. On an audit with a report: Edit results → invalid JSON rejected; valid change visible on the hosted report page.
5. Admin dashboard shows both audit widgets; queue-depth stat links to Horizon.

- [ ] **Step 3: Report**

Summarize results and any deviations.
