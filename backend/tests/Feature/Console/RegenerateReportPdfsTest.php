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
