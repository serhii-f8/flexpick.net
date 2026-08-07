# Audit UI — Public Report Implementation Plan (Part B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the customer-facing audit report look like it came from the same company as the app, by extracting its ordering logic into a testable service and giving the web report a light, brand-aligned Tailwind design — without breaking the DomPDF-rendered PDF.

**Architecture:** A new `ReportPresenter` takes severity ranking and file grouping out of the Blade `@php` block that currently owns them. The shared partials then fork into a Tailwind web set and an inline-style PDF set, both fed by that presenter so their ordering cannot drift.

**Tech Stack:** PHP 8.4, Laravel 13, Tailwind 4, DomPDF (`barryvdh/laravel-dompdf`), PHPUnit 11.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-07-audit-ui-design.md`. Part B only.
- Run every command inside Docker: `docker compose exec -T laravel.test <cmd>` from `/var/www/html/flexpick.net`.
- Tests are **PHPUnit**, not Pest. Extend `Tests\Feature\FeatureTest`.
- Format with `vendor/bin/pint` (never `pint --dirty`). `vendor/bin/phpstan analyse` must be `[OK] No errors`.
- **DomPDF supports neither flexbox nor grid.** `audit.blade.php` and `partials/pdf/*` stay table-and-block based with inline styles. This is deliberate, not legacy.
- **The web report must not inherit the dark theme.** `resources/css/app.css` loads the daisyUI `flexpick` theme with `color-scheme: dark` and `default: true`. Use Tailwind utilities and the `--color-primary-*` / `--color-secondary-*` scales from `colors.css`; do **not** use daisyUI semantic classes (`bg-base-100`, `text-base-content`).
- The PDF is a **paid deliverable**. Every task that touches it ends with the PDF render assertion from Task 5 passing.
- All user-facing strings go through `__()`.

---

### Task 1: Extract ReportPresenter

**Files:**
- Create: `backend/app/Services/AuditReport/ReportPresenter.php`
- Test: `backend/tests/Feature/Services/AuditReport/ReportPresenterTest.php`

**Interfaces:**
- Consumes: a `ReportPayload`-shaped array (`file_findings`, `deep_review`).
- Produces:
  - `ReportPresenter::SEVERITY_RANK` — `array<string, int>`
  - `findingsByFile(array $payload): Collection` — keyed by path, each value a `Collection` of finding arrays. Files ordered by worst severity descending; findings within a file by severity descending then line ascending.
  - `deepReviewMeta(array $payload): ?array` — the raw `deep_review` block or `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\AuditReport;

use App\Services\AuditReport\ReportPresenter;
use Tests\Feature\FeatureTest;

class ReportPresenterTest extends FeatureTest
{
    private ReportPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(ReportPresenter::class);
    }

    public function test_files_are_ordered_by_their_worst_finding(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'low', 'line' => 1, 'title' => 'A'],
            ['path' => 'b.php', 'severity' => 'critical', 'line' => 2, 'title' => 'B'],
            ['path' => 'c.php', 'severity' => 'medium', 'line' => 3, 'title' => 'C'],
        ]];

        $this->assertSame(
            ['b.php', 'c.php', 'a.php'],
            $this->presenter->findingsByFile($payload)->keys()->all()
        );
    }

    public function test_findings_within_a_file_sort_by_severity_then_line(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'low', 'line' => 5, 'title' => 'low-5'],
            ['path' => 'a.php', 'severity' => 'high', 'line' => 90, 'title' => 'high-90'],
            ['path' => 'a.php', 'severity' => 'high', 'line' => 10, 'title' => 'high-10'],
        ]];

        $titles = $this->presenter->findingsByFile($payload)
            ->get('a.php')
            ->pluck('title')
            ->all();

        $this->assertSame(['high-10', 'high-90', 'low-5'], $titles);
    }

    public function test_unknown_severity_ranks_lowest_and_missing_line_is_tolerated(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'not-a-severity', 'title' => 'unknown'],
            ['path' => 'a.php', 'severity' => 'info', 'line' => 3, 'title' => 'info-3'],
        ]];

        $titles = $this->presenter->findingsByFile($payload)
            ->get('a.php')
            ->pluck('title')
            ->all();

        $this->assertSame(['info-3', 'unknown'], $titles);
    }

    public function test_absent_file_findings_yields_an_empty_collection(): void
    {
        $this->assertTrue($this->presenter->findingsByFile([])->isEmpty());
    }

    public function test_deep_review_meta_is_returned_or_null(): void
    {
        $this->assertNull($this->presenter->deepReviewMeta([]));
        $this->assertSame(
            ['files_reviewed' => 3],
            $this->presenter->deepReviewMeta(['deep_review' => ['files_reviewed' => 3]])
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=ReportPresenterTest`
Expected: FAIL — `Class "App\Services\AuditReport\ReportPresenter" not found`.

- [ ] **Step 3: Write the presenter**

This is a verbatim lift of the logic currently inside the `@php` block at the top of
`resources/views/reports/partials/deep-findings.blade.php`, including its comments.

```php
<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Collection;

/**
 * Ordering and grouping for report findings.
 *
 * This logic previously lived in a @php block at the top of the
 * deep-findings partial. It moved here so the web and PDF renderings cannot
 * drift apart, and so the ordering is testable without rendering a view.
 */
