<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditReportReady;
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
