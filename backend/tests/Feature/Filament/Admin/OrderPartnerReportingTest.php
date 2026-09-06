<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderApproval;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class OrderPartnerReportingTest extends FeatureTest
{
    private function partnerSoldOrder(): array
    {
        $partnerTenant = $this->createTenant();
        $customerTenant = $this->createTenant();

        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now()->subDays(3),
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

        OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::PARTNER->value,
            'actor_user_id' => $customer->id,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => 'Cash received in person.',
            'decided_at' => now()->subDay(),
        ]);

        return [$order, $partnerTenant, $customer];
    }

    public function test_the_admin_view_page_shows_partner_identity_base_price_and_margin(): void
    {
        [$order, $partnerTenant] = $this->partnerSoldOrder();
        $this->actingAs($this->createAdminUser());

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee($partnerTenant->name)
            ->assertSee(money(4900, $order->currency->code))   // base price
            ->assertSee(money(3000, $order->currency->code));  // margin: 7900 - 4900
    }

    public function test_the_admin_view_page_shows_the_approval_and_attribution_history(): void
    {
        [$order] = $this->partnerSoldOrder();
        $this->actingAs($this->createAdminUser());

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee('Cash received in person.')
            // A bare assertSee('link') would be satisfiable by ambient markup
            // (e.g. a stray <link> tag or a "Link" label) without our field
            // ever rendering. Pair the label with its value, in DOM order, so
            // this can only pass because the Attribution Source entry itself
            // rendered "link".
            ->assertSeeHtmlInOrder(['Attribution Source', 'link']);
    }

    public function test_a_direct_order_renders_without_partner_fields(): void
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => null,
            'status' => OrderStatus::SUCCESS->value,
            'total_amount' => 4900,
            'total_amount_after_discount' => 4900,
            'base_price_snapshot' => 4900,
        ]);

        $this->actingAs($this->createAdminUser());

        // The page must not blow up on a null partner — this is the majority case.
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful();
    }
}
