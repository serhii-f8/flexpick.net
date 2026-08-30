<?php

namespace Tests\Feature\Services;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Services\PartnerCatalogService;
use Tests\Feature\FeatureTest;

class PartnerCatalogServiceTest extends FeatureTest
{
    private function planWithBasePrice(int $basePrice, array $metadata = [], array $resellerQuotaKeys = []): Plan
    {
        $product = Product::factory()->create([
            'reseller_quota_keys' => $resellerQuotaKeys,
            'metadata' => $metadata,
        ]);
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
        $plan = $this->planWithBasePrice(4900, ['audit_diagnostic_credits' => 10], ['audit_diagnostic_credits']);

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
        $plan = $this->planWithBasePrice(4900, [], []);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 100], true);
    }

    public function test_set_plan_offering_rejects_a_quota_value_below_the_base_value(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['audit_diagnostic_credits' => 10], ['audit_diagnostic_credits']);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 5], true);
    }

    public function test_set_plan_offering_ignores_a_blank_quota_field_instead_of_rejecting_the_save(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['audit_diagnostic_credits' => 10], ['audit_diagnostic_credits']);

        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => null], true);

        $this->assertSame([], $offering->quota_overrides);
    }

    public function test_set_plan_offering_casts_string_quota_values_to_int(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['audit_diagnostic_credits' => 10], ['audit_diagnostic_credits']);

        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => '15'], true);

        $this->assertSame(['audit_diagnostic_credits' => 15], $offering->quota_overrides);
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

    public function test_set_plan_offering_reads_the_quota_allowlist_from_the_dedicated_column_not_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create([
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
            'metadata' => ['audit_diagnostic_credits' => 10],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan->fresh(), 4900, ['audit_diagnostic_credits' => 15], true);

        $this->assertSame(['audit_diagnostic_credits' => 15], $offering->quota_overrides);
    }

    private function oneTimeProductWithBasePrice(int $basePrice, array $metadata = [], array $resellerQuotaKeys = []): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'reseller_quota_keys' => $resellerQuotaKeys,
            'metadata' => $metadata,
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return $product->fresh();
    }

    public function test_product_base_price_reads_the_default_currency_price(): void
    {
        $product = $this->oneTimeProductWithBasePrice(1500);

        $this->assertSame(1500, app(PartnerCatalogService::class)->productBasePrice($product));
    }

    public function test_set_product_offering_succeeds_at_or_above_the_floor(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);

        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 2000, [], true);

        $this->assertInstanceOf(PartnerProductOffering::class, $offering);
        $this->assertSame(2000, $offering->price);
    }

    public function test_set_product_offering_rejects_a_price_below_the_base_price(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 500, [], true);
    }

    public function test_set_product_offering_ignores_a_blank_quota_field_instead_of_rejecting_the_save(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500, ['bonus_credits' => 10], ['bonus_credits']);

        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, ['bonus_credits' => null], true);

        $this->assertSame([], $offering->quota_overrides);
    }

    public function test_set_product_offering_casts_string_quota_values_to_int(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500, ['bonus_credits' => 10], ['bonus_credits']);

        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, ['bonus_credits' => '15'], true);

        $this->assertSame(['bonus_credits' => 15], $offering->quota_overrides);
    }

    public function test_set_product_offering_updates_an_existing_offering_instead_of_duplicating(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);
        $service = app(PartnerCatalogService::class);

        $first = $service->setProductOffering($tenant, $product, 1500, [], false);
        $second = $service->setProductOffering($tenant, $product, 2500, [], true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2500, $second->fresh()->price);
    }

    public function test_set_product_offering_reads_the_quota_allowlist_from_the_dedicated_column_not_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500, ['bonus_credits' => 10], ['bonus_credits']);

        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, ['bonus_credits' => 15], true);

        $this->assertSame(['bonus_credits' => 15], $offering->quota_overrides);
    }

    public function test_set_product_offering_rejects_a_quota_key_not_on_the_allowlist(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500, [], []);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, ['bonus_credits' => 100], true);
    }

    public function test_set_product_offering_rejects_a_quota_value_below_the_base_value(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500, ['bonus_credits' => 10], ['bonus_credits']);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, ['bonus_credits' => 5], true);
    }

    public function test_plan_offering_is_flagged_below_minimum_after_a_base_price_increase(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, [], true);

        PlanPrice::where('plan_id', $plan->id)->update(['price' => 6900]);

        $this->assertTrue(app(PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
    }

    public function test_plan_offering_is_not_flagged_when_still_at_or_above_minimum(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 9900, [], true);

        $this->assertFalse(app(PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
    }

    public function test_product_offering_is_flagged_below_minimum_after_a_base_price_increase(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);
        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, [], true);

        OneTimeProductPrice::where('one_time_product_id', $product->id)->update(['price' => 3000]);

        $this->assertTrue(app(PartnerCatalogService::class)->isProductOfferingBelowMinimum($offering->fresh()));
    }
}
