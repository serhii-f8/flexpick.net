<?php

namespace Tests\Feature\Services;

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

        $this->assertSame(0, app(AuditEntitlementService::class)->subscriptionAllowance($tenant));
        $this->assertSame(0, app(AuditEntitlementService::class)->remainingDashboardRuns($user, $tenant));
    }

    public function test_allowance_reads_product_metadata_of_active_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        $this->assertSame(5, app(AuditEntitlementService::class)->subscriptionAllowance($tenant));
    }

    public function test_dashboard_runs_this_month_reduce_remaining(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        AuditRequest::factory()->count(2)->dashboardSource()->create(['user_id' => $user->id]);
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'created_at' => now()->subMonths(2)]);
        AuditRequest::factory()->create(['user_id' => $user->id]); // web source — doesn't count

        $service = app(AuditEntitlementService::class);
        $this->assertSame(2, $service->dashboardRunsUsedThisMonth($user));
        $this->assertSame(3, $service->remainingDashboardRuns($user, $tenant));
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
