<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Events\Order\Ordered;
use App\Events\Order\OrderedOffline;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PaymentProvider;
use App\Services\CalculationService;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PaymentProviders\Offline\OfflineProvider;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTest;

class CashOrderCreationTest extends FeatureTest
{
    private function offlineProvider(): PaymentProvider
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider;
    }

    public function test_a_comped_zero_amount_local_order_still_completes_immediately(): void
    {
        Event::fake([Ordered::class, OrderedOffline::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create($user, $tenant, isLocal: true);

        $this->assertSame(OrderStatus::SUCCESS->value, $order->status);
        Event::assertDispatched(Ordered::class);
        Event::assertNotDispatched(OrderedOffline::class);
    }

    public function test_a_paid_local_order_waits_for_a_human_to_confirm_the_cash(): void
    {
        Event::fake([Ordered::class, OrderedOffline::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 4900,
            isLocal: true,
        );

        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        Event::assertNotDispatched(Ordered::class);
        Event::assertDispatched(OrderedOffline::class);
    }

    public function test_the_discounted_amount_decides_the_gate(): void
    {
        // Faked because this lands on SUCCESS and triggers a real Ordered
        // dispatch; the assertion here is about the status gate, not events.
        Event::fake([Ordered::class, OrderedOffline::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 4900,
            discountTotal: 4900,
            totalAmountAfterDiscount: 0,
            isLocal: true,
        );

        $this->assertSame(OrderStatus::SUCCESS->value, $order->status);
    }

    public function test_it_persists_the_snapshot_attributes_it_is_handed(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 6900,
            isLocal: true,
            snapshot: [
                'partner_tenant_id' => $partnerTenant->id,
                'base_price_snapshot' => 4900,
                'quota_snapshot' => ['audit_diagnostic_credits' => 25],
                'type' => OrderType::RENEWAL->value,
                'not_a_column' => 'ignored',
            ],
        )->fresh();

        $this->assertSame($partnerTenant->id, $order->partner_tenant_id);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 25], $order->quota_snapshot);
        $this->assertSame(OrderType::RENEWAL->value, $order->type);
    }

    /**
     * Drives the real production path -- CheckoutService::initProductCheckout()
     * followed by OfflineProvider::initProductCheckout(), exactly as
     * ProductCheckoutForm::checkout() calls them -- rather than going through
     * OrderService::create() directly like every other test in this file.
     * initProductCheckout() computes isLocalOrder from the totals DTO (a paid
     * product is created isLocal: false), and it is OfflineProvider that
     * later flips is_local to true, which is what fires OrderedOffline via
     * OrderService::handleDispatchingEvents().
     */
    public function test_the_real_product_checkout_path_lands_a_paid_cash_order_pending_and_local(): void
    {
        Event::fake([OrderedOffline::class]);

        $provider = $this->offlineProvider();

        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'metadata' => ['audit_diagnostic_credits' => 10],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);

        $cartItem = new CartItemDto;
        $cartItem->productId = $product->id;
        $cartItem->quantity = 1;

        $cartDto = new CartDto;
        $cartDto->items = [$cartItem];

        $totals = app(CalculationService::class)->calculateCartTotals($cartDto, $user);
        $this->assertSame(4900, $totals->amountDue, 'sanity check: this must be a paid product, not a comped one');

        $order = app(CheckoutService::class)->initProductCheckout($cartDto, null, $totals, shouldCreateNewTenant: true);

        // Before the payment provider runs, the paid product order is a
        // regular gateway-shaped PENDING order (isLocal: false at creation).
        $this->assertSame(OrderStatus::PENDING->value, $order->fresh()->status);
        $this->assertFalse((bool) $order->fresh()->is_local);

        app(OfflineProvider::class)->initProductCheckout($order);

        $order = $order->fresh();

        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertTrue((bool) $order->is_local);
        $this->assertSame($provider->id, $order->payment_provider_id);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 10], $order->quota_snapshot);
        Event::assertDispatched(OrderedOffline::class);
    }
}
