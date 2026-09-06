<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_authenticated_user_can_view_pricing(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->withExceptionHandling();
        $response = $this->get(route('pricing'));

        $response->assertRedirect(route('login'));
    }

    public function test_single_audits_get_their_own_heading_below_the_plans(): void
    {
        $user = $this->createUser();

        $html = $this->actingAs($user)->get(route('pricing'))->assertOk()->getContent();

        $this->assertStringContainsString(__('Or buy a single audit'), $html);
        $this->assertLessThan(
            strpos($html, __('Or buy a single audit')),
            strpos($html, __('Plans & Pricing')),
        );
    }

    public function test_a_partner_customer_sees_one_banner_naming_the_partner(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

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
            'price' => 4900,
        ]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSee(__('Prices on this page are set by :partner.', ['partner' => $partnerTenant->name]));
        $response->assertSee(__('Sold through :partner', ['partner' => $partnerTenant->name]));
    }
}
