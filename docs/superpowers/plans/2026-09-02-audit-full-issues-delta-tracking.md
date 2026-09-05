# Audit Full Issue List + Delta Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the audit report list every issue group the scanners found (not just the top 12 AI-narrated ones), and track fixed/new/persisting status for each group against the user's previous audit of the same repo+branch — all deterministic, no added AI cost.

**Architecture:** `FindingGrouper` already produces every group and `AuditPipeline::persistGroups` already persists whatever it's given (proven by the existing `test_narration_is_capped_to_the_tier_budget` test) — the only real cap is `FindingGrouper`'s own `config('audit.findings.max_groups')` (20), which runs before persistence. Raising that config value is the entire "show all issues" change. A new `AuditGroupDeltaService`, modeled directly on the existing `AuditDeltaService`, diffs persisted `audit_finding_groups` rows between the current and previous same-branch report by their natural key (`rule_family|directory|dimension`) — no new hashing, no schema change.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (classic `TestCase`, not Pest), Blade, `barryvdh/laravel-dompdf`.

**Spec:** `docs/superpowers/specs/2026-09-02-audit-full-issues-delta-design.md`

## Deviations from the spec (and why)

The spec argues the shape of this feature; these are implementation-level refinements discovered while reading the actual code, kept here so the reasoning travels with the plan:

