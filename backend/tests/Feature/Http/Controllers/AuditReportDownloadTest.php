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
