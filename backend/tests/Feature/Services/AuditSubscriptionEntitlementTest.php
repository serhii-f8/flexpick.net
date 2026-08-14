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
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditSubscriptionEntitlementTest extends FeatureTest
{
    public function test_allowance_is_zero_without_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->assertSame(0, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::AUTOMATED));
        $this->assertSame(0, app(AuditEntitlementService::class)->remainingRuns($user, $tenant, AuditTier::AUTOMATED));
    }

    public function test_allowance_reads_product_metadata_of_active_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        $this->assertSame(5, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::AUTOMATED));
    }

    public function test_dashboard_runs_this_month_reduce_remaining(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => now()->subMonths(2),
        ]);
        // Guest-funnel run: free-funded, so it never touches plan quota.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::FREE->value,
        ]);

        $service = app(AuditEntitlementService::class);
        $this->assertSame(2, $service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
        $this->assertSame(3, $service->remainingRuns($user, $tenant, AuditTier::AUTOMATED));
    }

    public function test_subscription_allowance_meters_automated_runs_only(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 2]);

        // Three automated dashboard runs consume the allowance.
        AuditRequest::factory()->count(3)->create([
            'user_id' => $user->id, 'source' => 'dashboard',
            'tier' => AuditTier::AUTOMATED->value, 'created_at' => now(),
        ]);

        // A diagnostic run must NOT consume the paid allowance.
        AuditRequest::factory()->create([
            'user_id' => $user->id, 'source' => 'dashboard',
            'tier' => AuditTier::DIAGNOSTIC->value, 'created_at' => now(),
        ]);

        $this->assertSame(2, app(AuditEntitlementService::class)->remainingRuns($user, $tenant, AuditTier::AUTOMATED));
    }

    public function test_deep_ai_credits_are_metered_separately(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 2]);

        AuditRequest::factory()->create([
            'user_id' => $user->id, 'source' => 'dashboard',
            'tier' => AuditTier::DEEP_AI->value, 'created_at' => now(),
        ]);

        $service = app(AuditEntitlementService::class);

        $this->assertSame(2, $service->allowance($tenant, AuditTier::DEEP_AI));
        $this->assertSame(1, $service->remainingRuns($user, $tenant, AuditTier::DEEP_AI));
        // A deep_ai run does not also consume an automated run.
        $this->assertSame(5, $service->remainingRuns($user, $tenant, AuditTier::AUTOMATED));
    }

    public function test_runs_from_a_previous_month_do_not_count(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5, 'audit_deep_ai_credits' => 0]);

        AuditRequest::factory()->count(5)->create([
            'user_id' => $user->id, 'source' => 'dashboard',
            'tier' => AuditTier::AUTOMATED->value, 'created_at' => now()->subMonth(),
        ]);

        $this->assertSame(5, app(AuditEntitlementService::class)->remainingRuns($user, $tenant, AuditTier::AUTOMATED));
    }

    public function test_a_plan_without_deep_ai_credits_grants_none(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_analyses_per_month' => 5]);

        $this->assertSame(0, app(AuditEntitlementService::class)->remainingRuns($user, $tenant, AuditTier::DEEP_AI));
    }

    /** @return array{0: User, 1: Tenant} */
    private function subscribedTenant(array $metadata): array
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, $metadata);

        return [$user, $tenant];
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }

    private function createActiveSubscriptionFor(Tenant $tenant, User $user, array $productMetadata): Subscription
    {
        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }
}