1. **`AuditGroupDeltaService::deltasFor()` takes an `AuditReport`, not an `AuditRequest`.** The spec's §6 sketch used `AuditRequest`. But `scoring_version` — the field that must match between the two runs being compared, same as the existing `AuditDeltaService` — lives on `AuditReport`, not `AuditRequest` (`AuditReport.php` `$casts`). `AuditReportController::show()` already has `$auditReport` in scope, not a bare request. Matching the sibling service's exact signature (`AuditDeltaService::deltasFor(AuditReport $report)`) is the natural fit.
2. **No new value-object class.** `AuditDeltaService` returns a plain array, not a class. `AuditGroupDeltaService` follows the same convention — one less concept, consistent with the file it sits next to.
3. **Email gets a one-line summary only, not a duplicated full issue list.** The existing `report-ready.blade.php` already deliberately keeps the email short — it links to the full web report rather than embedding the AI-narrated groups (`$payload['groups']` isn't rendered in the email at all today). Embedding a second full issue list in the email would break that existing convention for no real benefit; the summary line follows the same pattern as the existing overall-score-delta sentence.
4. **PDF gets the deterministic Full Issues list, but no delta badges.** `AuditReportService::generatePdf()` calls `Pdf::loadView('reports.audit', ['report' => $report])` — it has never computed deltas, and threading `AuditGroupDeltaService` into PDF generation would mean calling it a second time outside the request-scoped `AuditReportController` (PDF generation happens on unlock, not on view). The PDF is a static download; delta context stays on the live web report a user reads before downloading it. The Full Issues list itself needs no delta computation, so it's included.

## Global Constraints

- Group identity = `rule_family + directory + dimension` (the same composite key `FindingGrouper` already buckets by) — no per-finding (file:line) fingerprinting in this plan.
- Delta comparison is scoped to the same `email` + `repo_url` + `branch` as the current report, and the same `scoring_version` — a version bump or a different branch breaks the comparison chain (returns `null`), exactly like the existing `AuditDeltaService`.
- No AI cost is added anywhere in this plan — the AI still narrates only `config('audit.tiers.*.narrated_groups')` (12) groups, unchanged.
- Available on every audit tier — no tier gating in any task below.

---

### Task 1: Raise the persisted-group cap so every issue survives grouping

**Files:**
- Modify: `backend/config/audit.php:125`
- Test: `backend/tests/Feature/Services/Findings/FindingGrouperTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new — this only changes a config default. `FindingGrouper::group()` (`Findings/FindingGrouper.php:18-69`) and `AuditPipeline::persistGroups()` (`AuditPipeline.php:104,205-212`) are untouched; they already pass through whatever `group()` returns.

- [ ] **Step 1: Write the failing test**

Add to `FindingGrouperTest`:

```php
    public function test_the_default_group_cap_does_not_truncate_a_realistic_finding_set(): void
    {
        $findings = [];
        for ($i = 1; $i <= 30; $i++) {
            $findings[] = $this->deduped("family.number{$i}", "app/Dir{$i}/File.php", Severity::HIGH, $i);
        }

        // config('audit.findings.max_groups') defaults to 20 today, which
        // silently drops 10 of these 30 distinct issues from every report.
        $this->assertCount(30, app(FindingGrouper::class)->group($findings));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail artisan test --filter=test_the_default_group_cap_does_not_truncate_a_realistic_finding_set`
Expected: FAIL — asserts 30, actual count is 20 (today's default cap).

- [ ] **Step 3: Raise the config default**

In `backend/config/audit.php`, change:

```php
        'max_groups' => 20,
```

to:

```php
        // A safety valve, not a display cap — FindingGrouper already
        // dedupes and buckets by rule_family+directory before this point, so
        // group count is bounded by distinct (rule × directory) combinations,
        // not raw finding count. 1000 is high enough that no realistic repo
        // hits it; it exists to stop a pathological ruleset from producing
        // an unbounded number of DB rows, not to trim a normal report.
        'max_groups' => 1000,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `sail artisan test --filter=FindingGrouperTest`
Expected: PASS, all tests in the file green (including the pre-existing `test_number_of_groups_is_capped`, which explicitly overrides the config to 2 and is unaffected by the default changing).

- [ ] **Step 5: Commit**

```bash
git add backend/config/audit.php backend/tests/Feature/Services/Findings/FindingGrouperTest.php
git commit -m "feat(audit): stop truncating finding groups at 20 per report"
```

---

### Task 2: `AuditGroupDeltaService`

**Files:**
- Create: `backend/app/Services/AuditReport/AuditGroupDeltaService.php`
- Test: `backend/tests/Feature/Services/AuditGroupDeltaServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\AuditReport` (has `auditRequest(): BelongsTo`, `audit_request_id`, `scoring_version`), `App\Models\AuditFindingGroup` (`audit_request_id`, `rule_family`, `directory`, `dimension`, `count` — all plain columns, see `AuditFindingGroup.php:14-24`).
- Produces: `AuditGroupDeltaService::deltasFor(AuditReport $report): ?array`, returning `null` or:
  ```php
  [
      'previous_at' => CarbonInterface,
      'groups' => [
          // key: "{$rule_family}|{$directory}|{$dimension}"
          'php.injection|app/Http|security_hygiene' => [
              'rule_family' => string,
              'directory' => string,
              'dimension' => string,
              'status' => 'new'|'fixed'|'persisting',
              'count' => int|null,          // current count; null when status is 'fixed'
              'previous_count' => int|null, // previous count; null when status is 'new'
              'count_delta' => int|null,    // current - previous; null unless status is 'persisting'
          ],
          // ...
      ],
      'summary' => [
          'fixed_groups' => int,      // groups gone entirely since the previous run
          'new_groups' => int,        // groups that didn't exist in the previous run
          'resolved_findings' => int, // total individual findings resolved: every finding in a fixed group, plus every count decrease within a persisting group
          'new_findings' => int,      // total individual findings added: every finding in a new group, plus every count increase within a persisting group
      ],
  ]
  ```
  Later tasks (3, 4) consume this exact shape.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Services/AuditGroupDeltaServiceTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditGroupDeltaService;
use Tests\Feature\FeatureTest;

class AuditGroupDeltaServiceTest extends FeatureTest
{
    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function reportWithGroups(
        string $email,
        string $repoUrl,
        ?string $branch,
        array $groups,
        int $scoringVersion = 1,
    ): AuditReport {
        $request = AuditRequest::factory()->verified()->create([
            'email' => $email,
            'repo_url' => $repoUrl,
            'branch' => $branch,
        ]);

        $report = AuditReport::factory()->locked()->create([
            'audit_request_id' => $request->id,
            'scoring_version' => $scoringVersion,
        ]);

        foreach ($groups as $group) {
            AuditFindingGroup::factory()->create(array_merge(['audit_request_id' => $request->id], $group));
        }

        return $report;
    }

    public function test_a_group_absent_from_the_previous_run_is_reported_as_new(): void
    {
        $this->reportWithGroups('gd1@example.com', 'https://github.com/acme/app', null, []);
        $current = $this->reportWithGroups('gd1@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 4],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $group = $result['groups']['php.injection|app/Http|security_hygiene'];
        $this->assertSame('new', $group['status']);
        $this->assertSame(4, $group['count']);
        $this->assertNull($group['previous_count']);
        $this->assertSame(1, $result['summary']['new_groups']);
        $this->assertSame(4, $result['summary']['new_findings']);
    }

    public function test_a_group_absent_from_the_current_run_is_reported_as_fixed(): void
    {
        $this->reportWithGroups('gd2@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 4],
        ]);
        $current = $this->reportWithGroups('gd2@example.com', 'https://github.com/acme/app', null, []);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['php.injection|app/Http|security_hygiene'];
        $this->assertSame('fixed', $group['status']);
        $this->assertNull($group['count']);
        $this->assertSame(4, $group['previous_count']);
        $this->assertSame(1, $result['summary']['fixed_groups']);
        $this->assertSame(4, $result['summary']['resolved_findings']);
    }

    public function test_a_persisting_group_with_more_findings_reports_a_positive_delta(): void
    {
        $this->reportWithGroups('gd3@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 5],
        ]);
        $current = $this->reportWithGroups('gd3@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 8],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['jscpd.duplication|src|duplication'];
        $this->assertSame('persisting', $group['status']);
        $this->assertSame(3, $group['count_delta']);
        $this->assertSame(3, $result['summary']['new_findings']);
        $this->assertSame(0, $result['summary']['resolved_findings']);
    }

    public function test_a_persisting_group_with_fewer_findings_reports_a_negative_delta(): void
    {
        $this->reportWithGroups('gd4@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 8],
        ]);
        $current = $this->reportWithGroups('gd4@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 3],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['jscpd.duplication|src|duplication'];
        $this->assertSame('persisting', $group['status']);
        $this->assertSame(-5, $group['count_delta']);
        $this->assertSame(5, $result['summary']['resolved_findings']);
        $this->assertSame(0, $result['summary']['new_findings']);
    }

    public function test_an_unchanged_group_has_a_zero_delta_and_does_not_count_toward_the_summary(): void
    {
        $this->reportWithGroups('gd5@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd5@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertSame(0, $result['groups']['php.injection|app/Http|security_hygiene']['count_delta']);
        $this->assertSame(0, $result['summary']['new_findings']);
        $this->assertSame(0, $result['summary']['resolved_findings']);
    }

    public function test_first_run_for_a_repo_and_branch_has_no_deltas(): void
    {
        $current = $this->reportWithGroups('gd6@example.com', 'https://github.com/acme/app', 'main', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_a_previous_run_on_a_different_branch_is_not_compared(): void
    {
        $this->reportWithGroups('gd7@example.com', 'https://github.com/acme/app', 'main', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd7@example.com', 'https://github.com/acme/app', 'feature/x', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 9],
        ]);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_default_branch_runs_compare_against_each_other(): void
    {
        // Both requests leave branch null (the default-branch convention) --
        // this must behave as "same branch," not as two mismatched nulls.
        $this->reportWithGroups('gd8@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd8@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 5],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $this->assertSame(3, $result['groups']['php.injection|app/Http|security_hygiene']['count_delta']);
    }

    public function test_does_not_compare_across_scoring_versions(): void
    {
        $this->reportWithGroups('gd9@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ], scoringVersion: 1);
        $current = $this->reportWithGroups('gd9@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 9],
        ], scoringVersion: 2);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_summary_totals_aggregate_across_a_mixed_set_of_groups(): void
    {
        $this->reportWithGroups('gd10@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'secrets.credential', 'directory' => 'config', 'dimension' => 'security_hygiene', 'count' => 1], // will be fixed
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 10], // will drop to 4 (6 resolved)
        ]);
        $current = $this->reportWithGroups('gd10@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 4],
            ['rule_family' => 'style.formatting', 'directory' => 'app', 'dimension' => 'structure', 'count' => 3], // new
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertSame(1, $result['summary']['fixed_groups']);
        $this->assertSame(1, $result['summary']['new_groups']);
        $this->assertSame(1 + 6, $result['summary']['resolved_findings']); // fixed group's 1, plus 10->4
        $this->assertSame(3, $result['summary']['new_findings']); // the new group's 3
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `sail artisan test --filter=AuditGroupDeltaServiceTest`
Expected: FAIL — `Class "App\Services\AuditReport\AuditGroupDeltaService" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/app/Services/AuditReport/AuditGroupDeltaService.php`:

```php
<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;

/**
 * Compares persisted finding groups between the current report and the most
 * recent previous report for the same email + repo_url + branch, so a report
 * can say which issues were fixed, which are new, and which persisted with a
 * changed count -- at the group level (rule_family + directory + dimension),
 * not per raw finding. See docs/superpowers/specs/2026-09-02-audit-full-issues-delta-design.md.
 */
class AuditGroupDeltaService
{
    public function deltasFor(AuditReport $report): ?array
    {
        $auditRequest = $report->auditRequest;
        $repoUrl = rtrim((string) $auditRequest->repo_url, '/');

        if ($repoUrl === '') {
            return null;
        }

        $previousReport = AuditReport::query()
            ->whereHas('auditRequest', fn ($query) => $query
                ->where('email', $auditRequest->email)
                ->whereIn('repo_url', [$repoUrl, $repoUrl.'/'])
                ->where('branch', $auditRequest->branch))
            ->where('id', '<', $report->id)
            ->where('scoring_version', $report->scoring_version)
            ->latest('id')
            ->first();

        if ($previousReport === null) {
            return null;
        }

        $current = $this->countsByKey((int) $report->audit_request_id);
        $previous = $this->countsByKey((int) $previousReport->audit_request_id);

        $groups = [];
        $summary = ['fixed_groups' => 0, 'new_groups' => 0, 'resolved_findings' => 0, 'new_findings' => 0];

        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $key) {
            $currentGroup = $current[$key] ?? null;
            $previousGroup = $previous[$key] ?? null;

            if ($currentGroup !== null && $previousGroup === null) {
                $groups[$key] = [...$currentGroup, 'status' => 'new', 'previous_count' => null, 'count_delta' => null];
                $summary['new_groups']++;
                $summary['new_findings'] += $currentGroup['count'];

                continue;
            }

            if ($currentGroup === null && $previousGroup !== null) {
                $groups[$key] = [
                    ...$previousGroup,
                    'count' => null,
                    'status' => 'fixed',
                    'previous_count' => $previousGroup['count'],
                    'count_delta' => null,
                ];
                $summary['fixed_groups']++;
                $summary['resolved_findings'] += $previousGroup['count'];

                continue;
            }

            $delta = $currentGroup['count'] - $previousGroup['count'];
            $groups[$key] = [
                ...$currentGroup,
                'status' => 'persisting',
                'previous_count' => $previousGroup['count'],
                'count_delta' => $delta,
            ];

            if ($delta > 0) {
                $summary['new_findings'] += $delta;
            } elseif ($delta < 0) {
                $summary['resolved_findings'] += abs($delta);
            }
        }

        return ['previous_at' => $previousReport->created_at, 'groups' => $groups, 'summary' => $summary];
    }

    /** @return array<string, array{rule_family: string, directory: string, dimension: string, count: int}> */
    private function countsByKey(int $auditRequestId): array
    {
        return AuditFindingGroup::query()
            ->where('audit_request_id', $auditRequestId)
            ->get(['rule_family', 'directory', 'dimension', 'count'])
            ->mapWithKeys(fn (AuditFindingGroup $group): array => [
                "{$group->rule_family}|{$group->directory}|{$group->dimension}" => [
                    'rule_family' => $group->rule_family,
                    'directory' => $group->directory,
                    'dimension' => $group->dimension,
                    'count' => $group->count,
                ],
            ])
            ->all();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `sail artisan test --filter=AuditGroupDeltaServiceTest`
Expected: PASS, all 10 tests green.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AuditReport/AuditGroupDeltaService.php backend/tests/Feature/Services/AuditGroupDeltaServiceTest.php
git commit -m "feat(audit): add group-level delta tracking between audit runs"
```

---

### Task 3: Wire the full group list and group deltas into `AuditReportController`

**Files:**
- Modify: `backend/app/Http/Controllers/AuditReportController.php:19-47,49-80`
- Test: `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php`

**Interfaces:**
- Consumes: `AuditGroupDeltaService::deltasFor(AuditReport $report): ?array` (Task 2), `AuditRequest::findingGroups(): HasMany` (`AuditRequest.php:210-213`, already orders by `score` desc).
- Produces: the `reports.audit-web` view now always receives `allGroups` (an `Illuminate\Support\Collection<AuditFindingGroup>`, possibly empty) and `groupDeltas` (the array shape from Task 2, or `null`). Task 4 consumes both.

- [ ] **Step 1: Write the failing test**

Add to `AuditReportControllerTest`:

```php
    public function test_report_view_lists_every_persisted_finding_group(): void
    {
        $report = AuditReport::factory()->unlocked()->create();
        AuditFindingGroup::factory()->create([
            'audit_request_id' => $report->audit_request_id,
            'rule_family' => 'php.injection',
            'directory' => 'app/Http',
            'severity' => 'high',
        ]);

        $response = $this->get(app(AuditReportService::class)->signedUrl($report));

        $response->assertOk();
        $response->assertSee('php.injection');
    }
```

Add the import at the top of the file:

```php
use App\Models\AuditFindingGroup;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail artisan test --filter=test_report_view_lists_every_persisted_finding_group`
Expected: FAIL — the view doesn't render a Full Issues section yet, so "php.injection" (which only appears via the new section, not via `$payload['groups']`, since the factory's default payload doesn't mention it) is not found on the page.

- [ ] **Step 3: Wire the controller**

In `backend/app/Http/Controllers/AuditReportController.php`, add the import:

```php
use App\Services\AuditReport\AuditGroupDeltaService;
```

Change the `show()` method's returned view data (`:35-46`) to:

```php
        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
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
```

Change the `sample()` method's returned view data (`:64-79`) to add two keys:

```php
        return view('reports.audit-web', [
            'report' => $report,
            'unlocked' => true,
            'isSample' => true,
            'percentile' => $fixture['percentile'],
            'unlockUrl' => null,
            // The sample exists to show every section a report can carry, and
            // the re-audit trend is one of them. Stored as an offset so the
            // "previous audit" date never goes stale.
            'deltas' => isset($fixture['deltas'])
                ? [
                    'previous_at' => now()->subDays((int) ($fixture['previous_audit_days_ago'] ?? 30)),
                    'deltas' => $fixture['deltas'],
                ]
                : null,
            // The sample is a static fixture, not a persisted AuditRequest --
            // there is no audit_finding_groups row to list or diff.
            'groupDeltas' => null,
            'allGroups' => collect(),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `sail artisan test --filter=AuditReportControllerTest`
Expected: PASS, all tests in the file green.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/AuditReportController.php backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(audit): pass every finding group and group deltas to the report view"
```

---

### Task 4: Render the Full Issues section and delta badges on the web report

**Files:**
- Modify: `backend/resources/views/reports/audit-web.blade.php`
- Test: `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php`

**Interfaces:**
- Consumes: `$allGroups` (`Collection<AuditFindingGroup>`, each with `rule_family`, `directory`, `severity`, `dimension`, `count`, `examples`), `$groupDeltas` (Task 2's array shape or `null`).
- Produces: nothing consumed by a later task — this is the last task in the web-view chain.

- [ ] **Step 1: Write the failing tests**

Add to `AuditReportControllerTest`:

```php
    public function test_report_view_shows_a_resolved_and_new_findings_summary_when_a_previous_run_exists(): void
    {
        $previousRequest = AuditRequest::factory()->verified()->create(['email' => 'summary@example.com', 'repo_url' => 'https://github.com/acme/app']);
        $previousReport = AuditReport::factory()->locked()->create(['audit_request_id' => $previousRequest->id, 'scoring_version' => \App\Services\AuditReport\ScoreCalculator::VERSION]);
        AuditFindingGroup::factory()->create([
            'audit_request_id' => $previousRequest->id,
            'rule_family' => 'secrets.credential',
            'directory' => 'config',
            'count' => 1,
        ]);

        $currentRequest = AuditRequest::factory()->verified()->create(['email' => 'summary@example.com', 'repo_url' => 'https://github.com/acme/app']);
        $currentReport = AuditReport::factory()->unlocked()->create(['audit_request_id' => $currentRequest->id, 'scoring_version' => \App\Services\AuditReport\ScoreCalculator::VERSION]);
        AuditFindingGroup::factory()->create([
            'audit_request_id' => $currentRequest->id,
            'rule_family' => 'style.formatting',
            'directory' => 'app',
            'count' => 2,
        ]);

        $response = $this->get(app(AuditReportService::class)->signedUrl($currentReport));

        $response->assertOk();
        $response->assertSee('1 issue resolved', false);
        $response->assertSee('2 new', false);
    }

    public function test_report_view_shows_no_delta_summary_on_a_first_run(): void
    {
        $report = AuditReport::factory()->unlocked()->create();
        AuditFindingGroup::factory()->create(['audit_request_id' => $report->audit_request_id]);

        $response = $this->get(app(AuditReportService::class)->signedUrl($report));

        $response->assertOk();
        $response->assertDontSee('issue resolved', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `sail artisan test --filter=AuditReportControllerTest`
Expected: FAIL on both new tests — no summary sentence exists in the view yet (the `test_report_view_lists_every_persisted_finding_group` test from Task 3 is passing at this point once Task 4 Step 3 lands; run it again after Step 3 to confirm).

- [ ] **Step 3: Add the Full Issues section and summary line**

In `backend/resources/views/reports/audit-web.blade.php`, insert a new section immediately after the existing `@if ($groups !== [])` … `@endif` block for "What we found" (the block ending just before `@php($metrics = $report->auditRequest->metrics)`):

```blade
    @if ($allGroups->isNotEmpty())
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
            <h2 class="text-base font-bold mb-3">{{ __('Full issue list') }}</h2>
            @if ($groupDeltas !== null)
                <p class="text-xs text-stone-500 mb-3">
                    @php($resolved = $groupDeltas['summary']['resolved_findings'])
                    @php($new = $groupDeltas['summary']['new_findings'])
                    {{ trans_choice('{0} No issues resolved|{1} 1 issue resolved|[2,*] :count issues resolved', $resolved, ['count' => $resolved]) }},
                    {{ trans_choice('{0} no new issues|{1} 1 new issue|[2,*] :count new issues', $new, ['count' => $new]) }}
                    {{ __('since your previous audit on :date', ['date' => $groupDeltas['previous_at']->format('Y-m-d')]) }}
                </p>
            @endif
            @foreach ($allGroups as $fullGroup)
                @php($deltaKey = "{$fullGroup->rule_family}|{$fullGroup->directory}|{$fullGroup->dimension}")
                @php($groupDelta = $groupDeltas['groups'][$deltaKey] ?? null)
                <div class="border-t border-stone-200 py-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $groupBadge[$fullGroup->severity] ?? 'bg-stone-100 text-stone-700' }}">{{ $fullGroup->severity }}</span>
                        <span class="font-semibold">{{ $fullGroup->rule_family }}</span>
                        <span class="text-xs text-stone-500">{{ $fullGroup->directory }} ·
                            {{ trans_choice('{1} :count finding|[2,*] :count findings', $fullGroup->count, ['count' => $fullGroup->count]) }}
                        </span>
                        @if ($groupDelta !== null && $groupDelta['status'] === 'new')
                            <span class="rounded-full bg-lime-50 px-2 py-0.5 text-[10px] font-bold text-lime-800">{{ __('new') }}</span>
                        @elseif ($groupDelta !== null && $groupDelta['status'] === 'persisting' && $groupDelta['count_delta'] !== 0)
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $groupDelta['count_delta'] > 0 ? 'bg-red-50 text-red-800' : 'bg-lime-50 text-lime-800' }}">{{ sprintf('%+d', $groupDelta['count_delta']) }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `sail artisan test --filter=AuditReportControllerTest`
Expected: PASS, all tests in the file green, including the two new ones and the one from Task 3.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/views/reports/audit-web.blade.php backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(audit): render the full issue list and delta badges on the web report"
```

---

### Task 5: Render the Full Issues list on the PDF report

**Files:**
- Modify: `backend/resources/views/reports/audit.blade.php`
- Test: `backend/tests/Feature/Services/AuditReportServiceTest.php`

**Interfaces:**
- Consumes: `$report->auditRequest->findingGroups` (same `HasMany` used in Task 3 — the PDF view already has `$report` in scope via `Pdf::loadView('reports.audit', ['report' => $report])`, `AuditReportService.php:147`, so no controller/service signature change is needed).
- Produces: nothing consumed elsewhere — terminal task for the PDF surface.

- [ ] **Step 1: Write the failing test**

`AuditReportServiceTest` already exists (`backend/tests/Feature/Services/AuditReportServiceTest.php`) and already has a private `payload(): array` helper (`:123`). Add the imports `use App\Models\AuditFindingGroup;` and `use Illuminate\Support\Facades\Storage;`, and this test — `AuditReportService::create()` is what triggers PDF generation via `generatePdf()` (`AuditReportService.php:144-150`):

```php
    public function test_generated_pdf_lists_every_persisted_finding_group(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFindingGroup::factory()->create([
            'audit_request_id' => $request->id,
            'rule_family' => 'jscpd.duplication',
            'directory' => 'src',
        ]);

        $report = app(AuditReportService::class)->create($request, $this->payload(), 1);

        $pdfContents = Storage::disk('local')->get($report->pdf_path);
        $this->assertStringContainsString('jscpd.duplication', $pdfContents);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail artisan test --filter=test_generated_pdf_lists_every_persisted_finding_group`
Expected: FAIL — `jscpd.duplication` isn't in the PDF yet (the PDF only renders `$payload['groups']`, the AI-narrated subset, which the test's `$this->payload()` fixture doesn't include).

- [ ] **Step 3: Add the section to the PDF template**

In `backend/resources/views/reports/audit.blade.php`, insert a new section immediately after the existing "What we found" `@if ($groups !== [])` … `@endif` block, before `@php($metrics = $report->auditRequest->metrics)`:

```blade
    @php($allGroups = $report->auditRequest->findingGroups)
    @if ($allGroups->isNotEmpty())
        <h2>{{ __('Full issue list') }}</h2>
        <table>
            <tr><th>{{ __('Rule family') }}</th><th>{{ __('Location') }}</th><th>{{ __('Severity') }}</th><th>{{ __('Count') }}</th></tr>
            @foreach ($allGroups as $fullGroup)
                <tr>
                    <td>{{ $fullGroup->rule_family }}</td>
                    <td>{{ $fullGroup->directory }}</td>
                    <td class="impact-{{ $fullGroup->severity }}">{{ strtoupper($fullGroup->severity) }}</td>
                    <td>{{ $fullGroup->count }}</td>
                </tr>
            @endforeach
        </table>
    @endif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `sail artisan test --filter=test_generated_pdf_lists_every_persisted_finding_group`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/views/reports/audit.blade.php backend/tests/Feature/Services/AuditReportServiceTest.php
git commit -m "feat(audit): list every persisted finding group in the PDF report"
```

---

### Task 6: Add a one-line fixed/new summary to the report-ready email

**Files:**
- Modify: `backend/app/Mail/Audit/AuditReportReady.php`
- Modify: `backend/app/Services/AuditReport/AuditReportService.php:93-101`
- Modify: `backend/resources/views/emails/audit/report-ready.blade.php`
- Test: `backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php`

**Interfaces:**
- Consumes: `AuditGroupDeltaService::deltasFor(AuditReport $report): ?array` (Task 2).
- Produces: `AuditReportReady` gains a fourth constructor property, `?array $groupDeltas`. Nothing later depends on it — terminal task.

- [ ] **Step 1: Write the failing test**

Add to `AuditReportControllerTest`:

```php
    public function test_send_mails_a_resolved_and_new_findings_summary_when_a_previous_run_exists(): void
    {
        Mail::fake();

        $previousRequest = AuditRequest::factory()->verified()->create(['email' => 'mailsummary@example.com', 'repo_url' => 'https://github.com/acme/app']);
        $service = app(AuditReportService::class);
        $previousReport = $service->create($previousRequest, $this->payload(), ScoreCalculator::VERSION);
        AuditFindingGroup::factory()->create(['audit_request_id' => $previousRequest->id, 'rule_family' => 'secrets.credential', 'directory' => 'config', 'count' => 1]);

        $currentRequest = AuditRequest::factory()->verified()->create(['email' => 'mailsummary@example.com', 'repo_url' => 'https://github.com/acme/app']);
        $currentReport = $service->create($currentRequest, $this->payload(), ScoreCalculator::VERSION);
        AuditFindingGroup::factory()->create(['audit_request_id' => $currentRequest->id, 'rule_family' => 'style.formatting', 'directory' => 'app', 'count' => 2]);

        $service->send($currentReport->fresh());

        Mail::assertQueued(AuditReportReady::class, function ($mail) {
            $rendered = $mail->render();

            return str_contains($rendered, '1 issue resolved') && str_contains($rendered, '2 new');
        });
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail artisan test --filter=test_send_mails_a_resolved_and_new_findings_summary_when_a_previous_run_exists`
Expected: FAIL — the email doesn't render any group-delta summary yet.

- [ ] **Step 3: Thread `groupDeltas` through the mailable**

In `backend/app/Mail/Audit/AuditReportReady.php`, add the constructor property:

```php
    public function __construct(
        public AuditReport $report,
        public string $signedUrl,
        public ?array $deltas = null,
        public ?array $groupDeltas = null,
    ) {}
```

`AuditReportService`'s constructor (`:19-23`) already constructor-injects `AuditDeltaService` as `$this->deltaService`. Add `AuditGroupDeltaService` as a constructor-injected property the same way:

```php
    public function __construct(
        private AuditFunnelRecorder $funnel,
        private AuditDeltaService $deltaService,
        private AuditGroupDeltaService $groupDeltaService,
        private AuditMailer $auditMailer,
    ) {}
```

Update the `send()` method (`:93-101`):

```php
    public function send(AuditReport $report): void
    {
        $this->auditMailer->send(
            new AuditReportReady(
                $report,
                $this->signedUrl($report),
                $this->deltaService->deltasFor($report),
                $this->groupDeltaService->deltasFor($report),
            ),
            $report->auditRequest->email,
            $report->auditRequest,
        );

        $report->auditRequest->update(['status' => AuditRequestStatus::SENT->value]);

        if ($report->auditRequest->source !== 'dashboard') {
            $this->funnel->record(AuditFunnelRecorder::STAGE_REPORT_SENT, $report->auditRequest);
        }
    }
```

In `backend/resources/views/emails/audit/report-ready.blade.php`, add a new paragraph immediately after the existing overall-delta `@if` block (after the line ending `@endif` that closes the `$deltas` block, before the final "This report was generated by automated analysis…" paragraph):

```blade
            @if ($groupDeltas !== null && ($groupDeltas['summary']['resolved_findings'] > 0 || $groupDeltas['summary']['new_findings'] > 0))
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ trans_choice('{0} No issues resolved|{1} 1 issue resolved|[2,*] :count issues resolved', $groupDeltas['summary']['resolved_findings'], ['count' => $groupDeltas['summary']['resolved_findings']]) }},
                    {{ trans_choice('{0} no new issues|{1} 1 new issue|[2,*] :count new issues', $groupDeltas['summary']['new_findings'], ['count' => $groupDeltas['summary']['new_findings']]) }}
                    {{ __('since your previous audit of this repository.') }}
                </p>
            @endif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `sail artisan test --filter=AuditReportControllerTest`
Expected: PASS, all tests in the file green.

- [ ] **Step 5: Run the full backend suite and format**

Run: `sail artisan test --compact` and `vendor/bin/pint --test` (from `backend/`)
Expected: full suite green, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Mail/Audit/AuditReportReady.php backend/app/Services/AuditReport/AuditReportService.php backend/resources/views/emails/audit/report-ready.blade.php backend/tests/Feature/Http/Controllers/AuditReportControllerTest.php
git commit -m "feat(audit): summarize resolved and new findings in the report-ready email"
```

---

## Self-Review Notes

- **Spec coverage:** §5 (data model) → Task 1. §6 (`AuditGroupDeltaService`) → Task 2. §7 (report display, all three surfaces) → Tasks 3, 4 (web), 5 (PDF), 6 (email) — see "Deviations from the spec" for the two intentional scope narrowings (email: summary only; PDF: no delta badges) and their rationale. §8 (edge cases: first audit, scoring-version bump, different branch) → covered by Task 2's tests. §9 (testing) → one test per case, satisfied across Tasks 1–6.
- **Placeholder scan:** no TBD/TODO; every step has runnable code, not a description of code.
- **Type consistency:** `AuditGroupDeltaService::deltasFor()`'s return shape is defined once in Task 2 and used identically (same keys: `previous_at`, `groups`, `summary`, same per-group keys: `status`, `count`, `previous_count`, `count_delta`) in Tasks 3, 4, and 6 — no renaming across tasks.
