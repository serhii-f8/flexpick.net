<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Dto\TotalsDto;
use App\Exceptions\PurchaseNotAllowedException;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CheckoutService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class CheckoutServicePurchasabilityTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    public function test_an_attributed_buyer_cannot_check_out_a_not_configured_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $this->expectException(PurchaseNotAllowedException::class);

        app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);
    }

    public function test_an_attributed_buyer_can_check_out_a_configured_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);
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
        ]);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);

        $this->assertNotNull($subscription);
    }

    public function test_a_direct_buyer_can_still_check_out_any_active_plan(): void
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $this->actingAs($this->createUser());

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);

        $this->assertNotNull($subscription);
    }

    public function test_an_attributed_buyer_cannot_check_out_a_not_configured_product(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $cartItem = new CartItemDto;
        $cartItem->productId = (string) $product->id;
        $cartItem->quantity = 1;

        $cartDto = new CartDto;
        $cartDto->items = [$cartItem];

        $totals = new TotalsDto;
        $totals->amountDue = 4900;
        $totals->currencyCode = 'USD';

        $this->expectException(PurchaseNotAllowedException::class);

        app(CheckoutService::class)->initProductCheckout($cartDto, null, $totals, true);
    }

    /**
     * Regression for Finding 1 of the final whole-branch review: a product
     * seeded not-visible (e.g. audit-report-unlock) can never have a
     * PartnerProductOffering configured for it — PartnerProductPricingTable's
     * table query only lists is_visible ones — so gating it the same way as
     * a browsable product would permanently strand every attributed buyer on
     * a purely transactional SKU. An attributed buyer with no offering at all
     * must still be able to complete checkout for it.
     */
    public function test_an_attributed_buyer_can_check_out_a_not_visible_product_with_no_offering(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => false]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $cartItem = new CartItemDto;
        $cartItem->productId = (string) $product->id;
        $cartItem->quantity = 1;

        $cartDto = new CartDto;
        $cartDto->items = [$cartItem];

        $totals = new TotalsDto;
        $totals->amountDue = 4900;
        $totals->currencyCode = 'USD';

        $order = app(CheckoutService::class)->initProductCheckout($cartDto, null, $totals, true);

        $this->assertNotNull($order);
    }
}
