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

    public function test_the_admin_view_page_shows_a_pending_partner_order_with_no_approval_row_yet(): void
    {
        $partnerTenant = $this->createTenant();
        $customerTenant = $this->createTenant();

        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
        ]);

        // No OrderApproval row is created — this is the realistic state for
        // a partner cash order still awaiting an approve/reject decision.
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6600,
            'total_amount_after_discount' => 6600,
            'base_price_snapshot' => 4400,
        ]);

        $this->actingAs($this->createAdminUser());

        // The page must not error on the null approval relation. The Partner
        // Sale section is gated on partner_tenant_id alone, so it must still
        // render with real pricing; the Approval & Attribution History
        // section is gated on (approval !== null || partner_tenant_id !==
        // null), so it must still render too, but with its approval-specific
        // fields as placeholders rather than the approval note/actor that a
        // decided order would show.
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee('Partner Sale')
            ->assertSee(money(4400, $order->currency->code))   // base price
            ->assertSee(money(2200, $order->currency->code))   // margin: 6600 - 4400
            ->assertSee('Approval & Attribution History')
            // Pair the "Decided By" label with the placeholder em dash, in
            // DOM order, so this can only pass because the null approval
            // relation genuinely fell through to the placeholder — not
            // because "—" happens to occur elsewhere on the page.
            ->assertSeeHtmlInOrder(['Decided By', '—'])
            ->assertDontSee('Cash received in person.');
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

        // The page must not blow up on a null partner — this is the majority
        // case — and, per spec, the two partner-reporting sections must be
        // fully gated off rather than merely empty. A bare assertSuccessful()
        // cannot tell a working ->visible() gate from a broken one: this
        // order's base_price_snapshot (4900) is non-null and amountDue()
        // resolves fine, so an inverted or deleted gate would still render
        // successfully, just with real money values leaking through. Assert
        // the section headings and their exclusive entry labels are absent —
        // confirmed (see task-8-report.md) to be absent from this page's
        // ambient markup, so their absence can only be explained by a
        // correctly-closed gate.
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertDontSee('Partner Sale')
            ->assertDontSee('Approval & Attribution History')
            ->assertDontSee('Base Price')
            ->assertDontSee('Margin')
            ->assertDontSee('Attribution Source')
            ->assertDontSee('Partner');
    }
}
