<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportControllerTest extends FeatureTest
{
    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }

    public function test_create_renders_pdf_and_links_existing_user(): void
    {
        $user = $this->createUser();
        $request = AuditRequest::factory()->create(['email' => $user->email]);

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertSame($user->id, $report->user_id);
        $this->assertSame(AuditRequestStatus::REPORT_READY->value, $request->fresh()->status);
        Storage::disk('local')->assertExists($report->pdf_path);
    }

    public function test_send_mails_report_and_marks_sent(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create();
        $service = app(AuditReportService::class);
        $report = $service->create($request, $this->payload());

        $service->send($report);

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        Mail::assertQueued(AuditReportReady::class, fn ($mail) => $mail->hasTo($request->email));
    }

    public function test_signed_url_shows_web_report(): void
    {
        $report = AuditReport::factory()->create();

        $url = app(AuditReportService::class)->signedUrl($report);
        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertSee('Fixture summary.');
    }

    public function test_unsigned_url_is_rejected_with_friendly_page(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->create();

        $response = $this->get(route('reports.view', $report));

        $response->assertStatus(403);
        $response->assertSee(__('This report link has expired'));
    }

    public function test_download_requires_ownership(): void
    {
        $this->withExceptionHandling();
        Storage::disk('local')->put('audit-reports/owned.pdf', '%PDF-1.4');
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $report = AuditReport::factory()->create(['user_id' => $owner->id, 'pdf_path' => 'audit-reports/owned.pdf']);

        $this->actingAs($stranger)->get(route('reports.download', $report))->assertStatus(403);
        $this->actingAs($owner)->get(route('reports.download', $report))->assertStatus(200);
    }
}
