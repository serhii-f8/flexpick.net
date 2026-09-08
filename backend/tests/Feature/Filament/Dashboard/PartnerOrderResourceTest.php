<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ListPartnerOrders;
use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerOrderResourceTest extends FeatureTest
{
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

    private function actAsPartner(Tenant $tenant, array $permissions = [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]): User
    {
        $user = $this->createUser($tenant, $permissions);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $user;
    }

    private function referredCustomer(Tenant $partnerTenant): array
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], ['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        return [$customer, $customerTenant];
    }

    private function order(User $customer, Tenant $customerTenant, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $customer->partner_tenant_id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
        ]);
    }

    public function test_access_requires_an_active_partner_and_the_orders_permission(): void
    {
        $this->actAsPartner($this->createTenant());
        $this->assertFalse(PartnerOrderResource::canAccess());

        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner, []);
        $this->assertFalse(PartnerOrderResource::canAccess());

        $this->actAsPartner($partner);
        $this->assertTrue(PartnerOrderResource::canAccess());
    }

    public function test_it_lists_cash_and_gateway_orders_of_referred_customers_only(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $cash = $this->order($customer, $customerTenant);
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();
        $gateway = $this->order($customer, $customerTenant, ['is_local' => false, 'status' => OrderStatus::SUCCESS->value, 'payment_provider_id' => $stripe->id]);
        $legacyStamped = Order::factory()->create(['user_id' => $this->createUser()->id, 'tenant_id' => $this->createTenant()->id, 'partner_tenant_id' => $partner->id, 'status' => OrderStatus::SUCCESS->value]);

        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $foreign = $this->order($otherCustomer, $otherTenant);
        $direct = Order::factory()->create(['user_id' => $this->createUser()->id, 'tenant_id' => $this->createTenant()->id, 'status' => OrderStatus::PENDING->value, 'is_local' => true]);

        Livewire::test(ListPartnerOrders::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$cash, $gateway, $legacyStamped])
            ->assertCanNotSeeTableRecords([$foreign, $direct]);
    }

    public function test_the_pending_tab_is_the_default_and_shows_only_pending_cash_orders(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $pending = $this->order($customer, $customerTenant);
        $approved = $this->order($customer, $customerTenant, ['status' => OrderStatus::SUCCESS->value]);
        $gatewayPending = $this->order($customer, $customerTenant, ['is_local' => false]);

        Livewire::test(ListPartnerOrders::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved, $gatewayPending]);
    }

    public function test_it_shows_base_price_your_price_and_margin_for_a_cash_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->assertSee($customer->email)
            ->assertSee((string) money(4900, 'USD'))
            ->assertSee((string) money(6900, 'USD'))
            ->assertSee((string) money(2000, 'USD'))
            ->assertSee(__('Cash'));
    }

    /**
     * Livewire::test() never boots the panel, so it never registers
     * Filament's tenancy global scope on Subscription -- the exact blind
     * spot that let the infolist regression above ship silently. This
     * drives the list page through a real request instead, the only way to
     * actually exercise that scope, matching how a browser really hits it.
     */
    public function test_the_list_page_shows_the_plan_product_name_for_a_subscription_purchase(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);

        $product = Product::factory()->create(['name' => 'Deep AI Code Review']);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $customerTenant->id,
            'user_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::PENDING->value,
        ]);
        $this->order($customer, $customerTenant, ['subscription_id' => $subscription->id]);

        $this->get(PartnerOrderResource::getUrl(tenant: $partner))
            ->assertSuccessful()
            ->assertSee('Deep AI Code Review');
    }

    public function test_the_navigation_badge_counts_only_this_partners_pending_cash_orders(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $this->order($customer, $customerTenant); // pending cash — counts
        $this->order($customer, $customerTenant, ['status' => OrderStatus::SUCCESS->value]); // approved — doesn't count
        $this->order($customer, $customerTenant, ['is_local' => false]); // pending gateway — doesn't count

        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $this->order($otherCustomer, $otherTenant); // another partner's pending cash order — doesn't count

        $this->assertSame('1', PartnerOrderResource::getNavigationBadge());
    }

    public function test_approve_confirms_the_cash_and_records_the_partner_decision(): void
    {
        $partner = $this->activePartnerTenant();
        $user = $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->callTableAction('approve', $order, data: ['note' => 'Cash received'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $approval = OrderApproval::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('partner', $approval->actor_type);
        $this->assertSame($user->id, $approval->actor_user_id);
        $this->assertSame('Cash received', $approval->note);
    }

    public function test_reject_marks_the_order_rejected(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->callTableAction('reject', $order, data: ['note' => null])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }

    public function test_approve_is_hidden_on_a_gateway_or_settled_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $gateway = $this->order($customer, $customerTenant, ['is_local' => false]);
        $settled = $this->order($customer, $customerTenant, ['status' => OrderStatus::SUCCESS->value]);

        Livewire::test(ListPartnerOrders::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('approve', $gateway)
            ->assertTableActionHidden('approve', $settled)
            ->assertTableActionHidden('reject', $gateway);
    }

    public function test_another_partners_order_cannot_be_approved_by_url(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $foreign = $this->order($otherCustomer, $otherTenant);

        $this->withExceptionHandling();
        $this->get(PartnerOrderResource::getUrl('view', ['record' => $foreign], tenant: $partner))->assertNotFound();
        $this->assertSame(OrderStatus::PENDING->value, $foreign->fresh()->status);
    }

    /**
     * The second arm of getEloquentQuery()'s predicate matches on the
     * buyer's CURRENT attribution, which is set at login/registration with
     * no relation to when any given order was placed. Without a bound by
     * partner_attributed_at, an order the customer placed long before ever
     * being attributed to this partner would still surface here -- a
     * purchase the partner had no part in.
     */
    public function test_an_order_placed_before_attribution_is_not_visible_to_the_partner(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        $customerTenant = $this->createTenant();
        $attributedAt = now();
        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partner->id,
            'partner_attributed_at' => $attributedAt,
        ]);
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();
        $preAttribution = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => false,
            'payment_provider_id' => $stripe->id,
            'created_at' => $attributedAt->copy()->subDay(),
        ]);
        $postAttribution = $this->order($customer, $customerTenant, ['created_at' => $attributedAt->copy()->addDay()]);

        Livewire::test(ListPartnerOrders::class, ['activeTab' => 'all'])
            ->assertCanNotSeeTableRecords([$preAttribution])
            ->assertCanSeeTableRecords([$postAttribution]);
    }

    /**
     * The "Items" infolist section (OrderResource::orderItems()) only ever
     * populates from Order::items(), which a subscription purchase never
     * creates rows in -- one-time products do. Without a dedicated entry,
     * the view page a partner opens to review before approving showed
     * price and status but never *what* was bought for the (now common,
     * since partner-reselling) plan-purchase case.
     */
    public function test_the_view_page_shows_the_plan_product_name_for_a_subscription_purchase(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);

        $product = Product::factory()->create(['name' => 'Deep AI Code Review']);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $customerTenant->id,
            'user_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::PENDING->value,
        ]);
        $order = $this->order($customer, $customerTenant, ['subscription_id' => $subscription->id]);

        $this->get(PartnerOrderResource::getUrl('view', ['record' => $order], tenant: $partner))
            ->assertSuccessful()
            ->assertSee('Deep AI Code Review');
    }

    public function test_the_view_page_loads_for_an_own_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        $this->get(PartnerOrderResource::getUrl('view', ['record' => $order], tenant: $partner))
            ->assertSuccessful()
            ->assertSee($order->uuid)
            ->assertSee($customer->email);
    }
}
