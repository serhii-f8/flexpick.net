<?php

namespace Tests\Feature\Services;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerMarginService;
use Tests\Feature\FeatureTest;

class PartnerMarginServiceTest extends FeatureTest
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

    /** @return array{0: User, 1: Tenant} */
    private function referredCustomer(Tenant $partnerTenant): array
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], ['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        return [$customer, $customerTenant];
    }

    private function cashOrder(User $customer, Tenant $customerTenant, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $customer->partner_tenant_id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => true,
            'total_amount' => 6900,
            'total_amount_after_discount' => 0,
            'base_price_snapshot' => 4900,
        ]);
    }

    public function test_it_sums_the_margin_of_approved_cash_orders_only(): void
    {
        $partner = $this->activePartnerTenant();
        [$customer, $customerTenant] = $this->referredCustomer($partner);

        $this->cashOrder($customer, $customerTenant);                                                        // 6900 - 4900 = 2000
        $this->cashOrder($customer, $customerTenant, ['total_amount' => 11900, 'base_price_snapshot' => 9900]); // 2000
        $this->cashOrder($customer, $customerTenant, ['status' => OrderStatus::PENDING->value]);            // not approved yet
        $this->cashOrder($customer, $customerTenant, ['status' => OrderStatus::REJECTED->value]);           // never earned
        $this->cashOrder($customer, $customerTenant, ['status' => OrderStatus::REFUNDED->value]);           // given back

        $this->assertSame(4000, app(PartnerMarginService::class)->earnedMargin($partner));
    }

    public function test_the_discounted_amount_is_what_the_customer_actually_paid(): void
    {
        $partner = $this->activePartnerTenant();
        [$customer, $customerTenant] = $this->referredCustomer($partner);

        // Same rule as OrderApprovalService::amountDue(): after-discount when
        // set, the gross total otherwise.
        $this->cashOrder($customer, $customerTenant, ['total_amount' => 6900, 'total_amount_after_discount' => 5900]); // 5900 - 4900 = 1000

        $this->assertSame(1000, app(PartnerMarginService::class)->earnedMargin($partner));
    }

    public function test_gateway_orders_and_other_partners_orders_earn_nothing(): void
    {
        $partner = $this->activePartnerTenant();
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();

        // A gateway purchase never carries a partner price: no snapshot, no margin.
        $this->cashOrder($customer, $customerTenant, ['is_local' => false, 'payment_provider_id' => $stripe->id, 'base_price_snapshot' => null]);

        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $this->cashOrder($otherCustomer, $otherTenant);

        $this->assertSame(0, app(PartnerMarginService::class)->earnedMargin($partner));
        $this->assertSame(2000, app(PartnerMarginService::class)->earnedMargin($otherPartner));
    }
}
