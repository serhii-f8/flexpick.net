<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
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

    public function test_fresh_email_has_no_free_runs_by_default(): void
    {
        $this->assertSame(0, $this->service->freeRunsLimit('fresh@example.com'));
        $this->assertSame(0, $this->service->freeRunsUsed('fresh@example.com'));
        $this->assertFalse($this->service->hasFreeRun('fresh@example.com'));
    }

    public function test_an_explicit_limit_still_grants_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);

        $this->assertSame(3, $this->service->freeRunsLimit('configured@example.com'));
        $this->assertTrue($this->service->hasFreeRun('configured@example.com'));
    }

    public function test_only_free_run_flagged_requests_count(): void
    {
        config(['audit.free_reports_limit' => 3]);
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

        $this->assertSame(2, $this->service->freeRunsLimit('bonus@example.com'));
        $this->assertTrue($this->service->hasFreeRun('bonus@example.com'));

        AuditRequest::factory()->count(2)->freeRun()->create(['email' => 'bonus@example.com']);
        $this->assertFalse($this->service->hasFreeRun('bonus@example.com'));
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
        UserParameter::create(['user_id' => $user->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '1']);

        $this->assertTrue($this->service->hasFreeRun($user->email));
        $this->assertTrue($this->service->hasAuditAccess($user, null));
    }

    /**
     * The production default is zero free runs, so a user who registers
     * directly clears none of the quota arms -- and used to be locked out of
     * the whole dashboard audit UI, including the only in-app route to a
     * checkout. Every tier has a catalog price, so being able to buy one is
     * itself access. Deliberately does not pin audit.free_reports_limit: the
     * production default is precisely what this test is about.
     */
    public function test_a_buyable_tier_alone_grants_audit_access_at_the_production_default(): void
    {
        $user = User::factory()->create(['email' => 'direct-signup@example.com']);

        $this->assertSame(0, (int) config('audit.free_reports_limit'));
        $this->assertFalse($this->service->hasFreeRun($user->email));
        $this->assertTrue($this->service->hasAuditAccess($user, null));
    }

    public function test_no_audit_access_without_free_runs_subscription_requests_or_a_buyable_tier(): void
    {
        // An empty catalog is the only way left to have nothing at all: with
        // one, any authenticated user can always reach a purchase.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
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
        config(['audit.free_reports_limit' => 3]);
        $user = $this->createUser();
        AuditRequest::factory()->freeRun()->create(['email' => $user->email]);

        $quota = $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC);

        $this->assertTrue($quota->isLifetime);
        $this->assertSame(3, $quota->limit);
        $this->assertSame(1, $quota->used);
        $this->assertSame(2, $quota->remaining());
        $this->assertSame(4900, $quota->priceCents);
        $this->assertTrue($quota->purchasable());
    }

    public function test_paid_tiers_carry_their_catalog_price(): void
    {
        $user = $this->createUser();

        $this->assertSame(4900, $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC)->priceCents);
        $this->assertSame(11900, $this->service->quotaFor($user, null, AuditTier::AUTOMATED)->priceCents);
        $this->assertSame(24900, $this->service->quotaFor($user, null, AuditTier::DEEP_AI)->priceCents);
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

    /**
     * quotaFor()'s monthly (non-lifetime) branch is the principal new money
     * API -- the tier selector and usage widget read limit/used/remaining()
     * off it. Both other quotaFor() tests pass a null tenant, so limit and
     * used are always 0 there; this is the only test that would catch the
     * `limit:`/`used:` named arguments being transposed, or allowance() and
     * runsUsedThisMonth() being swapped.
     */
    /**
     * A tenant whose plan grants a Diagnostic allowance (Partner is
     * currently the only one) switches Diagnostic to the same metered
     * semantics every other tier already has, instead of the per-email
     * lifetime free-run count.
     */
    public function test_diagnostic_quota_switches_to_a_subscription_allowance_when_the_plan_grants_one(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_diagnostic_credits' => 999]);

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        $quota = $this->service->quotaFor($user, $tenant, AuditTier::DIAGNOSTIC);

        $this->assertFalse($quota->isLifetime);
        $this->assertSame(999, $quota->limit);
        $this->assertSame(1, $quota->used);
        $this->assertSame(998, $quota->remaining());
    }

    public function test_quota_for_a_monthly_tier_reflects_a_real_allowance(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5]);

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        $quota = $this->service->quotaFor($user, $tenant, AuditTier::AUTOMATED);

        $this->assertFalse($quota->isLifetime);
        $this->assertSame(5, $quota->limit);
        $this->assertSame(2, $quota->used);
        $this->assertSame(3, $quota->remaining());
    }

    /**
     * The any-tier loop in hasAuditAccess() exists solely so a tenant holding
     * only Expert credits (no automated allowance) isn't locked out of the
     * dashboard nav. Nothing else exercises that branch.
     */
    public function test_expert_only_credits_grant_audit_access(): void
    {
        // The catalog is emptied so the purchasable-tier arm cannot answer
        // for this one -- the allowance loop has to.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
        [$user, $tenant] = $this->subscribedTenant(['audit_expert_credits' => 1]);

        $this->assertFalse($this->service->hasFreeRun($user->email));
        $this->assertTrue($this->service->hasAuditAccess($user, $tenant));
    }

    /** @return array{0: User, 1: Tenant} */
    private function subscribedTenant(array $productMetadata): array
    {
        $user = $this->createUser();
        $tenant = $this->createTenant();
        $tenant->users()->attach($user);

        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return [$user, $tenant];
    }
}
