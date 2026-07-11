<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditReportUnlocked;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportUnlockTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }

    public function test_web_source_report_is_born_locked_without_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNull($report->unlocked_at);
        $this->assertNull($report->pdf_path);
    }

    public function test_dashboard_source_report_is_born_unlocked_with_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->dashboardSource()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
        Storage::disk('local')->assertExists($report->pdf_path);
    }

    public function test_unlock_generates_pdf_and_sends_mail_once(): void
    {
        $request = AuditRequest::factory()->verified()->create();
        $report = app(AuditReportService::class)->create($request, $this->payload());

        app(AuditReportService::class)->unlock($report);
        app(AuditReportService::class)->unlock($report); // idempotent

        $report->refresh();
        $this->assertNotNull($report->unlocked_at);
        Storage::disk('local')->assertExists($report->pdf_path);
        Mail::assertQueued(AuditReportUnlocked::class, 1);
    }

    public function test_locked_report_pdf_download_is_denied(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->locked()->create();
        $user = \App\Models\User::factory()->create();
        $report->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertNotFound();
    }
}
