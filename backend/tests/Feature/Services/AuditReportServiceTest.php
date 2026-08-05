<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditReportServiceTest extends FeatureTest
{
    public function test_expert_tier_holds_instead_of_sending(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value]);

        $report = app(AuditReportService::class)->createAndDeliver($request, $this->payload(), 1);

        $this->assertSame(AuditRequestStatus::EXPERT_REVIEW->value, $request->fresh()->status);
        Mail::assertNotQueued(AuditReportReady::class);
        $this->assertNotNull($report->id);
    }

    public function test_every_other_tier_sends_as_before(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);

        app(AuditReportService::class)->createAndDeliver($request, $this->payload(), 1);

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        Mail::assertQueued(AuditReportReady::class);
    }

    public function test_publish_requires_an_expert_summary(): void
    {
        $report = AuditReport::factory()->create([
            'audit_request_id' => AuditRequest::factory()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]),
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => '',
                'review_notes' => '',
                'reviewed_by' => '',
                'reviewed_at' => '',
            ]]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(AuditReportService::class)->publish($report);
    }

    public function test_publish_requires_an_authenticated_user(): void
    {
        $report = AuditReport::factory()->create([
            'audit_request_id' => AuditRequest::factory()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]),
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => 'Ready to publish.',
                'review_notes' => '',
                'reviewed_by' => '',
                'reviewed_at' => '',
            ]]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AuditReportService::publish() requires an authenticated user.');
        app(AuditReportService::class)->publish($report);
    }

    public function test_publish_on_an_already_sent_report_is_a_noop(): void
    {
        Mail::fake();
        $report = AuditReport::factory()->create([
            'audit_request_id' => AuditRequest::factory()->create([
                'tier' => AuditTier::EXPERT->value,
                'status' => AuditRequestStatus::SENT->value,
            ]),
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => 'Already published.',
                'review_notes' => '',
                'reviewed_by' => 'Some Reviewer',
                'reviewed_at' => now()->toIso8601String(),
            ]]),
        ]);

        app(AuditReportService::class)->publish($report);

        Mail::assertNotQueued(AuditReportReady::class);
    }

    public function test_publish_stamps_attribution_regenerates_pdf_and_sends(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $report = AuditReport::factory()->create([
            'audit_request_id' => AuditRequest::factory()->create([
                'tier' => AuditTier::EXPERT->value,
                'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            ]),
            'payload' => array_merge($this->payload(), ['expert_review' => [
                'expert_summary' => 'Solid codebase, minor nits.',
                'review_notes' => 'See risks.',
                'reviewed_by' => '',
                'reviewed_at' => '',
            ]]),
        ]);
        $oldPdfPath = $report->pdf_path;

        app(AuditReportService::class)->publish($report);

        $report->refresh();
        $this->assertSame($admin->name, $report->payload['expert_review']['reviewed_by']);
        $this->assertNotEmpty($report->payload['expert_review']['reviewed_at']);
        $this->assertNotNull($report->pdf_path);
        $this->assertSame(AuditRequestStatus::SENT->value, $report->auditRequest->fresh()->status);
        Mail::assertQueued(AuditReportReady::class);
    }

    private function payload(): array
    {
        return [
            'summary' => 'ok',
            'scores' => ['overall' => 50],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [],
        ];
    }
}
