<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Dto\SubscriptionCheckoutDto;
use App\Livewire\Checkout\ProductCheckoutForm;
use App\Livewire\Checkout\SubscriptionCheckoutForm;
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
use App\Services\PaymentProviders\PaymentService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerPaymentProviderRestrictionTest extends FeatureTest
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
        PaymentProvider::where('slug', PaymentProviderConstants::STRIPE_SLUG)
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

    private function flatRatePlan(int $basePrice = 4900): Plan
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
            'setup_fee' => 0,
        ]);

        return $plan;
    }

    private function visibleProduct(int $basePrice = 4900): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'max_quantity' => 1,
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

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    /**
     * @param  array<int, object>  $providers
     * @return array<int, string>
     */
    private function slugs(array $providers): array
    {
        return array_map(fn ($provider): string => $provider->getSlug(), $providers);
    }

    public function test_a_partner_priced_plan_offers_only_the_offline_provider(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertGreaterThan(1, count($all), 'Arrangement failure: expected more than one active provider.');
        $this->assertSame([PaymentProviderConstants::OFFLINE_SLUG], $this->slugs($filtered));
    }

    public function test_an_unconfigured_plan_keeps_the_full_provider_list(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan();
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertSame($this->slugs($all), $this->slugs($filtered));
    }

    public function test_an_unattributed_buyer_keeps_the_full_provider_list(): void
    {
        $plan = $this->flatRatePlan();
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertSame($this->slugs($all), $this->slugs($filtered));
    }

    /**
     * The two static-helper tests above prove restrictToPartnerProviders()
     * filters correctly in isolation, but not that SubscriptionCheckoutForm
     * actually wires the right plan and buyer into it. Drive the real
     * component end to end so a mistake in that wiring (e.g. passing the
     * wrong plan, or calling usableProductOffering() instead) would fail.
     */
    public function test_the_subscription_checkout_component_offers_only_the_offline_provider_for_a_partner_priced_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $sessionDto = new SubscriptionCheckoutDto;
        $sessionDto->planSlug = $plan->slug;

        $this->actingAs($user);
        $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $sessionDto]);

        $component = Livewire::test(SubscriptionCheckoutForm::class);

        $this->assertSame(
            [PaymentProviderConstants::OFFLINE_SLUG],
            $this->slugs($component->viewData('paymentProviders')),
        );
    }

    /** Same rationale as the subscription-side component test above, for ProductCheckoutForm. */
    public function test_the_product_checkout_component_offers_only_the_offline_provider_for_a_partner_priced_product(): void
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

        $cartItem = new CartItemDto;
        $cartItem->productId = $product->id;
        $cartDto = new CartDto;
        $cartDto->items = [$cartItem];

        $this->actingAs($user);
        $this->withSession([SessionConstants::CART_DTO => $cartDto]);

        $component = Livewire::test(ProductCheckoutForm::class);

        $this->assertSame(
            [PaymentProviderConstants::OFFLINE_SLUG],
            $this->slugs($component->viewData('paymentProviders')),
        );
    }
}
