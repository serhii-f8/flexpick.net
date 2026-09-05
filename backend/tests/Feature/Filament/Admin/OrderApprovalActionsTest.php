<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderApproval;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class OrderApprovalActionsTest extends FeatureTest
{
    private function pendingCashOrder(): Order
    {
        $tenant = $this->createTenant();
        $customer = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
        ]);
    }

    public function test_an_admin_can_approve_a_direct_cash_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('approve_cash_payment', ['note' => 'Bank transfer cleared.']);

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame(
            OrderApprovalActor::ADMIN->value,
            OrderApproval::where('order_id', $order->id)->value('actor_type'),
        );
    }

    public function test_an_admin_can_reject_a_direct_cash_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('reject_cash_payment', ['note' => 'Never paid.']);

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }

    public function test_the_free_form_status_action_is_hidden_for_pending_cash_orders(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('update_order')
            ->assertActionVisible('approve_cash_payment');
    }

    public function test_the_cash_actions_are_hidden_for_a_completed_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();
        $order->update(['status' => OrderStatus::SUCCESS->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->fresh()->getRouteKey()])
            ->assertActionHidden('approve_cash_payment')
            ->assertActionHidden('reject_cash_payment');
    }
}
