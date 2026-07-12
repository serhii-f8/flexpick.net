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
