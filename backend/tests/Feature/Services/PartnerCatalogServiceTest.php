<?php

namespace Tests\Feature\Services;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Services\PartnerCatalogService;
use Tests\Feature\FeatureTest;

class PartnerCatalogServiceTest extends FeatureTest
{
    private function planWithBasePrice(int $basePrice, array $metadata = []): Plan
    {
        $product = Product::factory()->create(['metadata' => $metadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return $plan->fresh();
    }

    public function test_plan_base_price_reads_the_default_currency_price(): void
    {
        $plan = $this->planWithBasePrice(4900);

        $this->assertSame(4900, app(PartnerCatalogService::class)->planBasePrice($plan));
    }

    public function test_set_plan_offering_succeeds_at_or_above_the_floor(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => ['audit_diagnostic_credits'], 'audit_diagnostic_credits' => 10]);

        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 5900, ['audit_diagnostic_credits' => 15], true);

        $this->assertSame(5900, $offering->price);
        $this->assertSame(['audit_diagnostic_credits' => 15], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_set_plan_offering_rejects_a_price_below_the_base_price(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 100, [], true);
    }

    public function test_set_plan_offering_rejects_a_quota_key_not_on_the_allowlist(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => []]);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 100], true);
    }

    public function test_set_plan_offering_rejects_a_quota_value_below_the_base_value(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => ['audit_diagnostic_credits'], 'audit_diagnostic_credits' => 10]);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 5], true);
    }

    public function test_set_plan_offering_updates_an_existing_offering_instead_of_duplicating(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $service = app(PartnerCatalogService::class);

        $first = $service->setPlanOffering($tenant, $plan, 4900, [], false);
        $second = $service->setPlanOffering($tenant, $plan, 5900, [], true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5900, $second->fresh()->price);
    }
}
