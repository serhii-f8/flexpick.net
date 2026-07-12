<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportPageTest extends FeatureTest
{
    public function test_locked_report_shows_titles_but_hides_details(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('No tests')                       // risk title visible
            ->assertDontSee('Add a smoke suite')          // recommendation hidden
            ->assertDontSee('0 test files')               // evidence hidden
            ->assertSee(__('Unlock full report'))
            ->assertSee('/unlock');
    }

    public function test_unlocked_report_shows_everything_and_pdf_link(): void
    {
        $report = AuditReport::factory()->unlocked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $this->get($url)
            ->assertOk()
            ->assertSee('Add a smoke suite')
            ->assertSee('Add CI')
            ->assertSee(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertDontSee(__('Unlock full report'));
    }

    public function test_sample_report_is_public_and_unlocked(): void
    {
        $this->get('/reports/sample')
            ->assertOk()
            ->assertSee(__('Sample report'))
            ->assertSee(__('What to fix first'));
    }
}
