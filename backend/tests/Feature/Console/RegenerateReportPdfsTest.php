<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
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

    public function test_one_failing_report_does_not_stop_the_rest(): void
    {
        Storage::fake('local');
        $broken = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);
        $healthy = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);
        $attempted = [];

        $this->partialMock(AuditReportService::class, function ($mock) use ($broken, &$attempted): void {
            $mock->shouldReceive('regeneratePdf')->andReturnUsing(function (AuditReport $report) use ($broken, &$attempted): void {
                $attempted[] = $report->id;

                if ($report->id === $broken->id) {
                    throw new \RuntimeException('dompdf choked');
                }
            });
        });

        $this->artisan('app:regenerate-report-pdfs')->assertFailed();

        // Other tests' reports may share the table; what matters is that the
        // healthy report, created after the broken one, was still reached.
        $this->assertContains($broken->id, $attempted);
        $this->assertContains($healthy->id, $attempted);
        $this->assertGreaterThan(array_search($broken->id, $attempted, true), array_search($healthy->id, $attempted, true));
    }
}
