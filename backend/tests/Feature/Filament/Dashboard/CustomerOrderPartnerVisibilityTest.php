<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class CustomerOrderPartnerVisibilityTest extends FeatureTest
{
    private function partnerSoldOrderForCustomer(): array
    {
        $partnerTenant = $this->createTenant();
        $partnerTenant->update(['name' => 'Acme Consulting '.uniqid()]);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        ], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'link',
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => true,
            'total_amount' => 7900,
            'total_amount_after_discount' => 7900,
            'base_price_snapshot' => 4900,
        ]);

        return [$order, $partnerTenant, $customer, $customerTenant];
    }

    public function test_the_customer_sees_what_they_paid_and_who_sold_it(): void
    {
        [$order, $partnerTenant, $customer, $customerTenant] = $this->partnerSoldOrderForCustomer();
        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee($partnerTenant->name)
            ->assertSee(money(7900, $order->currency->code));
    }

    public function test_the_customer_never_sees_the_base_price_or_the_margin(): void
    {
        // Spec §10, as amended 2026-09-05: margin is partner/admin only.
        [$order, , $customer, $customerTenant] = $this->partnerSoldOrderForCustomer();
        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertDontSee(money(4900, $order->currency->code))   // base_price_snapshot
            ->assertDontSee(money(3000, $order->currency->code))   // margin
            ->assertDontSee('Base Price')
            ->assertDontSee('Margin');
    }

    public function test_a_direct_order_shows_no_partner_line(): void
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => null,
            'status' => OrderStatus::SUCCESS->value,
            'total_amount' => 4900,
            'total_amount_after_discount' => 4900,
        ]);

        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertDontSee('Sold Through');
    }
}
