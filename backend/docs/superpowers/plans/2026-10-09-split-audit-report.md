# Split Audit Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the single audit report into a plain-language **business report** (default page + PDF, with server-rendered SVG charts) and a **developer report** (all technical findings, separate page + PDF), both produced from one audit run.

**Architecture:** Payload v6 grows `client_summary` (verdict, areas, urgency/business-area per finding, roadmap, questions) inside the existing single Claude call. A pure `ReportChartBuilder` turns numbers into SVG strings; a `BusinessReportPresenter` assembles the business view model once so the web page and PDF cannot drift. A `ReportVariant` enum names the two reports and drives routes, views, PDF paths and filenames. Today's views become the developer report; new views render the business report.

**Tech Stack:** Laravel 13, PHP 8.4, Blade + Tailwind 4/daisyUI (web), barryvdh/dompdf + dompdf/php-svg-lib (PDF), PHPUnit 11.

**Spec:** `backend/docs/superpowers/specs/2026-10-09-split-audit-report-design.md`

## Global Constraints

- All commands run inside Docker from the repo root: `docker compose exec laravel.test <cmd>` (host PHP cannot reach MySQL).
- Tests: PHPUnit classes, `php artisan make:test --phpunit`; never Pest syntax.
- Formatting gate: `vendor/bin/pint` then `vendor/bin/pint --test` (never `pint --dirty`). Static analysis: `vendor/bin/phpstan analyse` (level 3).
- Stage explicit paths only (`git add <paths>`), never `git add -A` — other sessions share this checkout.
- Business report: no file paths, rule families, tool names or code anywhere on the page or in its PDF.
- Payload v1–v5 reports must keep rendering on both pages.
- No extra Claude API call per audit; `ai_max_tokens` stays 16000.
- No JS chart library; charts are server-built SVG. In PDFs SVG is embedded as `<img src="data:image/svg+xml;base64,…">`. SVG uses only `rect`, `path`, `circle`, `text` — no filters, no CSS inside SVG.
- PDF views: no flexbox, no grid (dompdf). Tables and blocks only.
- Score bands always come from `App\Support\ScoreBand::fromScore()`.
- Chart palette (print-safe, the brand's light-theme values): critical `#c55538`, watch `#a8712b`, healthy `#b98e41`, track `#e8e2d6`, ink `#1c1917`, muted `#78716c`.
- Download filenames: `codebase-health-business.pdf`, `codebase-health-developer.pdf`.
- One unlock opens both reports. A report held for expert review is 403 on both pages and both downloads.

## Review Focus

1. **A v5 report (old `client_summary`, no v6 fields) opened on the business page** — renders overview + findings without chips; roadmap, questions, business-area chart hidden; no PHP "undefined index" error. → Task 6 test `test_a_v5_report_renders_on_the_business_page_without_v6_sections`.
2. **A v1–v4 report (no `client_summary`) on the business page** — renders gauge, area bars, severity chart and the "full findings are in the developer report" line; never prints the technical `summary`. → Task 6 test `test_a_report_without_a_client_summary_still_renders_the_business_page`.
3. **A repository with zero finding groups / every dimension unmeasured** — charts render their empty state instead of dividing by zero. → Task 3 tests `test_severity_stack_with_no_issues_renders_an_empty_state`, `test_area_bars_draw_an_unmeasured_dimension_as_an_empty_track`, `test_business_area_bars_with_all_zero_counts_render_an_empty_state`.
4. **The developer PDF requested for a report unlocked before this change (`technical_pdf_path` null)** — generated on demand and streamed, not a 404. → Task 7 test `test_the_developer_pdf_is_generated_on_first_download_for_an_older_report`.
5. **Business page leaking technical identifiers** — a fixture's rule family and file path must not appear on the business page. → Task 6 test `test_the_business_page_shows_no_rule_families_or_file_paths`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Constants/ReportVariant.php` (new) | enum `business`/`technical`: route names, view names, PDF filenames, tab labels |
| `app/Services/AuditReport/ReportPayload.php` | v6 validation |
| `app/Services/AuditReport/ClaudeAnalyzer.php` | v6 output schema + prompt |
| `app/Services/AuditReport/ReportChartBuilder.php` (new) | numbers → SVG strings, no I/O |
| `app/Services/AuditReport/BusinessReportPresenter.php` (new) | business view model for web + PDF |
| `app/Http/Controllers/AuditReportController.php` | business/technical pages, samples, variant downloads |
| `app/Services/AuditReport/AuditReportService.php` | variant signed URLs, both PDFs |
| `app/Services/AuditRequestService.php` | delete both PDFs |
| `app/Console/Commands/RegenerateReportPdfs.php` (new) | one-off backfill of both PDFs |
| `database/migrations/2026_10_09_000001_add_technical_pdf_path_to_audit_reports_table.php` (new) | column |
| `resources/views/reports/technical-web.blade.php` | renamed from `audit-web`, plain-terms box removed |
| `resources/views/reports/technical-pdf.blade.php` | renamed from `audit`, plain-terms block removed |
| `resources/views/reports/business-web.blade.php` (new) | business page |
| `resources/views/reports/business-pdf.blade.php` (new) | business PDF |
| `resources/views/reports/partials/web/report-tabs.blade.php` (new) | tab switch |
| `resources/views/reports/partials/web/unlock-cta.blade.php` (new) | unlock CTA, shared by both pages |
| `resources/views/reports/partials/web/locked-text.blade.php` (new) | blurred placeholder + badge |
| `resources/views/reports/partials/chart.blade.php` (new) | inline SVG (web) or data-URI img (PDF) |
| `resources/views/reports/partials/web/client-summary.blade.php` | deleted |
| `resources/data/sample-audit-report.json` | v6 `client_summary` |
| `app/Mail/Audit/AuditReportReady.php`, `AuditReportUnlocked.php` + templates | two attachments, two links |
| Dashboard/admin download actions | two buttons |
| `tests/Support/FakeAiAnalyzer.php` | v6 output |

---

### Task 1: Payload v6 validation

**Files:**
- Modify: `app/Services/AuditReport/ReportPayload.php`
- Test: `tests/Unit/ReportPayloadTest.php`, `tests/Feature/Services/ReportPayloadTest.php`

**Interfaces:**
- Produces: `ReportPayload::VERSION === 6`; `ReportPayload::validate($payload)` accepts optional `client_summary.verdict` (string), `.areas[]{area, meaning, status}`, `.findings[].urgency` (`now|soon|later`), `.findings[].business_area` (`customers|costs|security|speed`), `.roadmap[]{step, outcome, effort S|M|L}`, `.questions[]` (strings). `areas[]` entries for dimensions absent from `scores` are **dropped** from the returned payload.
- Produces constants: `ReportPayload::URGENCIES`, `ReportPayload::BUSINESS_AREAS` (public, used by the presenter).

- [ ] **Step 1: Write the failing tests** — append to `tests/Unit/ReportPayloadTest.php`:

```php
    private function v6Summary(): array
    {
        return [
            'overview' => 'Plain overview.',
            'verdict' => 'Solid, but two risks need attention.',
            'areas' => [
                ['area' => 'testing', 'meaning' => 'Automatic checks.', 'status' => 'Barely any.'],
            ],
            'findings' => [[
                'what' => 'w', 'consequence' => 'c', 'gain' => 'g',
                'urgency' => 'now', 'business_area' => 'customers',
            ]],
            'roadmap' => [['step' => 'Add checks', 'outcome' => 'Fewer surprises', 'effort' => 'M']],
            'questions' => ['Which parts are tested?'],
        ];
    }

    public function test_accepts_a_v6_client_summary(): void
    {
        $payload = $this->valid() + ['client_summary' => $this->v6Summary()];

        $this->assertSame($payload, ReportPayload::validate($payload));
    }

    public function test_a_v5_client_summary_still_validates_under_v6(): void
    {
        $payload = $this->valid() + ['client_summary' => [
            'overview' => 'o',
            'findings' => [['what' => 'w', 'consequence' => 'c', 'gain' => 'g']],
        ]];

        $this->assertSame($payload, ReportPayload::validate($payload, 6));
    }

    public function test_rejects_an_unknown_urgency(): void
    {
        $summary = $this->v6Summary();
        $summary['findings'][0]['urgency'] = 'yesterday';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_an_unknown_business_area(): void
    {
        $summary = $this->v6Summary();
        $summary['findings'][0]['business_area'] = 'vibes';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_a_malformed_roadmap_step(): void
    {
        $summary = $this->v6Summary();
        $summary['roadmap'][0]['effort'] = 'XL';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_a_non_string_question(): void
    {
        $summary = $this->v6Summary();
        $summary['questions'] = [42];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_an_area_that_is_not_a_score_dimension(): void
    {
        $summary = $this->v6Summary();
        $summary['areas'][0]['area'] = 'overall';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_drops_an_area_for_a_dimension_that_was_not_measured(): void
    {
        $payload = $this->valid();
        unset($payload['scores']['testing']);
        $payload['client_summary'] = $this->v6Summary();

        $validated = ReportPayload::validate($payload);

        $this->assertSame([], $validated['client_summary']['areas']);
    }
```

Also rename/update the two existing version assertions: in `tests/Unit/ReportPayloadTest.php` rename `test_default_version_is_now_five` → `test_default_version_is_now_six` and change its expected value to `6`; in `tests/Feature/Services/ReportPayloadTest.php` rename `test_version_is_five` → `test_version_is_six` and change both `assertSame(5, ReportPayload::VERSION)` (lines 69 and 158) to `6`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/ReportPayloadTest.php tests/Feature/Services/ReportPayloadTest.php`
Expected: FAIL — version is 5; enum/shape violations not rejected; unmeasured area not dropped.

- [ ] **Step 3: Implement v6** in `app/Services/AuditReport/ReportPayload.php`:

Change `public const VERSION = 5;` to `public const VERSION = 6;`. Add constants below `EFFORTS`:

```php
    /** Score dimensions the plain-language areas may describe; never `overall`. */
    private const AREA_DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public const URGENCIES = ['now', 'soon', 'later'];

    public const BUSINESS_AREAS = ['customers', 'costs', 'security', 'speed'];
```

Add `6 => self::validateV6($payload),` to the `match`. Add after `validateV5()`:

```php
    private static function validateV6(array $payload): array
    {
        $payload = self::validateV5($payload);

        // Every v6 field is optional here for the same reason as every
        // section since v3: a v5 report must keep validating on view. The
        // Claude schema is where they are required.
        if (! array_key_exists('client_summary', $payload)) {
            return $payload;
        }

        $summary = $payload['client_summary'];

        if (array_key_exists('verdict', $summary) && ! is_string($summary['verdict'])) {
            throw new AiAnalysisException('Malformed client_summary verdict');
        }

        foreach ($summary['findings'] as $finding) {
            if (array_key_exists('urgency', $finding) && ! in_array($finding['urgency'], self::URGENCIES, true)) {
                throw new AiAnalysisException('Malformed client_summary finding: urgency');
            }

            if (array_key_exists('business_area', $finding) && ! in_array($finding['business_area'], self::BUSINESS_AREAS, true)) {
                throw new AiAnalysisException('Malformed client_summary finding: business_area');
            }
        }

        if (array_key_exists('areas', $summary)) {
            if (! is_array($summary['areas'])) {
                throw new AiAnalysisException('Malformed client_summary areas');
            }

            foreach ($summary['areas'] as $area) {
                if (! is_array($area)
                    || ! in_array($area['area'] ?? null, self::AREA_DIMENSIONS, true)
                    || ! is_string($area['meaning'] ?? null)
                    || ! is_string($area['status'] ?? null)) {
                    throw new AiAnalysisException('Malformed client_summary area entry');
                }
            }

            // A dimension absent from scores was not measured on this run.
            // Describing it would tell the owner something nobody checked,
            // so the entry goes -- the report itself is still sound.
            $payload['client_summary']['areas'] = array_values(array_filter(
                $summary['areas'],
                fn (array $area): bool => array_key_exists($area['area'], $payload['scores'] ?? []),
            ));
        }

        if (array_key_exists('roadmap', $summary)) {
            if (! is_array($summary['roadmap'])) {
                throw new AiAnalysisException('Malformed client_summary roadmap');
            }

            foreach ($summary['roadmap'] as $step) {
                if (! is_array($step)
                    || ! is_string($step['step'] ?? null)
                    || ! is_string($step['outcome'] ?? null)
                    || ! in_array($step['effort'] ?? null, self::EFFORTS, true)) {
                    throw new AiAnalysisException('Malformed client_summary roadmap step');
                }
            }
        }

        if (array_key_exists('questions', $summary)) {
            if (! is_array($summary['questions'])) {
                throw new AiAnalysisException('Malformed client_summary questions');
            }

            foreach ($summary['questions'] as $question) {
                if (! is_string($question)) {
                    throw new AiAnalysisException('Malformed client_summary question');
                }
            }
        }

        return $payload;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/ReportPayloadTest.php tests/Feature/Services/ReportPayloadTest.php`
Expected: PASS.

- [ ] **Step 5: Run the pipeline tests** (they assert `payload_schema_version === ReportPayload::VERSION`)

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Services/AuditPipelineTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AuditReport/ReportPayload.php backend/tests/Unit/ReportPayloadTest.php backend/tests/Feature/Services/ReportPayloadTest.php
git commit -m "feat(audit): payload v6 carries the business report's plain-language sections"
```

---

### Task 2: Claude schema + prompt, fake analyzer, sample fixture

**Files:**
- Modify: `app/Services/AuditReport/ClaudeAnalyzer.php`
- Modify: `tests/Support/FakeAiAnalyzer.php`
- Modify: `resources/data/sample-audit-report.json`
- Test: `tests/Unit/Services/AuditReport/ClaudeAnalyzerContractTest.php`

**Interfaces:**
- Consumes: v6 contract from Task 1.
- Produces: every new run's `client_summary` contains `overview, verdict, areas, findings[{what, consequence, gain, urgency, business_area}], roadmap, questions`. The sample fixture carries the same.

- [ ] **Step 1: Write the failing tests** — in `ClaudeAnalyzerContractTest.php` replace `test_the_output_schema_requires_a_plain_language_client_summary` and add two tests:

```php
    public function test_the_output_schema_requires_the_business_report_sections(): void
    {
        $schema = $this->schema();

        $this->assertContains('client_summary', $schema['required']);

        $summary = $schema['properties']['client_summary'];
        $this->assertSame(['overview', 'verdict', 'areas', 'findings', 'roadmap', 'questions'], $summary['required']);
        $this->assertFalse($summary['additionalProperties']);

        $finding = $summary['properties']['findings']['items'];
        $this->assertSame(['what', 'consequence', 'gain', 'urgency', 'business_area'], $finding['required']);
        $this->assertSame(ReportPayload::URGENCIES, $finding['properties']['urgency']['enum']);
        $this->assertSame(ReportPayload::BUSINESS_AREAS, $finding['properties']['business_area']['enum']);
        $this->assertFalse($finding['additionalProperties']);

        $area = $summary['properties']['areas']['items'];
        $this->assertSame(['area', 'meaning', 'status'], $area['required']);
        $this->assertNotContains('overall', $area['properties']['area']['enum']);

        $step = $summary['properties']['roadmap']['items'];
        $this->assertSame(['step', 'outcome', 'effort'], $step['required']);
        $this->assertSame(['S', 'M', 'L'], $step['properties']['effort']['enum']);

        $this->assertSame('string', $summary['properties']['questions']['items']['type']);
    }

    public function test_the_instructions_ask_for_every_business_section(): void
    {
        $prompt = $this->systemPrompt();

        foreach (['verdict', 'areas', 'urgency', 'business_area', 'roadmap', 'questions'] as $field) {
            $this->assertStringContainsString($field, $prompt);
        }
        // Areas must follow computed_scores, never describe an unmeasured one.
        $this->assertStringContainsString('computed_scores', $prompt);
    }
```

Update the payload in `test_a_payload_shaped_by_the_schema_satisfies_the_payload_contract` so `client_summary` is v6-complete:

```php
            'client_summary' => [
                'overview' => 'Plain words.',
                'verdict' => 'Sound, with gaps.',
                'areas' => [],
                'findings' => [['what' => 'w', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'soon', 'business_area' => 'costs']],
                'roadmap' => [['step' => 's', 'outcome' => 'o', 'effort' => 'S']],
                'questions' => ['q?'],
            ],
```

Add to `tests/Feature/Http/Controllers/AuditReportPageTest.php` (inside `test_the_sample_fixture_satisfies_the_current_payload_contract`, after the existing assertions):

```php
        foreach (['verdict', 'areas', 'roadmap', 'questions'] as $key) {
            $this->assertNotEmpty($validated['client_summary'][$key], "sample client_summary.{$key}");
        }
        foreach ($validated['client_summary']['findings'] as $finding) {
            $this->assertArrayHasKey('urgency', $finding);
            $this->assertArrayHasKey('business_area', $finding);
        }
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/Services/AuditReport/ClaudeAnalyzerContractTest.php --filter=business && docker compose exec laravel.test php artisan test --compact --filter=test_the_sample_fixture_satisfies`
Expected: FAIL (schema lacks fields; fixture lacks keys).

- [ ] **Step 3: Extend the schema** — in `ClaudeAnalyzer::SCHEMA` replace the whole `'client_summary' => [...]` entry with:

```php
            'client_summary' => [
                'type' => 'object',
                'properties' => [
                    'overview' => ['type' => 'string'],
                    'verdict' => ['type' => 'string'],
                    'areas' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'area' => ['type' => 'string', 'enum' => ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene']],
                                'meaning' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                            ],
                            'required' => ['area', 'meaning', 'status'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'findings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'what' => ['type' => 'string'],
                                'consequence' => ['type' => 'string'],
                                'gain' => ['type' => 'string'],
                                'urgency' => ['type' => 'string', 'enum' => ReportPayload::URGENCIES],
                                'business_area' => ['type' => 'string', 'enum' => ReportPayload::BUSINESS_AREAS],
                            ],
                            'required' => ['what', 'consequence', 'gain', 'urgency', 'business_area'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'roadmap' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'step' => ['type' => 'string'],
                                'outcome' => ['type' => 'string'],
                                'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                            ],
                            'required' => ['step', 'outcome', 'effort'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['overview', 'verdict', 'areas', 'findings', 'roadmap', 'questions'],
                'additionalProperties' => false,
            ],
```

(`ReportPayload::URGENCIES`/`BUSINESS_AREAS` are public constants, so they are legal in a class-constant expression.)

- [ ] **Step 4: Extend the prompt** — in `SYSTEM_PROMPT`, replace the final `client_summary` paragraph (from `The client_summary is written for…` to the end) with:

```
The client_summary is written for a non-technical reader -- the business owner who paid for
the audit, not their engineer. Plain everyday language in every field: no tool names, file
paths, rule ids, jargon or code.
- verdict: one headline sentence on where the codebase stands and what matters most.
- overview: two or three sentences on the overall state of the codebase.
- areas: one entry for each dimension present in computed_scores, and none for a dimension
  absent from it. meaning says in one sentence what that area is and why an owner should care;
  status says how this codebase is doing on it.
- findings: three to five, the ones that matter most to the business, each with what is wrong
  in plain terms, what problems it may cause them (lost customers, outages, slow or costly
  changes, security or legal exposure), and what they gain by fixing it. urgency is now (risk
  of harm today), soon (will hurt within months) or later (worth fixing when convenient).
  business_area is the one the finding most affects: customers, costs, security or speed (how
  fast the team can ship changes).
- roadmap: the fix-first plan, same order, each step restated as what will be done, the
  business outcome it produces, and its effort (S, M or L, matching the plan).
- questions: three to five questions the owner can ask their development team about these
  findings.
```

- [ ] **Step 5: Update the fake analyzer** — in `tests/Support/FakeAiAnalyzer.php` replace the `client_summary` entry with:

```php
                'client_summary' => [
                    'overview' => 'Fake plain-language overview.',
                    'verdict' => 'Fake verdict.',
                    'areas' => [['area' => 'testing', 'meaning' => 'Automatic checks.', 'status' => 'Very few.']],
                    'findings' => [['what' => 'Nothing is tested.', 'consequence' => 'Changes break things.', 'gain' => 'Fewer surprises.', 'urgency' => 'soon', 'business_area' => 'speed']],
                    'roadmap' => [['step' => 'Add checks', 'outcome' => 'Safer releases', 'effort' => 'S']],
                    'questions' => ['Which parts are tested today?'],
                ],
```

- [ ] **Step 6: Extend the sample fixture** — run this one-off script (it rewrites the JSON in place, preserving everything else):

```bash
docker compose exec laravel.test php -r '
$f = "resources/data/sample-audit-report.json";
$d = json_decode(file_get_contents($f), true);
$s = &$d["payload"]["client_summary"];
$s["verdict"] = "Your product works, but four gaps put revenue and customer trust at risk -- two of them need fixing this month.";
$s["areas"] = [
  ["area" => "structure", "meaning" => "How well the code is organised, which decides how quickly a developer can find and safely change things.", "status" => "Mixed: most of it is tidy, but a few oversized parts slow every change down."],
  ["area" => "duplication", "meaning" => "How often the same logic is copied instead of shared, which decides whether one fix fixes everything.", "status" => "Weak: important rules exist in several copies that already disagree."],
  ["area" => "testing", "meaning" => "How much is checked automatically before a release, which decides how often updates break things.", "status" => "Very weak: almost nothing is checked, so problems reach customers first."],
  ["area" => "dependencies", "meaning" => "The outside building blocks your product relies on, and whether they are current and safe.", "status" => "Fair: mostly up to date, with a few known security issues to patch."],
  ["area" => "security_hygiene", "meaning" => "Basic security habits, such as keeping passwords and keys out of the code.", "status" => "Needs work: some access keys are stored where they should not be."],
];
$meta = [["now", "customers"], ["now", "security"], ["soon", "costs"], ["soon", "speed"]];
foreach ($s["findings"] as $i => &$finding) { [$finding["urgency"], $finding["business_area"]] = $meta[$i]; }
unset($finding);
$s["roadmap"] = [
  ["step" => "Replace the exposed passwords and keys and move them out of the code", "outcome" => "Nobody with a copy of the code can get into your systems", "effort" => "S"],
  ["step" => "Add automatic checks around checkout and payments", "outcome" => "Payment bugs are caught before customers see them", "effort" => "M"],
  ["step" => "Merge the copied pricing rules into one", "outcome" => "Every customer pays the same, correct price", "effort" => "M"],
  ["step" => "Run those checks automatically on every change", "outcome" => "The improvements stay in place as the product grows", "effort" => "S"],
];
$s["questions"] = [
  "How do we confirm a payment really came from our payment provider?",
  "Where are our passwords and access keys kept, and who can see them?",
  "If we change a price rule, how many places have to be updated?",
  "What gets checked automatically before an update goes live?",
];
file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
'
```

Check `git diff --stat backend/resources/data/sample-audit-report.json` — only additions inside `client_summary` plus possible whitespace normalisation. If the diff rewrites unrelated lines (e.g. `—` escapes changed), that is acceptable as long as `json_decode` round-trips; confirm with the fixture test in Step 7.

- [ ] **Step 7: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/Services/AuditReport/ClaudeAnalyzerContractTest.php tests/Feature/Http/Controllers/AuditReportPageTest.php tests/Feature/Services/AuditPipelineTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/AuditReport/ClaudeAnalyzer.php backend/tests/Support/FakeAiAnalyzer.php backend/resources/data/sample-audit-report.json backend/tests/Unit/Services/AuditReport/ClaudeAnalyzerContractTest.php backend/tests/Feature/Http/Controllers/AuditReportPageTest.php
git commit -m "feat(audit): the analysis writes the business report's verdict, areas, roadmap and questions"
```

---

### Task 3: `ReportChartBuilder` — SVG charts

**Files:**
- Create: `app/Services/AuditReport/ReportChartBuilder.php`
- Test: `tests/Unit/Services/AuditReport/ReportChartBuilderTest.php`

**Interfaces:**
- Produces (all return a complete `<svg …>…</svg>` string with `role="img"` and a `<title>`):
  - `scoreGauge(int $score): string`
  - `percentileBar(int $percentile): string`
  - `areaBars(array<string,int> $scores, list<string> $notMeasured): string` — `$scores` without `overall`
  - `severityStack(array<string,int> $countsBySeverity): string` — keys `critical|high|medium|low|info`
  - `businessAreaBars(array<string,int> $counts): string` — keys `customers|costs|security|speed`
  - Constants: `ReportChartBuilder::AREA_LABELS` (dimension → plain label), `SEGMENT_LABELS` (`urgent|important|worth_fixing|minor` → label), `BUSINESS_AREA_LABELS`.

- [ ] **Step 1: Write the failing test** — `docker compose exec laravel.test php artisan make:test --phpunit --unit Services/AuditReport/ReportChartBuilderTest`, then fill it. The class only calls `__()` for labels, so extend `Tests\TestCase` (boots the app) rather than PHPUnit's bare `TestCase`:

```php
<?php

namespace Tests\Unit\Services\AuditReport;

use App\Services\AuditReport\ReportChartBuilder;
use Tests\TestCase;

class ReportChartBuilderTest extends TestCase
{
    private function charts(): ReportChartBuilder
    {
        return new ReportChartBuilder;
    }

    private function assertWellFormedSvg(string $svg): \SimpleXMLElement
    {
        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml, 'SVG must be well-formed XML');
        $this->assertSame('svg', $xml->getName());
        $this->assertSame('img', (string) $xml['role']);
        $this->assertNotSame('', trim((string) $xml->title));

        return $xml;
    }

    public function test_score_gauge_prints_the_score_and_uses_its_band_colour(): void
    {
        $svg = $this->charts()->scoreGauge(44);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString('>44<', $svg);
        $this->assertStringContainsString('#c55538', $svg); // critical band (< 50)
    }

    public function test_score_gauge_clamps_out_of_range_scores(): void
    {
        $this->assertStringContainsString('>100<', $this->charts()->scoreGauge(140));
        $this->assertStringContainsString('>0<', $this->charts()->scoreGauge(-5));
    }

    public function test_score_gauge_at_zero_draws_only_the_track(): void
    {
        $xml = $this->assertWellFormedSvg($this->charts()->scoreGauge(0));

        $this->assertCount(1, $xml->path);
    }

    public function test_percentile_bar_places_the_marker_proportionally(): void
    {
        $svg = $this->charts()->percentileBar(62);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString('cx="186"', $svg); // 62% of 300
    }

    public function test_area_bars_label_each_measured_dimension_in_plain_words(): void
    {
        $svg = $this->charts()->areaBars(['testing' => 12, 'structure' => 58], []);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::AREA_LABELS['testing'], $svg);
        $this->assertStringContainsString('>12<', $svg);
        $this->assertStringNotContainsString('security_hygiene', $svg);
    }

    public function test_area_bars_draw_an_unmeasured_dimension_as_an_empty_track(): void
    {
        $svg = $this->charts()->areaBars([], ['testing']);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('not checked'), $svg);
    }

    public function test_severity_stack_groups_severities_into_plain_segments(): void
    {
        $svg = $this->charts()->severityStack(['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 0, 'info' => 4]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::SEGMENT_LABELS['urgent'].' 3', $svg);
        $this->assertStringContainsString(ReportChartBuilder::SEGMENT_LABELS['important'].' 3', $svg);
        $this->assertStringNotContainsString(ReportChartBuilder::SEGMENT_LABELS['worth_fixing'], $svg);
    }

    public function test_severity_stack_with_no_issues_renders_an_empty_state(): void
    {
        $svg = $this->charts()->severityStack([]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('No issues found'), $svg);
    }

    public function test_business_area_bars_scale_to_the_largest_count(): void
    {
        $svg = $this->charts()->businessAreaBars(['customers' => 2, 'costs' => 1, 'security' => 0, 'speed' => 0]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::BUSINESS_AREA_LABELS['customers'], $svg);
        $this->assertStringContainsString('width="90"', $svg); // costs: 1 of max 2 fills half the 180px track
    }

    public function test_business_area_bars_with_all_zero_counts_render_an_empty_state(): void
    {
        $svg = $this->charts()->businessAreaBars(['customers' => 0]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('Nothing to show'), $svg);
    }

    public function test_labels_are_escaped(): void
    {
        $this->assertWellFormedSvg($this->charts()->areaBars(['structure' => 50], []));
        $this->assertStringNotContainsString('&amp;amp;', $this->charts()->businessAreaBars(['security' => 1]));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/Services/AuditReport/ReportChartBuilderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** `app/Services/AuditReport/ReportChartBuilder.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Support\ScoreBand;

/**
 * The business report's charts, as SVG strings: numbers in, markup out, no
 * I/O. The same string goes inline on the web page and, base64'd, into the
 * PDF -- dompdf runs no JavaScript, so this is the one way a chart can look
 * the same in both. Keep every chart to rect/path/circle/text: dompdf's SVG
 * renderer handles nothing fancier.
 */
class ReportChartBuilder
{
    /** Print-safe brand colours (the light theme's values). */
    private const BAND_COLORS = ['critical' => '#c55538', 'watch' => '#a8712b', 'healthy' => '#b98e41'];

    private const TRACK = '#e8e2d6';

    private const INK = '#1c1917';

    private const MUTED = '#78716c';

    private const FONT = 'font-family="DejaVu Sans, Helvetica, Arial, sans-serif"';

    public const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public const AREA_LABELS = [
        'structure' => 'Code organisation',
        'duplication' => 'Repeated code',
        'testing' => 'Automatic checks',
        'dependencies' => 'Third-party parts',
        'security_hygiene' => 'Security basics',
    ];

    /** Plain-language segments, worst first, and the severities each covers. */
    private const SEGMENTS = [
        'urgent' => ['critical', 'high'],
        'important' => ['medium'],
        'worth_fixing' => ['low'],
        'minor' => ['info'],
    ];

    public const SEGMENT_LABELS = [
        'urgent' => 'Urgent',
        'important' => 'Important',
        'worth_fixing' => 'Worth fixing',
        'minor' => 'Minor',
    ];

    private const SEGMENT_COLORS = [
        'urgent' => '#c55538',
        'important' => '#c98a3b',
        'worth_fixing' => '#b98e41',
        'minor' => '#a8a29e',
    ];

    public const BUSINESS_AREA_LABELS = [
        'customers' => 'Your customers',
        'costs' => 'Running costs',
        'security' => 'Security & compliance',
        'speed' => 'Speed of change',
    ];

    public function scoreGauge(int $score): string
    {
        $score = max(0, min(100, $score));
        $color = self::BAND_COLORS[ScoreBand::fromScore($score)->value];

        // Semicircle centred on (100,100), radius 80, drawn left to right
        // over the top. sweep-flag 1 is clockwise on screen.
        $body = '<path d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="'.self::TRACK.'" stroke-width="16" stroke-linecap="round"/>';

        if ($score > 0) {
            $angle = M_PI * (1 - $score / 100);
            $x = round(100 + 80 * cos($angle), 2);
            $y = round(100 - 80 * sin($angle), 2);
            $body .= sprintf('<path d="M 20 100 A 80 80 0 0 1 %s %s" fill="none" stroke="%s" stroke-width="16" stroke-linecap="round"/>', $x, $y, $color);
        }

        $body .= sprintf('<text x="100" y="92" text-anchor="middle" font-size="40" font-weight="bold" fill="%s" %s>%d</text>', self::INK, self::FONT, $score);
        $body .= sprintf('<text x="100" y="114" text-anchor="middle" font-size="11" fill="%s" %s>%s</text>', self::MUTED, self::FONT, $this->e(__('out of 100')));

        return $this->svg(__('Overall health: :score out of 100', ['score' => $score]), 200, 120, $body);
    }

    public function percentileBar(int $percentile): string
    {
        $percentile = max(0, min(100, $percentile));
        $x = $percentile * 3;

        $body = sprintf('<rect x="0" y="10" width="300" height="8" rx="4" fill="%s"/>', self::TRACK);
        $body .= sprintf('<rect x="0" y="10" width="%d" height="8" rx="4" fill="%s"/>', $x, self::BAND_COLORS['healthy']);
        $body .= sprintf('<circle cx="%d" cy="14" r="7" fill="%s"/>', $x, self::INK);

        return $this->svg(__('Healthier than :p% of audited codebases', ['p' => $percentile]), 300, 28, $body);
    }

    /**
     * @param  array<string, int>  $scores  dimension => 0-100, without `overall`
     * @param  list<string>  $notMeasured
     */
    public function areaBars(array $scores, array $notMeasured): string
    {
        $rows = array_values(array_filter(
            self::DIMENSIONS,
            fn (string $d): bool => array_key_exists($d, $scores) || in_array($d, $notMeasured, true),
        ));

        if ($rows === []) {
            return $this->emptyState(__('Nothing to show'), 300);
        }

        $body = '';
        foreach ($rows as $i => $dimension) {
            $y = $i * 30;
            $body .= sprintf('<text x="0" y="%d" font-size="12" fill="%s" %s>%s</text>', $y + 15, self::INK, self::FONT, $this->e(__(self::AREA_LABELS[$dimension])));
            $body .= sprintf('<rect x="140" y="%d" width="160" height="10" rx="5" fill="%s"/>', $y + 6, self::TRACK);

            if (array_key_exists($dimension, $scores)) {
                $score = max(0, min(100, (int) $scores[$dimension]));
                $color = self::BAND_COLORS[ScoreBand::fromScore($score)->value];
                $body .= sprintf('<rect x="140" y="%d" width="%s" height="10" rx="5" fill="%s"/>', $y + 6, round($score * 1.6, 2), $color);
                $body .= sprintf('<text x="340" y="%d" text-anchor="end" font-size="12" font-weight="bold" fill="%s" %s>%d</text>', $y + 15, self::INK, self::FONT, $score);
            } else {
                $body .= sprintf('<text x="340" y="%d" text-anchor="end" font-size="10" fill="%s" %s>%s</text>', $y + 15, self::MUTED, self::FONT, $this->e(__('not checked')));
            }
        }

        return $this->svg(__('Health by area'), 340, count($rows) * 30, $body);
    }

    /** @param  array<string, int>  $countsBySeverity */
    public function severityStack(array $countsBySeverity): string
    {
        $segments = [];
        foreach (self::SEGMENTS as $segment => $severities) {
            $count = array_sum(array_map(fn (string $s): int => (int) ($countsBySeverity[$s] ?? 0), $severities));
            if ($count > 0) {
                $segments[$segment] = $count;
            }
        }

        $total = array_sum($segments);
        if ($total === 0) {
            return $this->emptyState(__('No issues found'), 300);
        }

        $body = '';
        $x = 0.0;
        foreach ($segments as $segment => $count) {
            $width = round(300 * $count / $total, 2);
            $body .= sprintf('<rect x="%s" y="0" width="%s" height="18" fill="%s"/>', $x, $width, self::SEGMENT_COLORS[$segment]);
            $x += $width;
        }

        $legendX = 0;
        foreach ($segments as $segment => $count) {
            $body .= sprintf('<rect x="%d" y="30" width="10" height="10" fill="%s"/>', $legendX, self::SEGMENT_COLORS[$segment]);
            $body .= sprintf('<text x="%d" y="39" font-size="11" fill="%s" %s>%s %d</text>', $legendX + 14, self::INK, self::FONT, $this->e(__(self::SEGMENT_LABELS[$segment])), $count);
            $legendX += 100;
        }

        return $this->svg(__(':n problem areas by seriousness', ['n' => $total]), max(300, $legendX), 46, $body);
    }

    /** @param  array<string, int>  $counts */
    public function businessAreaBars(array $counts): string
    {
        $max = max([0, ...array_values(array_map('intval', $counts))]);
        if ($max === 0) {
            return $this->emptyState(__('Nothing to show'), 300);
        }

        $body = '';
        $i = 0;
        foreach (self::BUSINESS_AREA_LABELS as $area => $label) {
            $count = (int) ($counts[$area] ?? 0);
            $y = $i * 30;
            $body .= sprintf('<text x="0" y="%d" font-size="12" fill="%s" %s>%s</text>', $y + 15, self::INK, self::FONT, $this->e(__($label)));
            $body .= sprintf('<rect x="150" y="%d" width="180" height="12" rx="6" fill="%s"/>', $y + 5, self::TRACK);
            if ($count > 0) {
                $body .= sprintf('<rect x="150" y="%d" width="%s" height="12" rx="6" fill="%s"/>', $y + 5, round(180 * $count / $max, 2), self::BAND_COLORS['healthy']);
            }
            $body .= sprintf('<text x="360" y="%d" text-anchor="end" font-size="12" font-weight="bold" fill="%s" %s>%d</text>', $y + 15, self::INK, self::FONT, $count);
            $i++;
        }

        return $this->svg(__('Findings by part of your business'), 360, $i * 30, $body);
    }

    private function emptyState(string $message, int $width): string
    {
        $body = sprintf('<text x="0" y="16" font-size="12" fill="%s" %s>%s</text>', self::MUTED, self::FONT, $this->e($message));

        return $this->svg($message, $width, 24, $body);
    }

    private function svg(string $title, int $width, int $height, string $body): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img"><title>%3$s</title>%4$s</svg>',
            $width, $height, $this->e($title), $body,
        );
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Unit/Services/AuditReport/ReportChartBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AuditReport/ReportChartBuilder.php backend/tests/Unit/Services/AuditReport/ReportChartBuilderTest.php
git commit -m "feat(audit): server-rendered SVG charts for the business report"
```

---

### Task 4: `BusinessReportPresenter`

**Files:**
- Create: `app/Services/AuditReport/BusinessReportPresenter.php`
- Test: `tests/Feature/Services/AuditReport/BusinessReportPresenterTest.php`

**Interfaces:**
- Consumes: `ReportChartBuilder` (Task 3), `ReportPayload::BUSINESS_AREAS` (Task 1), `ScoreBand`.
- Produces: `BusinessReportPresenter::present(AuditReport $report, ?array $deltas, ?int $percentile, Collection $groups): array` returning:

```
overall: int, band: ScoreBand,
verdict: ?string, overview: ?string, hasClientSummary: bool,
delta: ?int, previousAt: ?Carbon,
percentile: ?int,
areas: list<array{key: string, label: string, score: ?int, meaning: ?string, status: ?string}>,
findings: list<array{what, consequence, gain, urgency: ?string, urgency_label: ?string, business_area: ?string}>,
roadmap: ?list<array{step, outcome, effort, effort_label}>,
questions: ?list<string>,
expertSummary: ?string,
charts: array{gauge: string, percentile: ?string, areas: string, severity: string, businessAreas: ?string}
```

`$groups` is the request's `AuditFindingGroup` collection; when empty (sample, old reports) severity counts fall back to `payload.groups`. `businessAreas` chart and `roadmap`/`questions` are `null` when the payload lacks those v6 fields.

- [ ] **Step 1: Write the failing test** (`php artisan make:test --phpunit Services/AuditReport/BusinessReportPresenterTest`):

```php
<?php

namespace Tests\Feature\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Services\AuditReport\BusinessReportPresenter;
use App\Support\ScoreBand;
use Tests\Feature\FeatureTest;

class BusinessReportPresenterTest extends FeatureTest
{
    private function v6Payload(): array
    {
        return AuditReport::factory()->definition()['payload'] + [
            'groups' => [['rule_family' => 'php:unused', 'directory' => 'app', 'severity' => 'medium', 'count' => 4, 'narrative' => ['what' => 'w', 'affects' => 'a', 'benefit' => 'b']]],
            'client_summary' => [
                'overview' => 'Overview.',
                'verdict' => 'Verdict.',
                'areas' => [['area' => 'testing', 'meaning' => 'Checks.', 'status' => 'Few.']],
                'findings' => [
                    ['what' => 'A', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'now', 'business_area' => 'customers'],
                    ['what' => 'B', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'later', 'business_area' => 'customers'],
                ],
                'roadmap' => [['step' => 'S1', 'outcome' => 'O1', 'effort' => 'L']],
                'questions' => ['Q1?'],
            ],
        ];
    }

    private function present(AuditReport $report): array
    {
        return app(BusinessReportPresenter::class)->present($report, null, 40, $report->auditRequest->findingGroups);
    }

    public function test_presents_a_v6_report(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $view = $this->present($report);

        $this->assertSame(55, $view['overall']);
        $this->assertSame(ScoreBand::WATCH, $view['band']);
        $this->assertSame('Verdict.', $view['verdict']);
        $this->assertTrue($view['hasClientSummary']);
        $this->assertSame('now', $view['findings'][0]['urgency']);
        $this->assertSame(__('Fix now'), $view['findings'][0]['urgency_label']);
        $this->assertSame(__('A month or more'), $view['roadmap'][0]['effort_label']);
        $this->assertSame(['Q1?'], $view['questions']);
        $this->assertNotNull($view['charts']['businessAreas']);
        $this->assertNotNull($view['charts']['percentile']);

        $testing = collect($view['areas'])->firstWhere('key', 'testing');
        $this->assertSame(20, $testing['score']);
        $this->assertSame('Checks.', $testing['meaning']);
        $this->assertNull(collect($view['areas'])->firstWhere('key', 'structure')['meaning']);
    }

    public function test_severity_counts_come_from_the_stored_finding_groups(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);
        AuditFindingGroup::factory()->create(['audit_request_id' => $report->audit_request_id, 'severity' => 'critical', 'count' => 7]);

        $view = $this->present($report->fresh());

        $this->assertStringContainsString(__('Urgent').' 1', $view['charts']['severity']);
    }

    public function test_severity_counts_fall_back_to_the_payload_groups(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $view = $this->present($report);

        $this->assertStringContainsString(__('Important').' 1', $view['charts']['severity']);
    }

    public function test_a_v5_summary_has_no_roadmap_questions_or_business_area_chart(): void
    {
        $payload = $this->v6Payload();
        $payload['client_summary'] = ['overview' => 'Old.', 'findings' => [['what' => 'A', 'consequence' => 'c', 'gain' => 'g']]];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $view = $this->present($report);

        $this->assertNull($view['verdict']);
        $this->assertSame('Old.', $view['overview']);
        $this->assertNull($view['findings'][0]['urgency']);
        $this->assertNull($view['roadmap']);
        $this->assertNull($view['questions']);
        $this->assertNull($view['charts']['businessAreas']);
    }

    public function test_a_report_without_a_client_summary(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $view = $this->present($report);

        $this->assertFalse($view['hasClientSummary']);
        $this->assertNull($view['overview']);
        $this->assertSame([], $view['findings']);
        $this->assertNotSame('', $view['charts']['gauge']);
    }

    public function test_unmeasured_dimensions_are_listed_without_a_score(): void
    {
        $payload = $this->v6Payload();
        unset($payload['scores']['testing']);
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);
        $report->auditRequest->update(['metrics' => ['not_measured' => ['testing']]]);

        $view = $this->present($report->fresh());

        $this->assertNull(collect($view['areas'])->firstWhere('key', 'testing')['score']);
    }

    public function test_expert_summary_is_exposed_without_the_review_notes(): void
    {
        $payload = $this->v6Payload() + ['expert_review' => ['expert_summary' => 'Looks fixable.', 'review_notes' => 'Internal.', 'reviewed_by' => 'R', 'reviewed_at' => '2026-10-01T00:00:00Z']];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $view = $this->present($report);

        $this->assertSame('Looks fixable.', $view['expertSummary']);
        $this->assertStringNotContainsString('Internal.', json_encode($view));
    }
}
```

If `AuditFindingGroup::factory()` does not exist (`ls backend/database/factories | grep FindingGroup`), create the row directly with `AuditFindingGroup::create([...])` supplying `rule_family`, `directory`, `severity`, `dimension` (`'structure'`), `count`, `score` (`0`), `examples` (`[]`), `tools` (`[]`).

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Services/AuditReport/BusinessReportPresenterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** `app/Services/AuditReport/BusinessReportPresenter.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditReport;
use App\Support\ScoreBand;
use Illuminate\Support\Collection;

/**
 * Assembles everything the business report shows, once, for both the web
 * page and the PDF -- so the two can never disagree about a number.
 *
 * Every v6 field is optional on stored payloads; whatever is missing comes
 * back null and the views hide that section.
 */
class BusinessReportPresenter
{
    public function __construct(private ReportChartBuilder $charts) {}

    /**
     * @param  array{previous_at: \Illuminate\Support\Carbon, deltas: array<string, int>}|null  $deltas
     * @param  Collection<int, \App\Models\AuditFindingGroup>  $groups
     * @return array<string, mixed>
     */
    public function present(AuditReport $report, ?array $deltas, ?int $percentile, Collection $groups): array
    {
        $payload = $report->payload;
        $summary = $payload['client_summary'] ?? null;
        $scores = array_diff_key($payload['scores'] ?? [], ['overall' => true]);
        $notMeasured = (array) ($report->auditRequest?->metrics['not_measured'] ?? []);
        $overall = (int) ($payload['scores']['overall'] ?? 0);
        $findings = $this->findings($summary['findings'] ?? []);
        $hasBusinessAreas = collect($findings)->contains(fn (array $f): bool => $f['business_area'] !== null);

        return [
            'overall' => $overall,
            'band' => ScoreBand::fromScore($overall),
            'verdict' => $summary['verdict'] ?? null,
            'overview' => $summary['overview'] ?? null,
            'hasClientSummary' => $summary !== null,
            'delta' => ($deltas['deltas']['overall'] ?? 0) !== 0 ? $deltas['deltas']['overall'] : null,
            'previousAt' => $deltas['previous_at'] ?? null,
            'percentile' => $percentile,
            'areas' => $this->areas($scores, $notMeasured, $summary['areas'] ?? []),
            'findings' => $findings,
            'roadmap' => isset($summary['roadmap']) ? $this->roadmap($summary['roadmap']) : null,
            'questions' => $summary['questions'] ?? null,
            'expertSummary' => $payload['expert_review']['expert_summary'] ?? null,
            'charts' => [
                'gauge' => $this->charts->scoreGauge($overall),
                'percentile' => $percentile !== null ? $this->charts->percentileBar($percentile) : null,
                'areas' => $this->charts->areaBars($scores, $notMeasured),
                'severity' => $this->charts->severityStack($this->severityCounts($groups, $payload['groups'] ?? [])),
                'businessAreas' => $hasBusinessAreas ? $this->charts->businessAreaBars($this->businessAreaCounts($findings)) : null,
            ],
        ];
    }

    /** @return list<array{key: string, label: string, score: ?int, meaning: ?string, status: ?string}> */
    private function areas(array $scores, array $notMeasured, array $described): array
    {
        $text = collect($described)->keyBy('area');
        $areas = [];

        foreach (ReportChartBuilder::DIMENSIONS as $dimension) {
            if (! array_key_exists($dimension, $scores) && ! in_array($dimension, $notMeasured, true)) {
                continue;
            }

            $areas[] = [
                'key' => $dimension,
                'label' => __(ReportChartBuilder::AREA_LABELS[$dimension]),
                'score' => array_key_exists($dimension, $scores) ? (int) $scores[$dimension] : null,
                'meaning' => $text[$dimension]['meaning'] ?? null,
                'status' => $text[$dimension]['status'] ?? null,
            ];
        }

        return $areas;
    }

    private function findings(array $findings): array
    {
        return array_map(fn (array $f): array => [
            'what' => $f['what'],
            'consequence' => $f['consequence'],
            'gain' => $f['gain'],
            'urgency' => $f['urgency'] ?? null,
            'urgency_label' => match ($f['urgency'] ?? null) {
                'now' => __('Fix now'),
                'soon' => __('Fix soon'),
                'later' => __('Can wait'),
                default => null,
            },
            'business_area' => $f['business_area'] ?? null,
        ], $findings);
    }

    private function roadmap(array $steps): array
    {
        return array_map(fn (array $s): array => $s + [
            'effort_label' => match ($s['effort']) {
                'S' => __('A few days'),
                'M' => __('A couple of weeks'),
                default => __('A month or more'),
            },
        ], $steps);
    }

    /** @return array<string, int> */
    private function severityCounts(Collection $groups, array $payloadGroups): array
    {
        // One problem area is one group, however many lines it touches: an
        // owner reads "3 urgent problems", not "214 lint hits".
        $source = $groups->isNotEmpty()
            ? $groups->map(fn ($g): string => $g->severity)
            : collect($payloadGroups)->pluck('severity');

        return $source->countBy()->all();
    }

    /** @return array<string, int> */
    private function businessAreaCounts(array $findings): array
    {
        return collect($findings)->pluck('business_area')->filter()->countBy()->all();
    }
}
```

Note: severity counts are **groups per severity**, not finding counts — the test `test_severity_counts_come_from_the_stored_finding_groups` asserts `Urgent 1` for one critical group with `count => 7`. This matches the business framing ("problem areas").

- [ ] **Step 4: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Services/AuditReport/BusinessReportPresenterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AuditReport/BusinessReportPresenter.php backend/tests/Feature/Services/AuditReport/BusinessReportPresenterTest.php
git commit -m "feat(audit): one presenter builds the business report for web and PDF"
```

---

### Task 5: `ReportVariant`, developer page route, and the technical views

Today's page becomes the developer report at `/reports/{uuid}/technical`. `/reports/{uuid}` keeps rendering the technical view until Task 6 swaps it — the suite stays green between tasks.

**Files:**
- Create: `app/Constants/ReportVariant.php`
- Rename: `resources/views/reports/audit-web.blade.php` → `technical-web.blade.php`; `resources/views/reports/audit.blade.php` → `technical-pdf.blade.php` (use `git mv`)
- Create: `resources/views/reports/partials/web/report-tabs.blade.php`, `resources/views/reports/partials/web/unlock-cta.blade.php`
- Delete: `resources/views/reports/partials/web/client-summary.blade.php`
- Modify: `routes/web.php:244-257`, `app/Http/Controllers/AuditReportController.php`, `app/Services/AuditReport/AuditReportService.php` (`signedUrl`, `generatePdf` view name)
- Test: `tests/Feature/Http/Controllers/AuditReportTechnicalPageTest.php` (new); update `AuditReportPageTest.php`, `PdfRenderTest.php`

**Interfaces:**
- Produces `App\Constants\ReportVariant` enum (`BUSINESS = 'business'`, `TECHNICAL = 'technical'`) with `routeName(): string`, `sampleRouteName(): string`, `webView(): string`, `pdfView(): string`, `pdfFilename(): string`, `pdfSuffix(): string`, `tabLabel(): string`.
- Produces `AuditReportService::signedUrl(AuditReport $report, ReportVariant $variant = ReportVariant::BUSINESS): string`.
- Produces routes `reports.view.technical` (signed), `reports.sample.technical`.
- Views receive `$variant` (ReportVariant) and `$tabUrls` (`array{business: string, technical: string}`).

- [ ] **Step 1: Write the failing test** — `php artisan make:test --phpunit Http/Controllers/AuditReportTechnicalPageTest`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Constants\ReportVariant;
use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportTechnicalPageTest extends FeatureTest
{
    private function technicalUrl(AuditReport $report): string
    {
        return app(AuditReportService::class)->signedUrl($report, ReportVariant::TECHNICAL);
    }

    public function test_the_developer_report_shows_every_technical_section(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $this->get($this->technicalUrl($report))
            ->assertOk()
            ->assertSee(__('Health scores'))
            ->assertSee(__('Risks, ranked by impact'))
            ->assertSee('Add a smoke suite')
            ->assertSee(__('What to fix first'));
    }

    public function test_the_developer_report_has_no_plain_terms_box(): void
    {
        $report = AuditReport::factory()->unlocked()->create([
            'payload' => AuditReport::factory()->definition()['payload'] + ['client_summary' => ['overview' => 'Owner-facing overview.', 'findings' => []]],
        ]);

        $this->get($this->technicalUrl($report))
            ->assertOk()
            ->assertDontSee(__('In plain terms'))
            ->assertDontSee('Owner-facing overview.');
    }

    public function test_the_developer_report_needs_a_signature(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->unlocked()->create();

        $this->get(route('reports.view.technical', $report))->assertForbidden();
    }

    public function test_the_developer_report_is_hidden_while_held_for_expert_review(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->unlocked()->create();
        $report->auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->get($this->technicalUrl($report))->assertForbidden();
    }

    public function test_tabs_link_both_reports_with_signed_urls(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $html = $this->get($this->technicalUrl($report))->assertOk()->getContent();

        $this->assertStringContainsString(__('Business overview'), $html);
        $this->assertStringContainsString(__('Developer report'), $html);
        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'\?[^"]*signature=#', $html);
        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'/technical\?[^"]*signature=#', $html);
    }

    public function test_the_sample_developer_report_is_public(): void
    {
        $this->get('/reports/sample/technical')
            ->assertOk()
            ->assertSee(__('Sample report'))
            ->assertSee(__('Deep file review'))
            ->assertDontSee(__('In plain terms'));
    }
}
```

The signed-link 403 assertion: check what `SignedLinkFailureTest` expects for an unsigned `reports.view` (it may render a friendly "link problem" page with a different status). Mirror that status/text for `reports.view.technical` instead of `assertForbidden()` if they differ — the `RepairHtmlEscapedQueryString` + `signed` middleware pair must be identical on both routes.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers/AuditReportTechnicalPageTest.php`
Expected: FAIL — `ReportVariant` not found.

- [ ] **Step 3: Create the enum** `app/Constants/ReportVariant.php`:

```php
<?php

namespace App\Constants;

/**
 * The two reports one audit produces: the business report for the owner and
 * the developer report for their engineer. One unlock opens both.
 */
enum ReportVariant: string
{
    case BUSINESS = 'business';
    case TECHNICAL = 'technical';

    public function routeName(): string
    {
        return match ($this) {
            self::BUSINESS => 'reports.view',
            self::TECHNICAL => 'reports.view.technical',
        };
    }

    public function sampleRouteName(): string
    {
        return match ($this) {
            self::BUSINESS => 'reports.sample',
            self::TECHNICAL => 'reports.sample.technical',
        };
    }

    public function webView(): string
    {
        return 'reports.'.$this->value.'-web';
    }

    public function pdfView(): string
    {
        return 'reports.'.$this->value.'-pdf';
    }

    /** Appended to the uuid in the stored PDF's path. */
    public function pdfSuffix(): string
    {
        return $this === self::BUSINESS ? '' : '-technical';
    }

    public function pdfFilename(): string
    {
        return match ($this) {
            self::BUSINESS => 'codebase-health-business.pdf',
            self::TECHNICAL => 'codebase-health-developer.pdf',
        };
    }

    public function tabLabel(): string
    {
        return match ($this) {
            self::BUSINESS => __('Business overview'),
            self::TECHNICAL => __('Developer report'),
        };
    }
}
```

- [ ] **Step 4: Rename views and remove the plain-terms box**

```bash
cd backend
git mv resources/views/reports/audit-web.blade.php resources/views/reports/technical-web.blade.php
git mv resources/views/reports/audit.blade.php resources/views/reports/technical-pdf.blade.php
git rm resources/views/reports/partials/web/client-summary.blade.php
```

In `technical-web.blade.php` delete the block:

```blade
    @if (($payload['client_summary'] ?? null) !== null)
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
            @include('reports.partials.web.client-summary', ['payload' => $payload, 'unlocked' => $unlocked])
        </div>
    @endif
```

In `technical-pdf.blade.php` delete the `@if (($payload['client_summary'] ?? null) !== null) … @endif` block (the "In plain terms" section). Change both `<title>` and `<h1>` texts to `{{ __('Codebase Health — Developer Report') }}`.

In `technical-web.blade.php`, insert the tabs right after the `@if ($isSample) … @endif` sample banner block:

```blade
    @include('reports.partials.web.report-tabs')
```

Then extract the locked CTA. Replace the `@else <div class="rounded-xl bg-stone-900 …"> … </div>` branch at the bottom with `@else @include('reports.partials.web.unlock-cta')`, and create `resources/views/reports/partials/web/unlock-cta.blade.php` holding exactly the removed markup:

```blade
{{-- Shared by both reports: one unlock opens the business and the developer report. --}}
<div class="rounded-xl bg-stone-900 p-7 text-center text-stone-50 mb-5">
    <h2 class="text-base font-bold mb-3">{{ __('Unlock full report') }}</h2>
    <p class="text-stone-300">{{ __('Get every finding\'s evidence and recommendation, the prioritized fix-first plan, and PDF export.') }}</p>
    <a class="inline-block rounded-lg bg-primary-500 px-6 py-3 font-bold text-stone-900 no-underline" href="{{ $unlockUrl }}">{{ ($quoteCatalogPrices ?? true) ? __('Unlock for $5') : __('Unlock the full report') }}</a>
    @php($cheapestPlan = collect(config('pricing.subscriptions'))->sortBy('price')->first())
    <a class="inline-block rounded-lg border border-stone-600 px-6 py-3 font-bold text-stone-50 no-underline" href="{{ route('register') }}">{{ ($quoteCatalogPrices ?? true) ? __('Or subscribe from $:price/mo', ['price' => number_format(($cheapestPlan['price'] ?? 0) / 100)]) : __('Or subscribe to a plan') }}</a>
</div>
```

Create `resources/views/reports/partials/web/report-tabs.blade.php`:

```blade
{{-- Switches between the two reports of one audit. $tabUrls holds a signed
     URL for each, so a reader never hits an expired or unsigned link. --}}
<nav class="mb-5 flex gap-1 rounded-xl border border-stone-200 bg-white p-1 text-sm font-semibold" aria-label="{{ __('Report version') }}">
    @foreach (\App\Constants\ReportVariant::cases() as $tab)
        <a href="{{ $tabUrls[$tab->value] }}"
           @if ($tab === $variant) aria-current="page" @endif
           @class([
               'flex-1 rounded-lg px-4 py-2 text-center no-underline',
               'bg-stone-900 text-stone-50' => $tab === $variant,
               'text-stone-600 hover:bg-stone-100' => $tab !== $variant,
           ])>{{ $tab->tabLabel() }}</a>
    @endforeach
</nav>
```

- [ ] **Step 5: Routes** — in `routes/web.php` replace the `reports.sample` / `reports.view` block with (sample routes **first**, so `sample` is never bound as a uuid):

```php
Route::get('/reports/sample', [AuditReportController::class, 'sample'])->name('reports.sample');
Route::get('/reports/sample/technical', [AuditReportController::class, 'sampleTechnical'])->name('reports.sample.technical');

Route::get('/reports/{auditReport:uuid}', [AuditReportController::class, 'show'])
    ->name('reports.view')
    ->middleware([RepairHtmlEscapedQueryString::class, 'signed']);

Route::get('/reports/{auditReport:uuid}/technical', [AuditReportController::class, 'showTechnical'])
    ->name('reports.view.technical')
    ->middleware([RepairHtmlEscapedQueryString::class, 'signed']);
```

(Leave `reports.download` and `reports.unlock` untouched in this task.)

- [ ] **Step 6: Service** — in `AuditReportService` add `use App\Constants\ReportVariant;` and change `signedUrl`:

```php
    public function signedUrl(AuditReport $report, ReportVariant $variant = ReportVariant::BUSINESS): string
    {
        return URL::temporarySignedRoute(
            $variant->routeName(),
            now()->addDays((int) config('audit.report_link_days')),
            ['auditReport' => $report->uuid],
        );
    }
```

and in `generatePdf()` change `Pdf::loadView('reports.audit', …)` to `Pdf::loadView(ReportVariant::TECHNICAL->pdfView(), …)` (Task 7 replaces this method).

- [ ] **Step 7: Controller** — rewrite `show()` and `sample()` around a variant (add `use App\Constants\ReportVariant;`):

```php
    public function show(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        return $this->renderReport($auditReport, $benchmark, ReportVariant::BUSINESS);
    }

    public function showTechnical(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        return $this->renderReport($auditReport, $benchmark, ReportVariant::TECHNICAL);
    }

    private function renderReport(AuditReport $auditReport, AuditBenchmarkService $benchmark, ReportVariant $variant)
    {
        // (keep the existing expert-hold comment here)
        abort_if($auditReport->auditRequest->isHeldForExpertReview(), 403);

        if ($auditReport->auditRequest->source !== 'dashboard') {
            app(AuditFunnelRecorder::class)->record(
                AuditFunnelRecorder::STAGE_REPORT_VIEWED,
                $auditReport->auditRequest,
                ['unlocked' => $auditReport->unlocked_at !== null, 'variant' => $variant->value],
            );
        }

        $reports = app(AuditReportService::class);

        // Until Task 6 ships the business view, both routes render the
        // developer report.
        return view(ReportVariant::TECHNICAL->webView(), [
            'report' => $auditReport,
            'variant' => $variant,
            'tabUrls' => [
                ReportVariant::BUSINESS->value => $reports->signedUrl($auditReport, ReportVariant::BUSINESS),
                ReportVariant::TECHNICAL->value => $reports->signedUrl($auditReport, ReportVariant::TECHNICAL),
            ],
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            // (keep the existing referred-reader comment)
            'quoteCatalogPrices' => ! $this->isReferred($auditReport),
            'percentile' => $benchmark->percentileFor((int) data_get($auditReport->payload, 'scores.overall', 0), $auditReport->scoring_version),
            'unlockUrl' => URL::temporarySignedRoute(
                'reports.unlock',
                now()->addDays((int) config('audit.report_link_days')),
                ['auditReport' => $auditReport->uuid],
            ),
            'deltas' => app(AuditDeltaService::class)->deltasFor($auditReport),
            'groupDeltas' => app(AuditGroupDeltaService::class)->deltasFor($auditReport),
            'allGroups' => $auditReport->auditRequest->findingGroups,
        ]);
    }

    public function sample()
    {
        return $this->renderSample(ReportVariant::BUSINESS);
    }

    public function sampleTechnical()
    {
        return $this->renderSample(ReportVariant::TECHNICAL);
    }
```

Rename the existing `sample()` body to `private function renderSample(ReportVariant $variant)`; keep its fixture loading and 404 guards unchanged, render `ReportVariant::TECHNICAL->webView()` for now, and add to its view data:

```php
            'variant' => $variant,
            'tabUrls' => [
                ReportVariant::BUSINESS->value => route(ReportVariant::BUSINESS->sampleRouteName()),
                ReportVariant::TECHNICAL->value => route(ReportVariant::TECHNICAL->sampleRouteName()),
            ],
```

If a funnel test asserts the exact `STAGE_REPORT_VIEWED` meta (`grep -rn "report_viewed" backend/tests`), add `'variant' => 'business'` to its expectation.

- [ ] **Step 8: Update existing tests that referenced the removed box/views**
  - `PdfRenderTest`: replace `'reports.audit'` with `'reports.technical-pdf'` (lines 54, 78). Change `test_the_pdf_carries_the_plain_language_summary` into `test_the_developer_pdf_has_no_plain_terms_section`: render `reports.technical-pdf` with the same fixture and assert `assertStringNotContainsString(__('In plain terms'), $html)` and `assertStringNotContainsString('Plain-words overview for the owner.', $html)`, still asserting `%PDF-` output.
  - `AuditReportPageTest`: delete `test_an_unlocked_report_shows_the_plain_language_summary`, `test_a_locked_report_shows_the_overview_but_hides_the_findings`, and `test_a_report_without_the_section_renders_without_it` (Task 6 re-adds them for the business page). In `test_sample_report_shows_every_section_a_report_can_carry`, change the URL to `/reports/sample/technical` and remove `'In plain terms'` from the list.

- [ ] **Step 9: Run the report test suites**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http tests/Feature/Services/AuditReport tests/Feature/Views tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Constants/ReportVariant.php backend/routes/web.php backend/app/Http/Controllers/AuditReportController.php backend/app/Services/AuditReport/AuditReportService.php backend/resources/views/reports backend/tests/Feature/Http/Controllers/AuditReportTechnicalPageTest.php backend/tests/Feature/Http/Controllers/AuditReportPageTest.php backend/tests/Feature/Services/AuditReport/PdfRenderTest.php
git commit -m "feat(audit): the technical report becomes the developer report at its own signed URL"
```

---

### Task 6: Business report web page

**Files:**
- Create: `resources/views/reports/business-web.blade.php`, `resources/views/reports/partials/web/locked-text.blade.php`, `resources/views/reports/partials/chart.blade.php`
- Modify: `app/Http/Controllers/AuditReportController.php` (`renderReport`, `renderSample` choose the variant's view and pass `business`)
- Test: `tests/Feature/Http/Controllers/AuditReportBusinessPageTest.php` (new); move technical assertions in existing tests to the technical URL

**Interfaces:**
- Consumes: `BusinessReportPresenter::present()` (Task 4), `ReportVariant` + tabs + unlock CTA (Task 5).
- Produces: `reports.view` and `reports.sample` render `reports.business-web` with `$business` (presenter array).
- Produces partial `reports.partials.chart` with params `svg` (string) and `pdf` (bool, default false).

- [ ] **Step 1: Write the failing test** — `php artisan make:test --phpunit Http/Controllers/AuditReportBusinessPageTest`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportBusinessPageTest extends FeatureTest
{
    private function v6Payload(): array
    {
        return AuditReport::factory()->definition()['payload'] + [
            'groups' => [['rule_family' => 'php:unused-imports', 'directory' => 'app/Http/Controllers', 'severity' => 'high', 'count' => 3, 'narrative' => ['what' => 'w', 'affects' => 'a', 'benefit' => 'b']]],
            'file_findings' => [['path' => 'app/Services/Billing/Webhook.php', 'line' => 12, 'title' => 'Unsigned webhook', 'severity' => 'critical', 'category' => 'security', 'evidence' => 'e', 'recommendation' => 'r', 'effort' => 'S']],
            'client_summary' => [
                'overview' => 'Your app works, but checkout is fragile.',
                'verdict' => 'Solid base, two urgent risks.',
                'areas' => [['area' => 'testing', 'meaning' => 'How much is checked automatically.', 'status' => 'Almost nothing is checked.']],
                'findings' => [['what' => 'Anyone can mark an order as paid.', 'consequence' => 'Free orders and lost revenue.', 'gain' => 'Revenue always matches orders.', 'urgency' => 'now', 'business_area' => 'customers']],
                'roadmap' => [['step' => 'Lock down payment confirmations', 'outcome' => 'Only real payments complete orders', 'effort' => 'S']],
                'questions' => ['How do we confirm a payment is real?'],
            ],
        ];
    }

    private function url(AuditReport $report): string
    {
        return app(AuditReportService::class)->signedUrl($report);
    }

    public function test_an_unlocked_business_report_shows_every_section(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Solid base, two urgent risks.')
            ->assertSee('Your app works, but checkout is fragile.')
            ->assertSee(__('Your codebase at a glance'))
            ->assertSee('Almost nothing is checked.')
            ->assertSee(__('How serious are the problems'))
            ->assertSee(__('What it affects in your business'))
            ->assertSee('Free orders and lost revenue.')
            ->assertSee(__('Fix now'))
            ->assertSee(__('Your roadmap'))
            ->assertSee('Only real payments complete orders')
            ->assertSee(__('Questions to ask your team'))
            ->assertSee('How do we confirm a payment is real?')
            ->assertSee('<svg', false)
            ->assertDontSee(__('Unlock full report'));
    }

    public function test_the_business_page_shows_no_rule_families_or_file_paths(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertDontSee('php:unused-imports')
            ->assertDontSee('app/Http/Controllers')
            ->assertDontSee('app/Services/Billing/Webhook.php')
            ->assertDontSee('Fixture summary.'); // the technical summary
    }

    public function test_a_locked_business_report_shows_headlines_and_charts_but_hides_details(): void
    {
        $report = AuditReport::factory()->locked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Solid base, two urgent risks.')
            ->assertSee('Anyone can mark an order as paid.')
            ->assertSee(__('Fix now'))
            ->assertSee('<svg', false)
            ->assertDontSee('Free orders and lost revenue.')
            ->assertDontSee('Revenue always matches orders.')
            ->assertDontSee('Almost nothing is checked.')
            ->assertDontSee('Only real payments complete orders')
            ->assertDontSee('How do we confirm a payment is real?')
            ->assertSee(__('Unlock full report'))
            ->assertSee('/unlock');
    }

    public function test_a_v5_report_renders_on_the_business_page_without_v6_sections(): void
    {
        $payload = $this->v6Payload();
        $payload['client_summary'] = ['overview' => 'Old overview.', 'findings' => [['what' => 'Old finding.', 'consequence' => 'c', 'gain' => 'g']]];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Old overview.')
            ->assertSee('Old finding.')
            ->assertDontSee(__('Your roadmap'))
            ->assertDontSee(__('Questions to ask your team'))
            ->assertDontSee(__('What it affects in your business'));
    }

    public function test_a_report_without_a_client_summary_still_renders_the_business_page(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee(__('Your full findings are in the developer report.'))
            ->assertSee(__('Your codebase at a glance'))
            ->assertDontSee('Fixture summary.');
    }

    public function test_the_business_page_links_to_the_developer_report(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $html = $this->get($this->url($report))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'/technical\?[^"]*signature=#', $html);
        $this->assertStringContainsString(__('Forward the developer report to your engineer'), $html);
    }

    public function test_the_sample_business_report_carries_every_business_section(): void
    {
        $response = $this->get('/reports/sample')->assertOk();

        foreach (['Your codebase at a glance', 'How serious are the problems', 'What it affects in your business', 'Your roadmap', 'Questions to ask your team', 'Expert\'s note'] as $section) {
            $response->assertSee(__($section));
        }
        $this->assertDoesNotMatchRegularExpression('/\\$\s?\d/', strip_tags($response->getContent()));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers/AuditReportBusinessPageTest.php`
Expected: FAIL — business sections missing.

- [ ] **Step 3: Chart and locked partials**

`resources/views/reports/partials/chart.blade.php`:

```blade
{{-- A ReportChartBuilder SVG. Inline on the web; on a PDF dompdf only reads
     SVG through an <img>, so it goes in as a data URI. The SVG is built
     with every label escaped, so emitting it raw is safe. --}}
@if ($pdf ?? false)
    <img src="data:image/svg+xml;base64,{{ base64_encode($svg) }}" style="width: {{ $width ?? '100%' }};" alt="">
@else
    <div class="[&>svg]:h-auto [&>svg]:w-full [&>svg]:max-w-full">{!! $svg !!}</div>
@endif
```

`resources/views/reports/partials/web/locked-text.blade.php`:

```blade
{{-- Stand-in for text that unlocks with the report: blurred filler, never the real words. --}}
<div class="relative mt-2">
    <div class="blur-[5px] select-none pointer-events-none text-sm text-stone-700" aria-hidden="true">
        @for ($i = 0; $i < ($lines ?? 2); $i++)
            <div class="mt-1">{{ str_repeat('█▌ ', 14) }}</div>
        @endfor
    </div>
    <div class="absolute inset-0 flex items-center justify-center"><span class="rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50">🔒 {{ __('Unlock to read') }}</span></div>
</div>
```

- [ ] **Step 4: Business page** — create `resources/views/reports/business-web.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health — Business Overview') }}</title>
    @vite('resources/css/app.css')
    <style>
        /* Same light reading surface as the developer report; see technical-web. */
        html, .reports-page { background: #faf8f4; color: #1c1917; }
        .reports-page h1, .reports-page h2, .reports-page h3 { color: #1c1917; font-family: inherit; }
    </style>
</head>
<body class="reports-page font-sans text-[15px] leading-relaxed">
@php($b = $business)
@php($urgencyChip = ['now' => 'bg-red-100 text-red-900', 'soon' => 'bg-amber-50 text-amber-800', 'later' => 'bg-lime-50 text-lime-800'])
<div class="mx-auto max-w-[860px] px-4 py-8">
    @if ($isSample)
        <div class="mb-3 rounded-lg bg-primary-500 px-3 py-2 text-center text-xs font-bold uppercase tracking-wider text-stone-900">{{ __('Sample report') }} — {{ __('the business overview every FlexPick audit includes') }}</div>
    @endif

    @include('reports.partials.web.report-tabs')

    {{-- 1. Verdict --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h1 class="mb-1 text-2xl font-bold">{{ __('Your codebase health, in plain words') }}</h1>
        <p class="text-xs text-stone-500">{{ $report->auditRequest->repo_url }} · {{ $report->created_at->format('Y-m-d') }}</p>
        <div class="mt-5 grid items-center gap-6 sm:grid-cols-[220px_1fr]">
            <div class="mx-auto w-full max-w-[220px]">@include('reports.partials.chart', ['svg' => $b['charts']['gauge']])</div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ __('Overall health') }}: {{ $b['band']->caption() }}</p>
                @if ($b['verdict'] !== null)
                    <p class="mt-1 text-lg font-semibold">{{ $b['verdict'] }}</p>
                @endif
                @if ($b['overview'] !== null)
                    <p class="mt-2">{{ $b['overview'] }}</p>
                @else
                    <p class="mt-2">{{ __('Your full findings are in the developer report.') }}</p>
                @endif
                @if ($b['delta'] !== null)
                    <p class="mt-2 text-sm font-semibold {{ $b['delta'] > 0 ? 'text-lime-700' : 'text-red-700' }}">
                        {{ $b['delta'] > 0 ? '▲' : '▼' }} {{ sprintf('%+d', $b['delta']) }}
                        {{ __('since your previous audit on :date', ['date' => $b['previousAt']->format('Y-m-d')]) }}
                    </p>
                @endif
                @if ($b['charts']['percentile'] !== null)
                    <div class="mt-4">
                        @include('reports.partials.chart', ['svg' => $b['charts']['percentile']])
                        <p class="mt-1 text-xs text-stone-500">{{ __('Healthier than :p% of the codebases we have audited.', ['p' => $b['percentile']]) }}</p>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- 2. Areas --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h2 class="mb-1 text-base font-bold">{{ __('Your codebase at a glance') }}</h2>
        <p class="mb-4 text-xs text-stone-500">{{ __('Each area is scored from 0 to 100. Higher is healthier.') }}</p>
        @include('reports.partials.chart', ['svg' => $b['charts']['areas']])
        @php($explained = collect($b['areas'])->whereNotNull('meaning'))
        @if ($explained->isNotEmpty())
            <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($explained as $area)
                    <div class="rounded-lg border border-stone-200 p-4">
                        <dt class="font-semibold">{{ $area['label'] }}</dt>
                        @if ($unlocked)
                            <dd class="mt-1 text-sm text-stone-700">{{ $area['meaning'] }}</dd>
                            <dd class="mt-1 text-sm"><strong>{{ __('How you are doing') }}:</strong> {{ $area['status'] }}</dd>
                        @else
                            <dd>@include('reports.partials.web.locked-text', ['lines' => 2])</dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- 3. Seriousness --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h2 class="mb-1 text-base font-bold">{{ __('How serious are the problems') }}</h2>
        <p class="mb-4 text-xs text-stone-500">{{ __('Every problem area we found, sorted by how soon it could hurt you.') }}</p>
        @include('reports.partials.chart', ['svg' => $b['charts']['severity']])
    </section>

    {{-- 4. Business areas --}}
    @if ($b['charts']['businessAreas'] !== null)
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-1 text-base font-bold">{{ __('What it affects in your business') }}</h2>
            <p class="mb-4 text-xs text-stone-500">{{ __('Which part of your business each key finding touches most.') }}</p>
            @include('reports.partials.chart', ['svg' => $b['charts']['businessAreas']])
        </section>
    @endif

    {{-- 5. Key findings --}}
    @if ($b['findings'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('What we found') }}</h2>
            <div class="grid gap-3">
                @foreach ($b['findings'] as $finding)
                    <article class="rounded-lg border border-stone-200 p-4">
                        <div class="flex flex-wrap items-start gap-2.5">
                            @if ($finding['urgency_label'] !== null)
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $urgencyChip[$finding['urgency']] }}">{{ $finding['urgency_label'] }}</span>
                            @endif
                            <h3 class="flex-1 font-semibold">{{ $finding['what'] }}</h3>
                        </div>
                        @if ($unlocked)
                            <div class="mt-2 text-sm text-stone-700">
                                <div><strong>{{ __('What it may cause') }}:</strong> {{ $finding['consequence'] }}</div>
                                <div class="mt-1"><strong>{{ __('What you gain by fixing it') }}:</strong> {{ $finding['gain'] }}</div>
                            </div>
                        @else
                            @include('reports.partials.web.locked-text', ['lines' => 2])
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @unless ($unlocked)
        @include('reports.partials.web.unlock-cta')
    @endunless

    {{-- 6. Roadmap --}}
    @if ($b['roadmap'] !== null && $b['roadmap'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('Your roadmap') }}</h2>
            @if ($unlocked)
                <ol class="relative border-l-2 border-primary-500 pl-6">
                    @foreach ($b['roadmap'] as $i => $step)
                        <li class="mb-5 last:mb-0">
                            <span class="absolute -left-[13px] flex h-6 w-6 items-center justify-center rounded-full bg-primary-500 text-xs font-bold text-stone-900">{{ $i + 1 }}</span>
                            <div class="font-semibold">{{ $step['step'] }}</div>
                            <div class="mt-1 text-sm text-stone-700">{{ $step['outcome'] }}</div>
                            <span class="mt-1 inline-block rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ __('Effort') }}: {{ $step['effort_label'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @else
                @include('reports.partials.web.locked-text', ['lines' => 4])
            @endif
        </section>
    @endif

    {{-- 7. Expert --}}
    @if ($b['expertSummary'] !== null)
        <section class="mb-5 rounded-xl border border-primary-500 bg-white p-7">
            <h2 class="mb-2 text-base font-bold">{{ __('Expert\'s note') }}</h2>
            <p class="text-sm text-stone-700">{{ $b['expertSummary'] }}</p>
        </section>
    @endif

    {{-- 8. Questions --}}
    @if ($b['questions'] !== null && $b['questions'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('Questions to ask your team') }}</h2>
            @if ($unlocked)
                <ul class="list-disc space-y-1.5 pl-5 text-sm text-stone-700">
                    @foreach ($b['questions'] as $question)
                        <li>{{ $question }}</li>
                    @endforeach
                </ul>
            @else
                @include('reports.partials.web.locked-text', ['lines' => 3])
            @endif
        </section>
    @endif

    {{-- 9. Hand-off --}}
    <section class="mb-5 rounded-xl bg-stone-900 p-7 text-stone-50">
        <h2 class="mb-1 text-base font-bold !text-stone-50">{{ __('Forward the developer report to your engineer') }}</h2>
        <p class="text-sm text-stone-300">{{ __('It has every finding with the exact files, evidence and recommended fix.') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <a class="inline-block rounded-lg bg-primary-500 px-5 py-2.5 font-bold text-stone-900 no-underline" href="{{ $tabUrls['technical'] }}">{{ __('Open the developer report') }}</a>
            @if (! $isSample && $unlocked && $report->pdf_path !== null)
                <a class="inline-block rounded-lg border border-stone-600 px-5 py-2.5 font-bold text-stone-50 no-underline" href="{{ route('reports.download', ['auditReport' => $report->uuid]) }}">{{ __('Download PDF') }}</a>
            @endif
        </div>
    </section>

    <p class="text-center text-xs text-stone-500">
        {{ __('Scores are measured by automated analysis; the explanations are written for a non-technical reader. Reply to your report email to talk any of it through with an engineer.') }}
    </p>
</div>
</body>
</html>
```

(Task 7 adds the developer-PDF link and changes the PDF link to the explicit `business` variant.)

- [ ] **Step 5: Controller** — in `renderReport()` replace the temporary `view(ReportVariant::TECHNICAL->webView(), [...])` with building `$data = [...]` (same array) and then:

```php
        if ($variant === ReportVariant::BUSINESS) {
            $data['business'] = app(BusinessReportPresenter::class)->present(
                $auditReport, $data['deltas'], $data['percentile'], $data['allGroups'],
            );
        }

        return view($variant->webView(), $data);
```

Do the same in `renderSample()` (presenter gets the in-memory `$report`, the computed `deltas`, `$fixture['percentile']`, and `collect()`). Add `use App\Services\AuditReport\BusinessReportPresenter;`. Remove the "Until Task 6" comment.

- [ ] **Step 6: Move technical assertions to the technical URL** — `reports.view` now renders the business page, so tests asserting technical content there must target `ReportVariant::TECHNICAL`. Run:

`docker compose exec laravel.test php artisan test --compact tests/Feature/Http tests/Feature/Views`

For each failure in `AuditReportRenderTest`, `AuditReportControllerTest`, `AuditReportFactsTest`, `DeepReviewRenderingTest`, `AuditReportPageTest`, `SizeBandCopyTest`: if it asserts technical content (scores tiles, groups, risks, repository facts, deep findings, expert review notes, fix-first plan, `Download PDF` link next to the plan), change `->signedUrl($report)` to `->signedUrl($report, ReportVariant::TECHNICAL)` (add `use App\Constants\ReportVariant;`) and `'/reports/sample'` to `'/reports/sample/technical'`. Do **not** weaken any assertion. Tests about the link/signature itself (`SignedLinkFailureTest`) stay on `reports.view`.

In `AuditReportPageTest`:
- `test_locked_report_shows_titles_but_hides_details` and `test_unlocked_report_shows_everything_and_pdf_link` → technical URL.
- `test_a_locked_report_quotes_catalog_prices_to_an_unreferred_reader` and `…_no_base_price_to_a_referred_reader` → keep on the business URL (the CTA partial is shared) **and** add a duplicate assertion block against the technical URL in each.
- `test_sample_report_is_public_and_unlocked` → assert `__('Your roadmap')` instead of `__('What to fix first')`.
- `test_sample_report_labels_each_section_with_its_tier` → `/reports/sample/technical`.
- `test_sample_report_shows_no_prices` → loop over both `/reports/sample` and `/reports/sample/technical`.

- [ ] **Step 7: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http tests/Feature/Views tests/Feature/Services/AuditReport`
Expected: PASS.

- [ ] **Step 8: Build assets and look at it** — `docker compose exec laravel.test npm run build`, open `http://localhost:8080/reports/sample` at desktop width and at 375px width. Check: gauge, area bars, severity and business-area charts render; no horizontal scroll at 375px; tabs switch to `/reports/sample/technical` and back.

- [ ] **Step 9: Commit**

```bash
git add backend/resources/views/reports backend/app/Http/Controllers/AuditReportController.php backend/tests/Feature/Http backend/tests/Feature/Views
git commit -m "feat(audit): the report link opens a plain-language business report with charts"
```

---

### Task 7: Business PDF, both PDFs stored, variant downloads, backfill

**Files:**
- Create: `database/migrations/2026_10_09_000001_add_technical_pdf_path_to_audit_reports_table.php`
- Create: `resources/views/reports/business-pdf.blade.php`
- Create: `app/Console/Commands/RegenerateReportPdfs.php`
- Modify: `app/Models/AuditReport.php` (fillable), `app/Services/AuditReport/AuditReportService.php` (`create`, `generatePdf`, new `renderPdf`, `ensureTechnicalPdf`), `app/Services/AuditRequestService.php:253-262`, `app/Http/Controllers/AuditReportController.php` (`download`), `routes/web.php` (download route), `database/factories/AuditReportFactory.php` (`locked()` also nulls `technical_pdf_path`), both web views (PDF links)
- Test: `tests/Feature/Services/AuditReport/PdfRenderTest.php`, `tests/Feature/Http/Controllers/AuditReportDownloadTest.php` (new), `tests/Feature/Console/RegenerateReportPdfsTest.php` (new)

**Interfaces:**
- Consumes: `ReportVariant` (`pdfView`, `pdfSuffix`, `pdfFilename`), `BusinessReportPresenter`.
- Produces: `audit_reports.technical_pdf_path` (nullable string). `AuditReportService::renderPdf(AuditReport $report, ReportVariant $variant): string` (writes, returns path). `AuditReportService::ensureTechnicalPdf(AuditReport $report): string`. Route `reports.download` = `/reports/{auditReport:uuid}/download/{variant?}`, `variant ∈ business|technical`. Artisan `app:regenerate-report-pdfs {--dry-run}`.

- [ ] **Step 1: Write the failing tests**

Append to `PdfRenderTest`:

```php
    public function test_the_business_pdf_renders_with_its_charts(): void
    {
        $report = AuditReport::factory()->unlocked()->create([
            'payload' => AuditReport::factory()->definition()['payload'] + [
                'groups' => [],
                'client_summary' => [
                    'overview' => 'Owner overview.',
                    'verdict' => 'Owner verdict.',
                    'areas' => [['area' => 'testing', 'meaning' => 'm', 'status' => 's']],
                    'findings' => [['what' => 'Owner finding.', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'now', 'business_area' => 'costs']],
                    'roadmap' => [['step' => 'Step one', 'outcome' => 'o', 'effort' => 'M']],
                    'questions' => ['Owner question?'],
                ],
            ],
        ]);
        $business = app(\App\Services\AuditReport\BusinessReportPresenter::class)->present($report, null, 50, collect());

        $html = view('reports.business-pdf', ['report' => $report->fresh(), 'business' => $business])->render();

        $this->assertStringContainsString('Owner verdict.', $html);
        $this->assertStringContainsString('Owner question?', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('Fixture summary.', $html);
        $output = Pdf::loadHTML($html)->output();
        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }

    public function test_unlocking_writes_both_pdfs(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Mail::fake();
        $report = AuditReport::factory()->locked()->create();

        app(\App\Services\AuditReport\AuditReportService::class)->unlock($report);

        $report->refresh();
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($report->pdf_path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($report->technical_pdf_path);
        $this->assertStringEndsWith('-technical.pdf', $report->technical_pdf_path);
    }
```

Create `tests/Feature/Http/Controllers/AuditReportDownloadTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportDownloadTest extends FeatureTest
{
    private function ownedReport(array $attributes = []): array
    {
        $owner = $this->createUser();
        $report = AuditReport::factory()->unlocked()->create(['user_id' => $owner->id] + $attributes);
        $report->auditRequest->update(['tenant_id' => null, 'user_id' => $owner->id, 'email' => $owner->email]);

        return [$owner, $report];
    }

    public function test_the_default_download_is_the_business_pdf(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit-reports/b.pdf', '%PDF-1.4 business');
        [$owner, $report] = $this->ownedReport(['pdf_path' => 'audit-reports/b.pdf']);

        $this->actingAs($owner)->get(route('reports.download', $report))
            ->assertOk()
            ->assertDownload('codebase-health-business.pdf');
    }

    public function test_the_developer_pdf_downloads_by_variant(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit-reports/b.pdf', '%PDF-1.4 business');
        Storage::disk('local')->put('audit-reports/t.pdf', '%PDF-1.4 technical');
        [$owner, $report] = $this->ownedReport(['pdf_path' => 'audit-reports/b.pdf', 'technical_pdf_path' => 'audit-reports/t.pdf']);

        $this->actingAs($owner)->get(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']))
            ->assertOk()
            ->assertDownload('codebase-health-developer.pdf');
    }

    public function test_the_developer_pdf_is_generated_on_first_download_for_an_older_report(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit-reports/b.pdf', '%PDF-1.4 business');
        [$owner, $report] = $this->ownedReport(['pdf_path' => 'audit-reports/b.pdf', 'technical_pdf_path' => null]);

        $this->actingAs($owner)->get(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']))
            ->assertOk()
            ->assertDownload('codebase-health-developer.pdf');

        $this->assertNotNull($report->fresh()->technical_pdf_path);
        Storage::disk('local')->assertExists($report->fresh()->technical_pdf_path);
    }

    public function test_an_unknown_variant_is_not_found(): void
    {
        $this->withExceptionHandling();
        [$owner, $report] = $this->ownedReport();

        $this->actingAs($owner)->get('/reports/'.$report->uuid.'/download/everything')->assertNotFound();
    }

    public function test_a_locked_report_has_no_developer_pdf_either(): void
    {
        $this->withExceptionHandling();
        $owner = $this->createUser();
        $report = AuditReport::factory()->locked()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']))->assertNotFound();
        $this->assertNull($report->fresh()->technical_pdf_path);
    }
}
```

If `ownedReport()`'s ownership setup does not satisfy `AuditReport::isViewableBy()` (403), copy the exact owner setup from `AuditReportControllerTest::test_download_of_an_unclaimed_report_falls_back_to_the_personal_owner_rule`.

Create `tests/Feature/Console/RegenerateReportPdfsTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class RegenerateReportPdfsTest extends FeatureTest
{
    public function test_regenerates_both_pdfs_for_unlocked_reports_only(): void
    {
        Storage::fake('local');
        $unlocked = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);
        $locked = AuditReport::factory()->locked()->create();
        $held = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);
        $held->auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->artisan('app:regenerate-report-pdfs')->assertSuccessful();

        $this->assertNotNull($unlocked->fresh()->pdf_path);
        $this->assertNotNull($unlocked->fresh()->technical_pdf_path);
        $this->assertNull($locked->fresh()->technical_pdf_path);
        $this->assertNull($held->fresh()->technical_pdf_path);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Storage::fake('local');
        $report = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);

        $this->artisan('app:regenerate-report-pdfs', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($report->fresh()->technical_pdf_path);
    }
}
```

Also add to an existing `AuditRequestService` delete test (find it with `grep -rn "function test.*delete" backend/tests/Feature | grep -i auditrequest`) — or create `tests/Feature/Services/AuditRequestDeleteTest.php` if none — a test that a report with both `pdf_path` and `technical_pdf_path` files on a faked disk has **both** files missing after `app(AuditRequestService::class)->delete($request)`.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Services/AuditReport/PdfRenderTest.php tests/Feature/Http/Controllers/AuditReportDownloadTest.php tests/Feature/Console/RegenerateReportPdfsTest.php`
Expected: FAIL — column, view, route parameter and command missing.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            // The developer report's PDF. pdf_path now holds the business
            // report's. Null on reports unlocked before the split -- the
            // download generates it on first request.
            $table->string('technical_pdf_path')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            $table->dropColumn('technical_pdf_path');
        });
    }
};
```

Add `'technical_pdf_path'` after `'pdf_path'` in `AuditReport::$fillable`. In `AuditReportFactory::locked()` return `['unlocked_at' => null, 'pdf_path' => null, 'technical_pdf_path' => null]`.

- [ ] **Step 4: Service** — in `AuditReportService` (add `use App\Constants\ReportVariant;`):

In `create()`, replace the single delete with:

```php
            foreach ([$existing->pdf_path, $existing->technical_pdf_path] as $path) {
                if ($path !== null) {
                    Storage::disk('local')->delete($path);
                }
            }
```

and add `'technical_pdf_path' => null,` next to `'pdf_path' => null,` in the new report's attributes.

Replace `generatePdf()` with:

```php
    private function generatePdf(AuditReport $report): void
    {
        $report->update([
            'pdf_path' => $this->renderPdf($report, ReportVariant::BUSINESS),
            'technical_pdf_path' => $this->renderPdf($report, ReportVariant::TECHNICAL),
        ]);
    }

    /** Renders one report's PDF to local storage and returns its path. */
    public function renderPdf(AuditReport $report, ReportVariant $variant): string
    {
        $path = config('audit.reports_dir').'/'.$report->uuid.$variant->pdfSuffix().'.pdf';
        $data = ['report' => $report];

        if ($variant === ReportVariant::BUSINESS) {
            $data['business'] = app(BusinessReportPresenter::class)->present(
                $report,
                $this->deltaService->deltasFor($report),
                app(AuditBenchmarkService::class)->percentileFor((int) data_get($report->payload, 'scores.overall', 0), $report->scoring_version),
                $report->auditRequest->findingGroups,
            );
        }

        Storage::disk('local')->put($path, Pdf::loadView($variant->pdfView(), $data)->output());

        return $path;
    }

    /**
     * Reports unlocked before the split carry only the business PDF; the
     * developer one is written the first time somebody asks for it.
     */
    public function ensureTechnicalPdf(AuditReport $report): string
    {
        if ($report->technical_pdf_path === null) {
            $report->update(['technical_pdf_path' => $this->renderPdf($report, ReportVariant::TECHNICAL)]);
        }

        return $report->technical_pdf_path;
    }
```

`AuditBenchmarkService` and `BusinessReportPresenter` are in the same namespace — no import needed.

- [ ] **Step 5: Delete both files** — in `AuditRequestService::delete()` replace the single-path block with:

```php
        foreach ([$auditRequest->report?->pdf_path, $auditRequest->report?->technical_pdf_path] as $path) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
        }
```

and update its docblock "The PDF does not." → "The PDFs do not."

- [ ] **Step 6: Route + controller** — in `routes/web.php`:

```php
Route::get('/reports/{auditReport:uuid}/download/{variant?}', [AuditReportController::class, 'download'])
    ->name('reports.download')
    ->whereIn('variant', ['business', 'technical'])
    ->middleware('auth');
```

Replace `download()`:

```php
    public function download(AuditReport $auditReport, ?string $variant = null)
    {
        // (keep the existing membership comment)
        abort_unless($auditReport->isViewableBy(auth()->user()), 403);
        abort_if($auditReport->auditRequest->isHeldForExpertReview(), 403);
        // pdf_path is only ever written on unlock, so it doubles as the
        // "this report's PDFs exist" gate for both variants.
        abort_if($auditReport->pdf_path === null, 404);

        $variant = ReportVariant::from($variant ?? ReportVariant::BUSINESS->value);
        $path = $variant === ReportVariant::TECHNICAL
            ? app(AuditReportService::class)->ensureTechnicalPdf($auditReport)
            : $auditReport->pdf_path;

        return Storage::disk('local')->download($path, $variant->pdfFilename());
    }
```

- [ ] **Step 7: PDF links on the web pages**
  - `business-web.blade.php` hand-off section: replace the single `Download PDF` link with two links — `route('reports.download', ['auditReport' => $report->uuid, 'variant' => 'business'])` labelled `__('Business PDF')` and `route('reports.download', ['auditReport' => $report->uuid, 'variant' => 'technical'])` labelled `__('Developer PDF')`, same classes.
  - `technical-web.blade.php`: replace `route('reports.download', ['auditReport' => $report->uuid])` / `__('Download PDF')` with the `technical` variant link labelled `__('Download PDF')`.
  - Update `AuditReportPageTest::test_unlocked_report_shows_everything_and_pdf_link` to assert the `technical` variant route.

- [ ] **Step 8: Business PDF view** — `resources/views/reports/business-pdf.blade.php`. Tables and blocks only (no flex/grid):

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Codebase Health — Business Overview') }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; margin: 32px; font-size: 13px; }
        h1 { font-size: 22px; margin-bottom: 2px; }
        h2 { font-size: 15px; margin-top: 26px; border-bottom: 1px solid #b98e41; padding-bottom: 4px; }
        .muted { color: #78716c; font-size: 11px; }
        .verdict { font-size: 16px; font-weight: bold; margin: 4px 0 6px; }
        .chip { font-size: 9px; font-weight: bold; padding: 2px 6px; border-radius: 8px; text-transform: uppercase; }
        .chip-now { background: #fee2e2; color: #b91c1c; }
        .chip-soon { background: #fef3c7; color: #b45309; }
        .chip-later { background: #ecfccb; color: #4d7c0f; }
        .card { border: 1px solid #e8e2d6; padding: 10px; margin-top: 8px; }
        .card-title { font-weight: bold; }
        table.layout { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: top; padding: 0; }
        .step-no { width: 26px; font-weight: bold; color: #b98e41; }
    </style>
</head>
<body>
    @php($b = $business)

    <h1>{{ __('Your codebase health, in plain words') }}</h1>
    <p class="muted">{{ $report->auditRequest->repo_url }} · {{ __('Generated :date by FlexPick', ['date' => $report->created_at->format('Y-m-d')]) }}</p>

    <table class="layout">
        <tr>
            <td style="width: 200px;">@include('reports.partials.chart', ['svg' => $b['charts']['gauge'], 'pdf' => true, 'width' => '190px'])</td>
            <td>
                <p class="muted">{{ __('Overall health') }}: {{ $b['band']->caption() }}</p>
                @if ($b['verdict'] !== null)<p class="verdict">{{ $b['verdict'] }}</p>@endif
                <p>{{ $b['overview'] ?? __('Your full findings are in the developer report.') }}</p>
                @if ($b['delta'] !== null)
                    <p class="muted">{{ sprintf('%+d', $b['delta']) }} {{ __('since your previous audit on :date', ['date' => $b['previousAt']->format('Y-m-d')]) }}</p>
                @endif
                @if ($b['charts']['percentile'] !== null)
                    @include('reports.partials.chart', ['svg' => $b['charts']['percentile'], 'pdf' => true, 'width' => '300px'])
                    <p class="muted">{{ __('Healthier than :p% of the codebases we have audited.', ['p' => $b['percentile']]) }}</p>
                @endif
            </td>
        </tr>
    </table>

    <h2>{{ __('Your codebase at a glance') }}</h2>
    <p class="muted">{{ __('Each area is scored from 0 to 100. Higher is healthier.') }}</p>
    @include('reports.partials.chart', ['svg' => $b['charts']['areas'], 'pdf' => true, 'width' => '420px'])
    @foreach (collect($b['areas'])->whereNotNull('meaning') as $area)
        <div class="card">
            <div class="card-title">{{ $area['label'] }}</div>
            <div>{{ $area['meaning'] }}</div>
            <div><strong>{{ __('How you are doing') }}:</strong> {{ $area['status'] }}</div>
        </div>
    @endforeach

    <h2>{{ __('How serious are the problems') }}</h2>
    @include('reports.partials.chart', ['svg' => $b['charts']['severity'], 'pdf' => true, 'width' => '420px'])

    @if ($b['charts']['businessAreas'] !== null)
        <h2>{{ __('What it affects in your business') }}</h2>
        @include('reports.partials.chart', ['svg' => $b['charts']['businessAreas'], 'pdf' => true, 'width' => '420px'])
    @endif

    @if ($b['findings'] !== [])
        <h2>{{ __('What we found') }}</h2>
        @foreach ($b['findings'] as $finding)
            <div class="card">
                @if ($finding['urgency_label'] !== null)<span class="chip chip-{{ $finding['urgency'] }}">{{ $finding['urgency_label'] }}</span>@endif
                <div class="card-title">{{ $finding['what'] }}</div>
                <div><strong>{{ __('What it may cause') }}:</strong> {{ $finding['consequence'] }}</div>
                <div><strong>{{ __('What you gain by fixing it') }}:</strong> {{ $finding['gain'] }}</div>
            </div>
        @endforeach
    @endif

    @if ($b['roadmap'] !== null && $b['roadmap'] !== [])
        <h2>{{ __('Your roadmap') }}</h2>
        <table class="layout">
            @foreach ($b['roadmap'] as $i => $step)
                <tr>
                    <td class="step-no">{{ $i + 1 }}.</td>
                    <td style="padding-bottom: 8px;">
                        <strong>{{ $step['step'] }}</strong><br>
                        {{ $step['outcome'] }}<br>
                        <span class="muted">{{ __('Effort') }}: {{ $step['effort_label'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($b['expertSummary'] !== null)
        <h2>{{ __('Expert\'s note') }}</h2>
        <p>{{ $b['expertSummary'] }}</p>
    @endif

    @if ($b['questions'] !== null && $b['questions'] !== [])
        <h2>{{ __('Questions to ask your team') }}</h2>
        <ul>
            @foreach ($b['questions'] as $question)
                <li>{{ $question }}</li>
            @endforeach
        </ul>
    @endif

    <p class="muted" style="margin-top: 28px;">{{ __('The developer report, sent alongside this one, has every finding with the exact files, evidence and recommended fix.') }}</p>
</body>
</html>
```

The PDF is only generated for unlocked reports, so it has no locked states.

- [ ] **Step 9: Backfill command** — `app/Console/Commands/RegenerateReportPdfs.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Console\Command;

/**
 * One-off after the report split: every unlocked report's stored PDF is the
 * old combined report. Rewrites it as the business PDF and adds the
 * developer PDF. Safe to re-run -- it only ever regenerates.
 */
class RegenerateReportPdfs extends Command
{
    protected $signature = 'app:regenerate-report-pdfs {--dry-run : List what would be regenerated and change nothing}';

    protected $description = 'Regenerate the business and developer PDFs of every unlocked audit report';

    public function handle(AuditReportService $reports): int
    {
        $query = AuditReport::query()
            ->whereNotNull('unlocked_at')
            ->with('auditRequest');

        $done = 0;

        $query->chunkById(50, function ($chunk) use ($reports, &$done): void {
            foreach ($chunk as $report) {
                // A held report's PDF is written when its reviewer publishes.
                if ($report->auditRequest === null || $report->auditRequest->isHeldForExpertReview()) {
                    continue;
                }

                if (! $this->option('dry-run')) {
                    $reports->regeneratePdf($report);
                }

                $this->line($report->uuid);
                $done++;
            }
        });

        $this->info(($this->option('dry-run') ? 'Would regenerate ' : 'Regenerated ').$done.' report(s).');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 10: Run tests**

Run: `docker compose exec laravel.test php artisan migrate && docker compose exec laravel.test php artisan test --compact tests/Feature/Services/AuditReport tests/Feature/Http tests/Feature/Console tests/Feature/Services/AuditReportUnlockTest.php`
Expected: PASS.

- [ ] **Step 11: Visual check of both PDFs** — `docker compose exec laravel.test php artisan tinker --execute '$r = App\Models\AuditReport::whereNotNull("unlocked_at")->latest()->first(); app(App\Services\AuditReport\AuditReportService::class)->regeneratePdf($r); echo $r->fresh()->pdf_path, PHP_EOL, $r->fresh()->technical_pdf_path;'` then copy both files out of `storage/app/private/` (or `storage/app/`, per the `local` disk root) and open them. Confirm every chart is visible (gauge arc, bars, legend text). If dompdf drops SVG `<text>`, fall back to rendering labels in HTML beside the image for that chart and note it in the commit message.

- [ ] **Step 12: Commit**

```bash
git add backend/database/migrations/2026_10_09_000001_add_technical_pdf_path_to_audit_reports_table.php backend/app/Models/AuditReport.php backend/database/factories/AuditReportFactory.php backend/app/Services/AuditReport/AuditReportService.php backend/app/Services/AuditRequestService.php backend/app/Http/Controllers/AuditReportController.php backend/routes/web.php backend/resources/views/reports backend/app/Console/Commands/RegenerateReportPdfs.php backend/tests/Feature/Services backend/tests/Feature/Http backend/tests/Feature/Console
git commit -m "feat(audit): business and developer PDFs, downloadable separately"
```

---

### Task 8: Emails carry both reports

**Files:**
- Modify: `app/Mail/Audit/AuditReportReady.php`, `app/Mail/Audit/AuditReportUnlocked.php`, `resources/views/emails/audit/report-ready.blade.php`, `resources/views/emails/audit/unlocked.blade.php`, `app/Services/AuditReport/AuditReportService.php` (`send`, `unlock`)
- Test: `tests/Feature/Mail/AuditMailablesTest.php`

**Interfaces:**
- Consumes: `ReportVariant`, `signedUrl($report, $variant)`, `technical_pdf_path`.
- Produces: `new AuditReportReady(AuditReport $report, string $signedUrl, ?array $deltas = null, ?array $groupDeltas = null, ?string $technicalUrl = null)`; `new AuditReportUnlocked(AuditReport $report, string $reportUrl, ?string $technicalUrl = null)`.

- [ ] **Step 1: Write the failing tests** — replace `test_report_ready_attaches_pdf_and_links` in `AuditMailablesTest`:

```php
    public function test_report_ready_attaches_both_pdfs_and_links_both_reports(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 business');
        Storage::disk('local')->put('audit-reports/fixture-technical.pdf', '%PDF-1.4 technical');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf', 'technical_pdf_path' => 'audit-reports/fixture-technical.pdf']);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x', technicalUrl: 'https://app.example.com/reports/abc/technical?signature=y');

        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc/technical?signature=y');
        $mailable->assertSeeInHtml(__('Forward the developer report to your engineer'));
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')->as('codebase-health-business.pdf')->withMime('application/pdf')
        );
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture-technical.pdf')->as('codebase-health-developer.pdf')->withMime('application/pdf')
        );
    }

    public function test_report_ready_without_a_developer_pdf_attaches_only_the_business_one(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 business');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf', 'technical_pdf_path' => null]);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x');

        $this->assertCount(1, $mailable->attachments());
        $mailable->assertDontSeeInHtml(__('Forward the developer report to your engineer'));
    }

    public function test_unlocked_email_links_both_reports(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $mailable = new AuditReportUnlocked($report, 'https://app.example.com/reports/abc?signature=x', 'https://app.example.com/reports/abc/technical?signature=y');

        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc/technical?signature=y');
    }
```

Add `use App\Mail\Audit\AuditReportUnlocked;` if missing. Then, in whichever test covers `AuditReportService::send()` queuing `AuditReportReady` (`grep -rn "AuditReportReady::class" backend/tests`), add an assertion inside its `Mail::assertQueued(AuditReportReady::class, fn ($m) => …)` closure: `str_contains((string) $m->technicalUrl, '/technical')`.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Mail/AuditMailablesTest.php`
Expected: FAIL — unknown named argument `technicalUrl`.

- [ ] **Step 3: Mailables** — `AuditReportReady`: add constructor param `public ?string $technicalUrl = null,` (last), and replace `attachments()`:

```php
    public function attachments(): array
    {
        $files = [
            [ReportVariant::BUSINESS, $this->report->pdf_path],
            [ReportVariant::TECHNICAL, $this->report->technical_pdf_path],
        ];

        $attachments = [];
        foreach ($files as [$variant, $path]) {
            if ($path !== null) {
                $attachments[] = Attachment::fromStorageDisk('local', $path)
                    ->as($variant->pdfFilename())
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
```

(add `use App\Constants\ReportVariant;`). `AuditReportUnlocked`: add `public ?string $technicalUrl = null,` after `$reportUrl`.

- [ ] **Step 4: Templates** — in `report-ready.blade.php` change the intro to `{{ __('Your codebase health report is ready. It comes in two parts: a plain-language business overview, and a developer report with every technical detail.') }}`, change the link label to `{{ __('View the business overview') }}`, and add right after that link paragraph:

```blade
            @if ($technicalUrl !== null)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Forward the developer report to your engineer') }}:
                    <a href="{{ $technicalUrl }}">{{ __('Open the developer report') }}</a>
                </p>
            @endif
```

In `unlocked.blade.php`, after the button paragraph add:

```blade
            @if ($technicalUrl !== null)
                <p style="margin: 16px 0 0; line-height: 24px; text-align: center;">
                    <a href="{{ $technicalUrl }}">{{ __('Open the developer report') }}</a>
                </p>
            @endif
```

and change `the PDF export is ready` to `both PDFs are attached to your dashboard downloads`. Re-check any `AuditMailablesTest` assertion on that sentence and update it.

- [ ] **Step 5: Service** — in `AuditReportService::send()` pass `technicalUrl: $this->signedUrl($report, ReportVariant::TECHNICAL)` to `new AuditReportReady(...)`; in `unlock()` pass `$this->signedUrl($report, ReportVariant::TECHNICAL)` as the third `AuditReportUnlocked` argument.

- [ ] **Step 6: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Mail tests/Feature/Services/AuditReportUnlockTest.php tests/Feature/Services/AuditMailerBinaryAttachmentTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Mail/Audit/AuditReportReady.php backend/app/Mail/Audit/AuditReportUnlocked.php backend/resources/views/emails/audit/report-ready.blade.php backend/resources/views/emails/audit/unlocked.blade.php backend/app/Services/AuditReport/AuditReportService.php backend/tests/Feature/Mail/AuditMailablesTest.php
git commit -m "feat(audit): report emails link and attach both the business and developer reports"
```

(If Step 1 touched another test file for the `send()` assertion, add it to `git add`.)

---

### Task 9: Dashboard and admin download buttons

**Files:**
- Modify: `resources/views/filament/dashboard/pages/audit-reports.blade.php:276-283`, `app/Filament/Dashboard/Resources/AuditRequests/Pages/ViewAuditRequest.php:46-50`, `app/Filament/Admin/Resources/AuditRequests/Pages/ViewAuditRequest.php:38-47`
- Test: `tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php`, `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `reports.download` with `variant` (Task 7).

- [ ] **Step 1: Write the failing tests** — in `AuditRequestResourceTest`, next to the test at line ~402 that asserts `route('reports.download', $report)`, add `->assertSee(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']), escape: false)` to the same chain. In the test at ~423 (no file → no download link), add `->assertDontSee(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']), escape: false)`. In `AuditReportsRenderTest`, find the test that renders an unlocked report row and add `->assertSee(route('reports.download', ['auditReport' => $report, 'variant' => 'technical']))` and `->assertSee(__('Developer PDF'))`; keep the existing `assertDontSee` at line 52 and add the matching technical-variant `assertDontSee`. Add the same technical-variant assertion to a dashboard `ViewAuditRequest` test if one exists (`grep -rln "Dashboard.*ViewAuditRequest" backend/tests`).

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**
  - Dashboard list (`audit-reports.blade.php`): replace the single `PDF` button with two:

```blade
                                    <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', ['auditReport' => $report, 'variant' => 'business']) }}">
                                        {{ __('Business PDF') }}
                                    </x-filament::button>
                                    <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', ['auditReport' => $report, 'variant' => 'technical']) }}">
                                        {{ __('Developer PDF') }}
                                    </x-filament::button>
```

  - Dashboard `ViewAuditRequest`: replace `Action::make('downloadPdf')` with two actions `downloadBusinessPdf` (label `__('Business PDF')`, url `route('reports.download', ['auditReport' => $record->report, 'variant' => 'business'])`) and `downloadTechnicalPdf` (label `__('Developer PDF')`, variant `technical`), each keeping `->openUrlInNewTab()` and the existing `->visible(...)` closure.
  - Admin `ViewAuditRequest`: same split; both keep the icon `heroicon-m-arrow-down-tray`, the existing comment, and the `pdf_path !== null && ! held` visibility.

  The business-variant URL is `/download/business`; the existing assertion `route('reports.download', $report)` (no variant) produces `/download` and will no longer match. Update those existing assertions to the explicit `'variant' => 'business'` form.

- [ ] **Step 4: Run tests**

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Filament`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/views/filament/dashboard/pages/audit-reports.blade.php backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ViewAuditRequest.php backend/app/Filament/Admin/Resources/AuditRequests/Pages/ViewAuditRequest.php backend/tests/Feature/Filament
git commit -m "feat(dashboard): separate business and developer PDF downloads"
```

---

### Task 10: Full verification

- [ ] **Step 1: Format** — `docker compose exec laravel.test vendor/bin/pint` then `docker compose exec laravel.test vendor/bin/pint --test`. Expected: clean.
- [ ] **Step 2: Static analysis** — `docker compose exec laravel.test vendor/bin/phpstan analyse`. Expected: no errors.
- [ ] **Step 3: Full suite** — `docker compose exec laravel.test php artisan test --compact`. Expected: all green. On a `QueryException` storm, re-run once (other sessions share the test DB) before investigating.
- [ ] **Step 4: Leftover references** — `grep -rn "reports\.audit'\|reports\.audit-web\|client-summary\|codebase-health-report\.pdf" backend/app backend/resources backend/tests backend/routes`. Expected: no output.
- [ ] **Step 5: Manual pass** — with `npm run build` done, open `/reports/sample` and `/reports/sample/technical` at desktop and 375px width; open a locked and an unlocked real report through `signedUrl()` from tinker; download both PDFs from the dashboard.
- [ ] **Step 6: Commit any formatting fixes**

```bash
git add <files pint changed>
git commit -m "style: pint"
```

- [ ] **Step 7: Deploy note** — record in the PR description: run `php artisan migrate` (part of deploy) then once `php artisan app:regenerate-report-pdfs` on production; spot-check the first real business reports for jargon.
