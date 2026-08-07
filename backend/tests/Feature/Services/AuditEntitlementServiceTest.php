<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditEntitlementServiceTest extends FeatureTest
{
    private AuditEntitlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditEntitlementService::class);
    }

    public function test_fresh_email_has_three_free_runs(): void
    {
        $this->assertSame(3, $this->service->freeRunsLimit('fresh@example.com'));
        $this->assertSame(0, $this->service->freeRunsUsed('fresh@example.com'));
        $this->assertTrue($this->service->hasFreeRun('fresh@example.com'));
    }

    public function test_only_free_run_flagged_requests_count(): void
    {
        AuditRequest::factory()->count(2)->freeRun()->create(['email' => 'used@example.com']);
        AuditRequest::factory()->create(['email' => 'used@example.com']); // not flagged — e.g. a failed submission

        $this->assertSame(2, $this->service->freeRunsUsed('used@example.com'));
        $this->assertTrue($this->service->hasFreeRun('used@example.com'));
    }

    public function test_quota_exhausts_at_limit(): void
    {
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);

        $this->assertFalse($this->service->hasFreeRun('maxed@example.com'));
    }

    public function test_registered_user_bonus_extends_limit(): void
    {
        $user = User::factory()->create(['email' => 'bonus@example.com']);
        UserParameter::create(['user_id' => $user->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '2']);
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'bonus@example.com']);

        $this->assertSame(5, $this->service->freeRunsLimit('bonus@example.com'));
        $this->assertTrue($this->service->hasFreeRun('bonus@example.com'));
    }

    public function test_consume_free_run_sets_flag(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'c@example.com']);

        $this->service->consumeFreeRun($request);

        $this->assertTrue($request->refresh()->free_run);
        $this->assertSame(1, $this->service->freeRunsUsed('c@example.com'));
    }

    /**
     * A user who registers directly -- rather than arriving from the public
     * audit form -- has no prior request and no subscription, but still holds
     * the free-run quota. Without this, the whole dashboard audit UI is hidden
     * from them and they have no in-app route to create a first request.
     */
    public function test_free_runs_alone_grant_audit_access(): void
    {
        $user = User::factory()->create(['email' => 'fresh-signup@example.com']);

        $this->assertTrue($this->service->hasFreeRun($user->email));
        $this->assertTrue($this->service->hasAuditAccess($user, null));
    }

    public function test_no_audit_access_without_free_runs_subscription_or_requests(): void
    {
        config(['audit.free_reports_limit' => 0]);
        $user = User::factory()->create(['email' => 'no-quota@example.com']);

        $this->assertFalse($this->service->hasFreeRun($user->email));
        $this->assertFalse($this->service->hasAuditAccess($user, null));
    }
}
