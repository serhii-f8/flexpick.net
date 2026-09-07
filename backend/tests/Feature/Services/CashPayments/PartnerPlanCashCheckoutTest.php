<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Models\Order;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use App\Services\PaymentProviders\PaymentService;
use Tests\Feature\FeatureTest;

/**
 * The one number the customer actually owes on a partner-priced plan sale.
 *
 * Every other partner-pricing test on this branch asserts on a TotalsDto,
 * which is display only. This drives the real chain — CheckoutService ->
 * OfflineProvider -> CashSubscriptionService — and asserts the persisted
 * subscription price and the resulting pending order's total.
 */
class PartnerPlanCashCheckoutTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set explicitly here rather than in a shared helper: FeatureTest does
        // not reset the database between test classes, so a global row like
        // this one must be arranged by every test that depends on it.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
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
            'has_trial' => false,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
            'setup_fee' => 0,
        ]);

        return $plan;
    }

    private function offering(Tenant $partnerTenant, Plan $plan, int $price = 7900): PartnerPlanOffering
    {
        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => $price,
            'quota_overrides' => ['audit_diagnostic_credits' => 3],
            'is_enabled' => true,
        ]);

        app(PartnerPricingResolver::class)->flush();

        return $offering;
    }

    private function attributedUser(Tenant $partnerTenant, Tenant $customerTenant): User
    {
        return $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    private function pendingOrderFor(Subscription $subscription): ?Order
    {
        return Order::where('subscription_id', $subscription->id)->first();
    }

    public function test_a_partner_attributed_cash_checkout_bills_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        $this->offering($partnerTenant, $plan, price: 7900);

        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame(7900, (int) $subscription->fresh()->price);

        app(PaymentService::class)
            ->getPaymentProviderBySlug(PaymentProviderConstants::OFFLINE_SLUG)
            ->initSubscriptionCheckout($plan, $subscription);

        $order = $this->pendingOrderFor($subscription);

        $this->assertNotNull($order, 'Expected the offline checkout to open a pending cash order.');
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(7900, (int) $order->total_amount);
        $this->assertSame($partnerTenant->id, $order->partner_tenant_id);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
    }

    public function test_an_unattributed_cash_checkout_still_bills_the_base_price(): void
    {
        $plan = $this->flatRatePlan(basePrice: 5900);

        $customerTenant = $this->createTenant();
        $user = $this->createUser($customerTenant);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame(5900, (int) $subscription->fresh()->price);

        app(PaymentService::class)
            ->getPaymentProviderBySlug(PaymentProviderConstants::OFFLINE_SLUG)
            ->initSubscriptionCheckout($plan, $subscription);

        $order = $this->pendingOrderFor($subscription);

        $this->assertNotNull($order);
        $this->assertSame(5900, (int) $order->total_amount);
        $this->assertNull($order->partner_tenant_id);
    }

    /**
     * CheckoutService reuses an existing NEW subscription rather than always
     * creating one, so a subscription created before the buyer was attributed
     * must have its price re-derived — not carried forward.
     */
    public function test_a_reused_subscription_created_before_attribution_is_repriced(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);

        $customerTenant = $this->createTenant();
        $user = $this->createUser($customerTenant);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame(4900, (int) $subscription->fresh()->price);
        $this->assertNull($subscription->fresh()->partner_tenant_id);

        // The buyer is attributed afterwards (a partner referral link followed
        // by a login), and the partner then configures the offering.
        $user->update([
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'login',
        ]);
        $this->offering($partnerTenant, $plan, price: 7900);

        $reused = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame($subscription->id, $reused->id, 'Arrangement failure: expected the NEW subscription to be reused.');
        $this->assertSame(7900, (int) $reused->fresh()->price);
        $this->assertSame($partnerTenant->id, $reused->fresh()->partner_tenant_id);

        app(PaymentService::class)
            ->getPaymentProviderBySlug(PaymentProviderConstants::OFFLINE_SLUG)
            ->initSubscriptionCheckout($plan, $reused);

        $order = $this->pendingOrderFor($reused);

        $this->assertNotNull($order);
        $this->assertSame(7900, (int) $order->total_amount);
        $this->assertSame($partnerTenant->id, $order->partner_tenant_id);
    }

    /**
     * The mirror image: an offering the partner has since disabled must not
     * leave a stale partner price frozen on a reused subscription.
     */
    public function test_a_reused_subscription_falls_back_to_base_when_the_offering_is_withdrawn(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        $offering = $this->offering($partnerTenant, $plan, price: 7900);

        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame(7900, (int) $subscription->fresh()->price);

        $offering->update(['is_enabled' => false]);
        app(PartnerPricingResolver::class)->flush();

        $reused = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame($subscription->id, $reused->id);
        $this->assertSame(4900, (int) $reused->fresh()->price);
        // Base-priced, but still the partner's customer (spec §8.2).
        $this->assertSame($partnerTenant->id, $reused->fresh()->partner_tenant_id);
    }

    /**
     * The local (free-trial) flow reuses a NEW subscription through the same
     * findNewByPlanSlugAndTenant() lookup as the paid flow, so it can adopt a
     * row an abandoned paid checkout already froze at the partner price.
     *
     * A local subscription is converted through a gateway, and by Decision 2 a
     * gateway may never see a partner price — so the reused row must be reset
     * to base, exactly as create(localSubscription: true) would have built it.
     */
    public function test_the_local_flow_reprices_a_reused_partner_priced_subscription_to_base(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        $this->offering($partnerTenant, $plan, price: 7900);

        $customerTenant = $this->createTenant();
        $user = $this->attributedUser($partnerTenant, $customerTenant);
        $this->actingAs($user);

        // An abandoned paid checkout freezes the partner price on a NEW row.
        $paid = app(CheckoutService::class)->initSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );
        $this->assertSame(7900, (int) $paid->fresh()->price, 'Arrangement failure: expected the paid flow to freeze the partner price.');

        // The same buyer then starts the free-trial flow for the same plan.
        $reused = app(CheckoutService::class)->initLocalSubscriptionCheckout(
            $plan->slug,
            $customerTenant->uuid,
        );

        $this->assertSame($paid->id, $reused->id, 'Arrangement failure: expected the NEW subscription to be reused.');
        $this->assertSame(4900, (int) $reused->fresh()->price);
        // The snapshot still records who sold it — only the price is base.
        $this->assertSame($partnerTenant->id, $reused->fresh()->partner_tenant_id);
    }
}
