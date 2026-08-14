<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
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

    public function test_only_allowance_funded_runs_are_metered(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        // A purchased run and a free run must not spend plan quota.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::FREE->value,
        ]);

        $this->assertSame(2, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
    }

    public function test_each_tier_meters_independently(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);
        AuditRequest::factory()->count(3)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        $this->assertSame(1, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
        $this->assertSame(3, $this->service->runsUsedThisMonth($user, AuditTier::DEEP_AI));
        $this->assertSame(0, $this->service->runsUsedThisMonth($user, AuditTier::EXPERT));
    }

    public function test_last_months_runs_do_not_count(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => now()->startOfMonth()->subDay(),
        ]);

        $this->assertSame(0, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
    }

    public function test_diagnostic_quota_is_the_lifetime_free_quota(): void
    {
        $user = $this->createUser();
        AuditRequest::factory()->freeRun()->create(['email' => $user->email]);

        $quota = $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC);

        $this->assertTrue($quota->isLifetime);
        $this->assertSame(3, $quota->limit);
        $this->assertSame(1, $quota->used);
        $this->assertSame(2, $quota->remaining());
        $this->assertFalse($quota->purchasable());
    }

    public function test_paid_tiers_carry_their_catalog_price(): void
    {
        $user = $this->createUser();

        $this->assertSame(4900, $this->service->quotaFor($user, null, AuditTier::AUTOMATED)->priceCents);
        $this->assertSame(19900, $this->service->quotaFor($user, null, AuditTier::DEEP_AI)->priceCents);
        $this->assertSame(99900, $this->service->quotaFor($user, null, AuditTier::EXPERT)->priceCents);
    }

    public function test_quotas_returns_one_entry_per_tier(): void
    {
        $user = $this->createUser();

        $quotas = $this->service->quotas($user, null);

        $this->assertCount(count(AuditTier::cases()), $quotas);
    }

    public function test_a_null_tenant_has_no_allowance(): void
    {
        $user = $this->createUser();

        $this->assertSame(0, $this->service->allowance(null, AuditTier::AUTOMATED));
        $this->assertSame(0, $this->service->remainingRuns($user, null, AuditTier::AUTOMATED));
    }

    public function test_consuming_a_free_run_marks_the_funding(): void
    {
        $request = AuditRequest::factory()->create(['funding' => AuditFunding::ALLOWANCE->value]);

        $this->service->consumeFreeRun($request);

        $this->assertTrue($request->fresh()->free_run);
        $this->assertSame(AuditFunding::FREE, $request->fresh()->funding);
    }
}
