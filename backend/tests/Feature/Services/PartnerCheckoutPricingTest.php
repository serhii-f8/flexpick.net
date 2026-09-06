<?php

namespace Tests\Feature\Services;

use App\Constants\DiscountConstants;
use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Models\Discount;
use App\Models\DiscountCode;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\Order;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalculationService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerCheckoutPricingTest extends FeatureTest
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

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_an_attributed_buyer_is_charged_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(7900, $totals->subtotal);
        $this->assertSame(7900, $totals->amountDue);
    }

    public function test_an_unattributed_buyer_is_charged_the_base_price(): void
    {
        $plan = $this->flatRatePlan(basePrice: 4900);
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(4900, $totals->subtotal);
    }

    public function test_an_unconfigured_plan_is_charged_at_base_price_for_an_attributed_buyer(): void
    {
        // Spec §8.2 as amended.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(11900, $totals->subtotal);
    }

    public function test_get_plan_price_still_returns_the_base_price_for_gateway_sync(): void
    {
        // Decision 2: five gateway providers depend on this returning base.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $this->actingAs($this->attributedUser($partnerTenant));
        app(PartnerPricingResolver::class)->flush();

        $planPrice = app(CalculationService::class)->getPlanPrice($plan);

        $this->assertSame(4900, (int) $planPrice->price);
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

    public function test_cart_totals_use_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 4900);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $cart = new CartDto;
        $item = new CartItemDto;
        $item->productId = $product->id;
        $item->quantity = 1;
        $cart->items = [$item];

        $totals = app(CalculationService::class)->calculateCartTotals($cart, $user);

        $this->assertSame(7900, $totals->subtotal);
        $this->assertSame(7900, $totals->amountDue);
    }

    public function test_cart_totals_fall_back_to_base_for_an_unconfigured_product(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $cart = new CartDto;
        $item = new CartItemDto;
        $item->productId = $product->id;
        $item->quantity = 1;
        $cart->items = [$item];

        $totals = app(CalculationService::class)->calculateCartTotals($cart, $user);

        $this->assertSame(11900, $totals->subtotal);
    }

    /**
     * calculateCartTotals() computes the discount against the partner price
     * rather than the base price. That is deliberate — a discount has to apply
     * to what is actually charged — and it is the one semantic change this
     * branch made to money arithmetic, so it gets its own test.
     */
    public function test_a_discount_code_applies_against_the_partner_price_not_the_base_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 4900);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $discount = Discount::create([
            'name' => 'Partner pricing discount '.uniqid(),
            'type' => DiscountConstants::TYPE_PERCENTAGE,
            'amount' => 10,
            'is_active' => true,
            'max_redemptions' => -1,
            'max_redemptions_per_user' => -1,
            'is_enabled_for_all_one_time_products' => true,
            'is_enabled_for_all_plans' => true,
        ]);
        $code = 'PARTNER10-'.uniqid();
        DiscountCode::create([
            'discount_id' => $discount->id,
            'code' => $code,
        ]);

        $cart = new CartDto;
        $item = new CartItemDto;
        $item->productId = $product->id;
        $item->quantity = 1;
        $cart->items = [$item];
        $cart->discountCode = $code;

        $totals = app(CalculationService::class)->calculateCartTotals($cart, $user);

        // 10% of the partner price (790), not 10% of the base price (490).
        $this->assertSame(7900, $totals->subtotal);
        $this->assertSame(790, $totals->discountAmount);
        $this->assertSame(7110, $totals->amountDue);
    }

    public function test_order_totals_write_the_partner_price_onto_the_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 4900);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        $customerTenant = $this->createTenant();
        $customerTenant->users()->attach($user);
        app(PartnerPricingResolver::class)->flush();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $customerTenant->id,
            'status' => OrderStatus::NEW->value,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'price_per_unit' => 0,
        ]);
        $order->refresh();

        app(CalculationService::class)->calculateOrderTotals($order, $user);

        $this->assertSame(7900, (int) $order->fresh()->total_amount);
        $this->assertSame(7900, (int) $order->items()->first()->price_per_unit);
    }
}
