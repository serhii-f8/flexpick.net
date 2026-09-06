<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Dto\SubscriptionCheckoutDto;
use App\Livewire\Checkout\ConvertLocalSubscriptionCheckoutForm;
use App\Livewire\Checkout\SubscriptionTotals;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalculationService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

/**
 * The convert-local-subscription flow is collected by a payment gateway, and a
 * gateway bills from CalculationService::getPlanPrice() — the base price
 * (Decision 2). It therefore must not *quote* a partner price either, or the
 * customer reads $79 on the page and is charged $49 by the gateway.
 */
class PartnerGatewayFlowsAreBasePricedTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Arranged explicitly rather than through a shared helper: FeatureTest
        // does not reset the database between test classes.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        PaymentProvider::where('slug', PaymentProviderConstants::STRIPE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

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

    private function partnerPricedPlan(Tenant $partnerTenant, int $basePrice = 4900, int $partnerPrice = 7900): Plan
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
            'has_trial' => false,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
            'setup_fee' => 0,
        ]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => $partnerPrice,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        app(PartnerPricingResolver::class)->flush();

        return $plan;
    }

    private function attributedUser(Tenant $partnerTenant, Tenant $customerTenant): User
    {
        return $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_the_convert_local_subscription_form_shows_the_base_price_to_an_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->partnerPricedPlan($partnerTenant);
        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);

        // Arrangement guard: the offering really is usable for this buyer, so a
        // base price below can only come from the deliberate opt-out.
        $this->assertSame(
            7900,
            app(CalculationService::class)->calculatePlanTotals($user, $plan->slug)->subtotal,
            'Arrangement failure: expected the partner offering to be usable for this buyer.',
        );

        $sessionDto = new SubscriptionCheckoutDto;
        $sessionDto->planSlug = $plan->slug;

        $this->actingAs($user);
        $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $sessionDto]);

        $component = Livewire::test(ConvertLocalSubscriptionCheckoutForm::class);

        $this->assertSame(4900, $component->viewData('totals')->subtotal);
        $this->assertSame(4900, $component->viewData('totals')->amountDue);
    }

    public function test_the_convert_local_subscription_page_shows_the_base_price_to_an_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->partnerPricedPlan($partnerTenant);
        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $customerTenant->id,
            'plan_id' => $plan->id,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(10),
        ]);

        $this->actingAs($user);

        $response = $this->get(route('checkout.convert-local-subscription', $subscription->uuid));

        $response->assertOk();
        $this->assertSame(4900, $response->viewData('totals')->subtotal);
    }

    /**
     * The base-price opt-out decides which price the page quotes, so it must
     * not be reachable from the browser. Livewire refuses a client update to a
     * #[Locked] property; without the attribute this set() would succeed and
     * the next recompute would quote the partner price against a gateway that
     * charges base.
     */
    public function test_the_partner_pricing_opt_out_cannot_be_flipped_from_the_browser(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->partnerPricedPlan($partnerTenant);
        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);

        $sessionDto = new SubscriptionCheckoutDto;
        $sessionDto->planSlug = $plan->slug;

        $this->actingAs($user);
        $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $sessionDto]);

        $totals = Livewire::test(SubscriptionTotals::class, [
            'totals' => app(CalculationService::class)->calculatePlanTotals($user, $plan->slug, allowPartnerPricing: false),
            'plan' => $plan,
            'page' => 'http://localhost/checkout/convert-local-subscription',
            'canAddDiscount' => true,
            'isTrailSkipped' => false,
            'allowPartnerPricing' => false,
        ]);

        $this->assertFalse($totals->get('allowPartnerPricing'), 'Arrangement failure: expected the component to mount opted out.');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $totals->set('allowPartnerPricing', true);
    }
}
