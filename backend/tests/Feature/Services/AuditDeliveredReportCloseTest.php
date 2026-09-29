<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditCreditNeeded;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTest;

class AuditDeliveredReportCloseTest extends FeatureTest
{
    /**
     * @return array{0: AuditRequest, 1: Tenant}
     */
    private function deliveredRequest(AuditRequestStatus $status): array
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => $status->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $request->id]);

        return [$request->refresh(), $tenant];
    }

    /**
     * @return array<string, array{0: AuditRequestStatus}>
     */
    public static function deliveredStatuses(): array
    {
        return [
            'report_ready' => [AuditRequestStatus::REPORT_READY],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW],
        ];
    }

    #[DataProvider('deliveredStatuses')]
    public function test_a_failed_retry_never_demotes_or_refunds_on_not_analyzable(AuditRequestStatus $status): void
    {
        Mail::fake();
        [$request, $tenant] = $this->deliveredRequest($status);

        app(AuditRequestService::class)->closeNotAnalyzable($request, 'Repository is no longer reachable.');

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        $this->assertNotSame('Repository is no longer reachable.', $request->failure_reason);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $entry = collect($request->pipeline_log)->firstWhere('step', 'retry_failed');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Repository is no longer reachable.', $entry['message']);
        $this->assertNull(collect($request->pipeline_log)->firstWhere('step', 'refunded'));
    }

    #[DataProvider('deliveredStatuses')]
    public function test_a_failed_retry_never_demotes_or_refunds_on_awaiting_credit(AuditRequestStatus $status): void
    {
        Mail::fake();
        [$request, $tenant] = $this->deliveredRequest($status);

        app(AuditRequestService::class)->closeAwaitingCredit($request, 'Needs 2 runs; 1 available.', tooLarge: true);

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $entry = collect($request->pipeline_log)->firstWhere('step', 'retry_failed');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Needs 2 runs; 1 available.', $entry['message']);
    }

    public function test_refund_refuses_a_request_that_has_a_report(): void
    {
        [$request, $tenant] = $this->deliveredRequest(AuditRequestStatus::REPORT_READY);

        $this->assertFalse(app(AuditEntitlementService::class)->refund($request));

        $this->assertNull($request->refresh()->credit_refunded_at);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_request_without_a_report_still_closes_and_refunds(): void
    {
        Mail::fake();
        $tenant = $this->createTenant();
        $make = fn () => AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::ANALYZING->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        $service = app(AuditRequestService::class);

        $notAnalyzable = $make();
        $service->closeNotAnalyzable($notAnalyzable, 'No access.');
        $awaiting = $make();
        $service->closeAwaitingCredit($awaiting, 'Needs more.', tooLarge: false);

        $this->assertSame(AuditRequestStatus::NOT_ANALYZABLE->value, $notAnalyzable->refresh()->status);
        $this->assertNotNull($notAnalyzable->credit_refunded_at);
        $this->assertSame(AuditRequestStatus::AWAITING_CREDIT->value, $awaiting->refresh()->status);
        $this->assertNotNull($awaiting->credit_refunded_at);
        $this->assertSame(2, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertQueued(AuditRepoAccessNeeded::class);
        Mail::assertQueued(AuditCreditNeeded::class);
    }
}
