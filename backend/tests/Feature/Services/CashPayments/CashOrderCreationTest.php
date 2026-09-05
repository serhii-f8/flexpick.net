<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Events\Order\Ordered;
use App\Events\Order\OrderedOffline;
use App\Models\PaymentProvider;
use App\Services\OrderService;
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
}
