<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditSnapshotEntitlementTest extends FeatureTest
{
    public function test_the_snapshot_wins_over_the_live_plan_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_deep_ai_credits' => 2]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => ['audit_deep_ai_credits' => 7],
        ]);

        $this->assertSame(7, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_key_missing_from_the_snapshot_falls_back_to_plan_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_deep_ai_credits' => 4]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => ['audit_diagnostic_credits' => 30],
        ]);

        $this->assertSame(4, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_subscription_with_no_snapshot_behaves_exactly_as_before(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_expert_credits' => 1]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => null,
        ]);

        $this->assertSame(1, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::EXPERT));
    }

    public function test_a_literal_zero_snapshot_value_wins_over_a_nonzero_plan_metadata_value(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 25]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            // A partner override of literal 0 means zero, not "no override" --
            // AuditEntitlementService::planMetadata() must test !== null, not
            // truthiness, to tell the two apart.
            'quota_snapshot' => ['audit_diagnostic_credits' => 0],
        ]);

        $this->assertSame(0, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DIAGNOSTIC));
    }
}
