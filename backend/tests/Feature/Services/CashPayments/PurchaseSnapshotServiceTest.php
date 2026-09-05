<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\SubscriptionStatus;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashPayments\PurchaseSnapshotService;
use Tests\Feature\FeatureTest;

class PurchaseSnapshotServiceTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $partnerPlan = Plan::factory()->create(['product_id' => $partnerProduct->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $partnerPlan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** @return array{0: Plan, 1: Product} */
    private function sellablePlan(int $basePrice = 4900, array $metadata = ['audit_diagnostic_credits' => 10]): array
    {
        $product = Product::factory()->create([
            'reseller_quota_keys' => array_keys($metadata),
            'metadata' => $metadata,
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_visible' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return [$plan, $product];
    }

    public function test_an_unattributed_buyer_freezes_base_price_and_base_metadata(): void
    {
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create();

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(4900, $snapshot['base_price_snapshot']);
        $this->assertSame(['audit_diagnostic_credits' => 10], $snapshot['quota_snapshot']);
    }

    public function test_an_attributed_buyer_gets_the_partners_quota_overrides_merged_over_base(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan(4900, ['audit_diagnostic_credits' => 10, 'audit_deep_ai_credits' => 2]);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => ['audit_diagnostic_credits' => 25],
            'is_enabled' => true,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertSame($partnerTenant->id, $snapshot['partner_tenant_id']);
        $this->assertSame(4900, $snapshot['base_price_snapshot'], 'base_price_snapshot is the platform base, never the partner price');
        $this->assertSame(
            ['audit_diagnostic_credits' => 25, 'audit_deep_ai_credits' => 2],
            $snapshot['quota_snapshot'],
        );
    }

    public function test_a_disabled_offering_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => ['audit_diagnostic_credits' => 25],
            'is_enabled' => false,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(['audit_diagnostic_credits' => 10], $snapshot['quota_snapshot']);
    }

    public function test_an_offering_that_fell_below_the_admin_minimum_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $product] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'quota_overrides' => ['audit_diagnostic_credits' => 12],
            'is_enabled' => true,
        ]);

        // The admin raises the base quota above what the partner promised.
        $product->update(['metadata' => ['audit_diagnostic_credits' => 20]]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(['audit_diagnostic_credits' => 20], $snapshot['quota_snapshot']);
    }

    public function test_a_lapsed_partner_plan_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->createTenant(); // no partner-plan subscription at all
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $this->assertNull(app(PurchaseSnapshotService::class)->partnerTenantFor($user));
        $this->assertNull(app(PurchaseSnapshotService::class)->forPlan($user, $plan)['partner_tenant_id']);
    }

    public function test_it_snapshots_one_time_products_too(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create([
            'reseller_quota_keys' => ['audit_expert_credits'],
            'metadata' => ['audit_expert_credits' => 1],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 9900,
        ]);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 12900,
            'quota_overrides' => ['audit_expert_credits' => 3],
            'is_enabled' => true,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forProduct($user, $product);

        $this->assertSame($partnerTenant->id, $snapshot['partner_tenant_id']);
        $this->assertSame(9900, $snapshot['base_price_snapshot']);
        $this->assertSame(['audit_expert_credits' => 3], $snapshot['quota_snapshot']);
    }

    public function test_an_item_with_no_price_in_the_default_currency_snapshots_a_null_base_price(): void
    {
        $product = Product::factory()->create(['metadata' => []]);
        $plan = Plan::factory()->create(['product_id' => $product->id]); // no PlanPrice row
        $user = User::factory()->create();

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['base_price_snapshot']);
        $this->assertSame([], $snapshot['quota_snapshot']);
    }
}
