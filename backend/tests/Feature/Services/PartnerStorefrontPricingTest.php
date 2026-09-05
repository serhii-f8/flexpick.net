<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerStorefrontPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true]);

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

    public function test_the_pricing_page_shows_base_price_to_an_unattributed_customer(): void
    {
        $plan = $this->visiblePlan(basePrice: 4900);
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(4900, app(CurrencyService::class)->getCurrency()->code));
    }
}
