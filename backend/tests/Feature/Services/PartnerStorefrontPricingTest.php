<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\ReferralConstants;
use App\Constants\SubscriptionStatus;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use App\Services\ReferralService;
use Tests\Feature\FeatureTest;

/**
 * Whole-suite coupling warning: several assertions here read the storefront's
 * full plan/product collections, which include every row any earlier test class
 * left behind — Tests\Feature\FeatureTest re-seeds once per suite run, not per
 * class. Assertions must therefore be bound to data this class created (by id
 * or by a unique slug), never to a collection count or to "the first plan".
 * Anything phrased against the whole collection can pass for the wrong reason.
 */
class PartnerStorefrontPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Both columns: PartnerPricingResolver gates on exactly the pair
        // checkout itself filters on (is_active AND is_enabled_for_new_payments).
        // Set explicitly here rather than relying on the seeded default —
        // FeatureTest does not reset the database between test classes.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function visiblePlan(int $basePrice = 4900): Plan
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        return $plan;
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_a_configured_plan_is_decorated_with_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decoratePlans(collect([$plan]), $user)
            ->first();

        $this->assertSame(7900, $decorated->partner_price);
        $this->assertSame($partnerTenant->name, $decorated->partner_tenant_name);
    }

    public function test_an_unconfigured_plan_is_left_at_base_price(): void
    {
        // Spec §8.2 as amended: an item the partner never configured stays in
        // the catalog at base price. It must NOT be hidden or nulled out.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decoratePlans(collect([$plan]), $user)
            ->first();

        $this->assertNull($decorated->partner_price);
        $this->assertNull($decorated->partner_tenant_name);
        $this->assertTrue($decorated->is($plan), 'The plan must still be present in the collection.');
    }

    public function test_the_pricing_page_renders_the_partner_price_for_an_attributed_customer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(7900, app(CurrencyService::class)->getCurrency()->code));
    }

    public function test_the_pricing_page_renders_the_partner_price_on_the_first_referral_link_visit(): void
    {
        // A guest arriving through a partner link has no fp_rc cookie yet --
        // it is queued on this very response. The partner price must still
        // render now, not only after a refresh.
        config(['app.referral.enabled' => true]);
        $partnerTenant = $this->activePartnerTenant();
        $member = $this->createUser($partnerTenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;
        $plan = $this->visiblePlan(basePrice: 4902);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7902,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->get(route('pricing', [ReferralConstants::HTTP_PARAM_REFERRAL_CODE => $code]));

        $response->assertOk();
        $response->assertSee(money(7902, app(CurrencyService::class)->getCurrency()->code));
        $response->assertDontSee(money(4902, app(CurrencyService::class)->getCurrency()->code));
    }

    public function test_the_pricing_page_is_closed_to_an_unattributed_customer(): void
    {
        // Base prices are never quoted to anyone: a customer nobody referred
        // is turned away before a single card renders (RequirePartnerAttribution).
        $this->withExceptionHandling();
        $this->visiblePlan(basePrice: 4901);
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertForbidden();
        $response->assertDontSee(money(4901, app(CurrencyService::class)->getCurrency()->code));
        $response->assertDontSee(money(7900, app(CurrencyService::class)->getCurrency()->code));
    }

    private function visibleProduct(int $basePrice = 4900): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        return $product;
    }

    public function test_a_configured_product_is_decorated_with_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct();
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decorateProducts(collect([$product]), $user)
            ->first();

        $this->assertSame(7900, $decorated->partner_price);
        $this->assertSame($partnerTenant->name, $decorated->partner_tenant_name);
    }

    public function test_an_unconfigured_product_stays_in_the_catalog_at_base_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decorateProducts(collect([$product]), $user)
            ->first();

        $this->assertNull($decorated->partner_price);
        $this->assertTrue($decorated->is($product));
    }

    public function test_the_pricing_page_renders_the_partner_product_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct();
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 8900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(8900, app(CurrencyService::class)->getCurrency()->code));
    }
}