class ReportPresenter
{
    public const SEVERITY_RANK = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1,
    ];

    /**
     * Findings grouped by file; files ordered by their worst finding, findings
     * within a file by severity, then line. A customer opens one file and sees
     * everything wrong with it instead of jumping around a flat severity list.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function findingsByFile(array $payload): Collection
    {
        return collect($payload['file_findings'] ?? [])
            // Arrays compare element-wise in PHP, so negating the rank sorts
            // severity descending and line ascending in one pass.
            ->sortBy(fn (array $f): array => [-$this->rank($f), $f['line'] ?? 0])
            ->groupBy('path')
            ->sortByDesc(fn (Collection $findings): int => $findings->max(fn (array $f): int => $this->rank($f)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function deepReviewMeta(array $payload): ?array
    {
        return $payload['deep_review'] ?? null;
    }

    /** @param  array<string, mixed>  $finding */
    private function rank(array $finding): int
    {
        return self::SEVERITY_RANK[$finding['severity'] ?? ''] ?? 0;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=ReportPresenterTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --format agent
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
git add backend/app/Services/AuditReport/ReportPresenter.php \
        backend/tests/Feature/Services/AuditReport/ReportPresenterTest.php
git commit -m "refactor(audit): extract finding ordering into ReportPresenter"
```

---

### Task 2: Point the existing partial at the presenter

No visual change. This proves the extraction is behaviour-preserving before anything forks.

**Files:**
- Modify: `backend/resources/views/reports/partials/deep-findings.blade.php:1-15` (the `@php` block)

**Interfaces:**
- Consumes: `ReportPresenter::findingsByFile()`, `ReportPresenter::deepReviewMeta()` from Task 1.
- Produces: unchanged `$byFile` / `$deep` locals for the rest of the template.

- [ ] **Step 1: Confirm the current report tests pass**

Run: `docker compose exec -T laravel.test php artisan test --filter=AuditReportControllerTest`
Expected: PASS. These assert on content strings (`'Fixture summary.'`, `'Reviewed thoroughly, no blockers.'`) and are the regression net for this whole plan.

- [ ] **Step 2: Replace the @php block**

Replace lines 1–15 of `deep-findings.blade.php` with:

```blade
@php
    $presenter = app(\App\Services\AuditReport\ReportPresenter::class);
    $deep = $presenter->deepReviewMeta($payload);
    $byFile = $presenter->findingsByFile($payload);
@endphp
```

Everything below `@endphp` is untouched.

- [ ] **Step 3: Run the report tests**

Run: `docker compose exec -T laravel.test php artisan test --filter='AuditReportControllerTest|ReportPresenterTest'`
Expected: PASS. Identical output to Step 1 — this step must not change behaviour.

- [ ] **Step 4: Commit**

```bash
git add backend/resources/views/reports/partials/deep-findings.blade.php
git commit -m "refactor(audit): deep-findings partial reads from ReportPresenter"
```

---

### Task 3: PDF render regression test

Written **before** the fork, so it is a genuine safety net rather than a rubber stamp.

**Files:**
- Test: `backend/tests/Feature/Services/AuditReport/PdfRenderTest.php`

**Interfaces:**
- Consumes: `AuditReportService`, `AuditReport` factory.
- Produces: a guard asserting the PDF still renders and is a real PDF.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Services\AuditReport;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\Feature\FeatureTest;

class PdfRenderTest extends FeatureTest
{
    /**
     * DomPDF fails on CSS a browser tolerates -- flexbox and grid in
     * particular. The PDF is a paid deliverable, so it gets its own guard.
     */
    public function test_report_pdf_renders_to_a_pdf_document(): void
    {
        $user = User::factory()->create();
        $request = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
        ]);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
            'payload' => [
                'summary' => 'Fixture summary.',
                'scores' => ['overall' => 68, 'security' => 80],
                'file_findings' => [[
                    'path' => 'src/App.php',
                    'severity' => 'high',
                    'line' => 12,
                    'title' => 'Unvalidated input',
                    'evidence' => 'Evidence here.',
                    'recommendation' => 'Validate it.',
                    'effort' => 'small',
                ]],
                'deep_review' => ['files_reviewed' => 1, 'files_selected' => 1],
            ],
        ]);

        $output = Pdf::loadView('reports.audit', [
            'report' => $report->fresh(),
            'payload' => $report->payload,
        ])->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }
}
```

If `reports.audit` requires view variables beyond `report` and `payload`, read
`AuditReportService` where it calls `Pdf::loadView(...)` and pass exactly the same set.

- [ ] **Step 2: Run it**

Run: `docker compose exec -T laravel.test php artisan test --filter=PdfRenderTest`
Expected: PASS against the current, unforked PDF view.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Services/AuditReport/PdfRenderTest.php
git commit -m "test(audit): guard the PDF report against render regressions"
```

---

### Task 4: Fork the partials

**Files:**
- Create: `backend/resources/views/reports/partials/pdf/deep-findings.blade.php`
- Create: `backend/resources/views/reports/partials/pdf/expert-review.blade.php`
- Create: `backend/resources/views/reports/partials/web/deep-findings.blade.php`
- Create: `backend/resources/views/reports/partials/web/expert-review.blade.php`
- Modify: `backend/resources/views/reports/audit.blade.php:113,115` (include paths)
- Modify: `backend/resources/views/reports/audit-web.blade.php:229,235` (include paths)
- Delete: `backend/resources/views/reports/partials/deep-findings.blade.php`, `backend/resources/views/reports/partials/expert-review.blade.php`

**Interfaces:**
- Consumes: `ReportPresenter` (both forks call it identically).
- Produces: `reports.partials.pdf.*` and `reports.partials.web.*` view names.

- [ ] **Step 1: Create the PDF fork**

`git mv` the two existing partials into `partials/pdf/`. Their contents are unchanged — they already use the inline-style class vocabulary that `audit.blade.php` defines.

```bash
mkdir -p backend/resources/views/reports/partials/pdf
git mv backend/resources/views/reports/partials/deep-findings.blade.php backend/resources/views/reports/partials/pdf/deep-findings.blade.php
git mv backend/resources/views/reports/partials/expert-review.blade.php backend/resources/views/reports/partials/pdf/expert-review.blade.php
```

Update `audit.blade.php` lines 113 and 115 to `reports.partials.pdf.deep-findings` and `reports.partials.pdf.expert-review`.

- [ ] **Step 2: Verify the PDF still renders**

Run: `docker compose exec -T laravel.test php artisan test --filter=PdfRenderTest`
Expected: PASS. If it fails, the include paths are wrong — fix before continuing.

- [ ] **Step 3: Create the web fork of deep-findings**

`backend/resources/views/reports/partials/web/deep-findings.blade.php` — same structure and same `__()` strings as the PDF fork, Tailwind classes instead of the `.risk` / `.badge-*` vocabulary:

```blade
@php
    $presenter = app(\App\Services\AuditReport\ReportPresenter::class);
    $deep = $presenter->deepReviewMeta($payload);
    $byFile = $presenter->findingsByFile($payload);

    $badge = [
        'critical' => 'bg-red-100 text-red-900',
        'high' => 'bg-red-50 text-red-800',
        'medium' => 'bg-amber-50 text-amber-800',
        'low' => 'bg-lime-50 text-lime-800',
        'info' => 'bg-sky-50 text-sky-800',
    ];
@endphp

@if ($deep !== null)
    <h2 class="mb-3 text-base font-bold">{{ __('Deep file review') }}</h2>

    @if ($deep['degraded'] ?? false)
        <p class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('The deep review could not be completed for this run. The automated analysis in this report is complete, and we have been notified.') }}
        </p>
    @else
        @if ($deep['truncated'] ?? false)
            <p class="text-xs text-stone-500">
                {{ __('Reviewed :reviewed of :selected selected files, in risk order.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                    'selected' => $deep['files_selected'] ?? 0,
                ]) }}
            </p>
        @else
            <p class="text-xs text-stone-500">
                {{ __('Reviewed :reviewed files, selected as the riskiest in this repository.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @endif

        @if ($byFile->isEmpty())
            {{-- P6: a healthy verdict is a designed outcome, not an empty state. --}}
            <p class="mt-3 text-sm">
                {{ __('No file-level issues were found across the :count files reviewed. The riskiest parts of this codebase hold up to close reading.', [
                    'count' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @else
            @foreach ($byFile as $path => $findings)
                <div class="mt-5 border-t border-stone-200 pt-4">
                    <p class="break-all font-mono text-sm font-semibold">{{ $path }}</p>

                    @foreach ($findings as $finding)
                        <div class="mt-3 border-l-2 border-stone-200 pl-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $badge[$finding['severity']] ?? 'bg-stone-100 text-stone-700' }}">
                                    {{ $finding['severity'] }}
                                </span>
                                <span class="font-semibold">{{ $finding['title'] }}</span>
                                @if (($finding['line'] ?? null) !== null)
                                    <span class="text-xs text-stone-500">{{ __('line :line', ['line' => $finding['line']]) }}</span>
                                @endif
                            </div>

                            @if ($unlocked)
                                <div class="mt-2 space-y-1 text-sm text-stone-700">
                                    <div>{{ $finding['evidence'] }}</div>
                                    <div><strong>{{ __('Fix') }}:</strong> {{ $finding['recommendation'] }}</div>
                                    <div class="text-xs text-stone-500">{{ __('Effort: :effort', ['effort' => $finding['effort']]) }}</div>

                                    @if (($finding['related_paths'] ?? []) !== [])
                                        <div class="text-xs text-stone-500">
                                            {{ __('Also involves') }}: {{ implode(', ', $finding['related_paths']) }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    @endif
@endif
```

- [ ] **Step 4: Create the web fork of expert-review**

`backend/resources/views/reports/partials/web/expert-review.blade.php`. Every `__()` string and
every `expert_review` payload key is byte-identical to the PDF fork — `AuditReportControllerTest`
asserts on `'Human expert review'`, `'Reviewed thoroughly, no blockers.'` and `'Jane Reviewer'`,
and all three must still render.

```blade
@php
    $expertReview = $payload['expert_review'] ?? null;
@endphp

@if ($expertReview !== null)
    <h2 class="mb-3 text-base font-bold">{{ __('Human expert review') }}</h2>

    <p class="rounded-lg border border-stone-200 border-l-4 border-l-green-600 bg-green-50 px-4 py-3 text-sm text-green-900">
        {{ __('Reviewed by a human expert.') }}
    </p>

    <p class="mt-3">{{ $expertReview['expert_summary'] }}</p>

    @if (trim($expertReview['review_notes'] ?? '') !== '')
        <div class="mt-2 text-sm text-stone-700">
            {{ $expertReview['review_notes'] }}
        </div>
    @endif

    <p class="mt-3 text-xs text-stone-500">
        {{ __('Reviewed by :name on :date', [
            'name' => $expertReview['reviewed_by'],
            'date' => \Illuminate\Support\Carbon::parse($expertReview['reviewed_at'])->format(config('app.datetime_format')),
        ]) }}
    </p>
@endif
```

- [ ] **Step 5: Point audit-web at the web fork**

Update `audit-web.blade.php` lines 229 and 235 to `reports.partials.web.deep-findings` and `reports.partials.web.expert-review`.

- [ ] **Step 6: Run the report tests**

Run: `docker compose exec -T laravel.test php artisan test --filter='AuditReportControllerTest|PdfRenderTest'`
Expected: PASS. The web view still has its old inline `<style>` at this point, so the Tailwind classes are inert — that is fine and expected; Task 5 supplies them.

- [ ] **Step 7: Commit**

```bash
git add backend/resources/views/reports/
git commit -m "refactor(audit): fork report partials into web and pdf variants"
```

---

### Task 5: Light, brand-aligned web report

**Files:**
- Modify: `backend/resources/views/reports/audit-web.blade.php` (replace `<style>` block and body classes)
- Test: `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php` (add one assertion)

**Interfaces:**
- Consumes: `resources/css/app.css` via `@vite`, `partials/web/*` from Task 4.
- Produces: no PHP API change.

- [ ] **Step 1: Add the failing assertion**

In `AuditReportControllerTest::test_signed_url_shows_web_report()`, add:

```php
        $response->assertSee('reports-page', false);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --filter=test_signed_url_shows_web_report`
Expected: FAIL — no `reports-page` marker in the output.

- [ ] **Step 3: Replace the head and body shell**

Swap the 40-line inline `<style>` block (lines 7–47) for the Vite bundle, and give `<body>` the light brand surface. **Do not** use daisyUI semantic classes — `app.css` loads a dark-default theme and they would invert the design.

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health Report') }}</title>
    @vite('resources/css/app.css')
    <style>
        /* The bundle's daisyUI theme is dark by default; this document is a
           reading surface and is deliberately light. Scoped to this page. */
        .reports-page { background: #faf8f4; color: #1c1917; }
    </style>
</head>
<body class="reports-page font-sans text-[15px] leading-relaxed">
<div class="mx-auto max-w-[860px] px-4 py-8">
```

Close with `</div></body></html>` at the end of the file.

- [ ] **Step 4: Restyle the page blocks**

Translate the old CSS classes to Tailwind, one for one:

| Old class | Tailwind replacement |
| --- | --- |
| `.card` | `rounded-xl border border-stone-200 bg-white p-7 mb-5` |
| `h1` | `text-2xl font-bold mb-1` |
| `h2` | `text-base font-bold mb-3` |
| `.muted` | `text-xs text-stone-500` |
| `.sample-banner` | `mb-5 rounded-lg bg-primary-500 px-3 py-2 text-center text-xs font-bold uppercase tracking-wider text-stone-900` |
| `.score-big` | `text-5xl font-bold text-primary-600` |
| `.scores-grid` | `grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3` |
| `.score-tile` | `rounded-lg border border-stone-200 p-3 text-center` |
| `.locked-blur` | `blur-[5px] select-none pointer-events-none text-stone-700` |
| `.lock-overlay` | `absolute inset-0 flex items-center justify-center` |
| `.lock-pill` | `rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50` |
| `.cta-card` | `rounded-xl bg-stone-900 p-7 text-center text-stone-50` |
| `.btn` | `inline-block rounded-lg bg-primary-500 px-6 py-3 font-bold text-stone-900 no-underline` |
| `.btn-ghost` | `inline-block rounded-lg border border-stone-600 px-6 py-3 font-bold text-stone-50 no-underline` |
| `table` / `th` / `td` | `w-full border-collapse` / `border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500` / `border-b border-stone-200 p-2 text-left align-top` |

Keep the delta colouring at line 67 but move it off the inline `style` attribute:

```blade
<p class="text-xs {{ $deltas['deltas']['overall'] > 0 ? 'text-lime-700' : 'text-red-700' }}">
```

- [ ] **Step 5: Build assets and run the tests**

```bash
docker compose exec -T laravel.test npm run build
docker compose exec -T laravel.test php artisan test --filter='AuditReportControllerTest|PdfRenderTest'
```

Expected: PASS. `@vite` needs a built manifest — without it the view throws "Vite manifest not found", exactly as `CLAUDE.md` warns for `/pricing`.

- [ ] **Step 6: Align the PDF palette**

In `audit.blade.php`, update only the colour literals in its `<style>` block to match: `#b98e41` for the score and headings accent, `#faf8f4` page ground, `#e8e2d6` borders. **Do not** change any layout rule, and keep `font-family: DejaVu Sans` — DM Sans would require font embedding and is out of scope.

- [ ] **Step 7: Full verification**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
docker compose exec -T laravel.test php artisan test --compact
```

Expected: Pint `PASS`, PHPStan `[OK] No errors`, full suite green.

- [ ] **Step 8: Commit**

```bash
git add backend/resources/views/reports/ backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(audit): light brand-aligned web report on the shared design system"
```

---

## Manual verification

Automated tests cannot judge appearance. After Task 5:

1. `docker compose exec -T laravel.test php artisan horizon` (or the two `queue:work` processes) so a report can be generated.
2. Open a signed report URL from Mailpit at `http://localhost:8026`.
3. Confirm: light warm ground, DM Sans, gold headline score, coral high-severity badges, no dark-theme bleed-through.
4. Download the PDF from the same report and confirm it still opens and its layout is intact.
