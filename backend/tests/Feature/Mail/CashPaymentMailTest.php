<?php

namespace Tests\Feature\Mail;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Mail\CashPayments\CustomerOrderApproved;
use App\Mail\CashPayments\CustomerOrderExpired;
use App\Mail\CashPayments\CustomerOrderRejected;
use App\Mail\CashPayments\PartnerNewPendingOrder;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class CashPaymentMailTest extends FeatureTest
{
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

    private function pendingCashOrder(?Tenant $partnerTenant = null): Order
    {
        $tenant = $this->createTenant();
        $customer = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant?->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
        ]);
    }

    public function test_the_partner_is_told_when_a_cash_order_opens(): void
    {
        Mail::fake();

        $partnerTenant = $this->activePartnerTenant();
        $partnerUser = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $bystander = $this->createUser($partnerTenant, []);
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        app(OrderService::class)->create(
            $customer,
            $customerTenant,
            totalAmount: 6900,
            currency: Currency::where('code', 'USD')->first(),
            isLocal: true,
            snapshot: ['partner_tenant_id' => $partnerTenant->id, 'base_price_snapshot' => 4900],
        );

        // No discount was applied, so total_amount_after_discount stays at its
        // NOT NULL DEFAULT 0 — the mail must still quote the real amount due
        // (total_amount), not $0.00.
        Mail::assertQueued(PartnerNewPendingOrder::class, function ($mail) use ($partnerUser) {
            $mail->assertSeeInHtml((string) money(6900, 'USD'));

            return $mail->hasTo($partnerUser->email);
        });
        Mail::assertNotQueued(PartnerNewPendingOrder::class, fn ($mail) => $mail->hasTo($bystander->email));
    }

    public function test_no_partner_mail_for_a_direct_cash_order(): void
    {
        Mail::fake();

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        app(OrderService::class)->create(
            $customer,
            $customerTenant,
            totalAmount: 4900,
            currency: Currency::where('code', 'USD')->first(),
            isLocal: true,
        );

        Mail::assertNotQueued(PartnerNewPendingOrder::class);
    }

    public function test_the_customer_is_told_the_outcome(): void
    {
        Mail::fake();

        $service = app(OrderApprovalService::class);

        $approved = $this->pendingCashOrder();
        $service->approve($approved, OrderApprovalActor::PARTNER, User::factory()->create());
        Mail::assertQueued(CustomerOrderApproved::class, fn ($mail) => $mail->hasTo($approved->user->email));

        $rejected = $this->pendingCashOrder();
        $service->reject($rejected, OrderApprovalActor::ADMIN, User::factory()->create());
        Mail::assertQueued(CustomerOrderRejected::class, fn ($mail) => $mail->hasTo($rejected->user->email));

        $expired = $this->pendingCashOrder();
        $service->reject($expired, OrderApprovalActor::SYSTEM, null, 'Timed out.');
        Mail::assertQueued(CustomerOrderExpired::class, fn ($mail) => $mail->hasTo($expired->user->email));
        Mail::assertNotQueued(CustomerOrderRejected::class, fn ($mail) => $mail->hasTo($expired->user->email));
    }

    public function test_a_second_approval_is_a_silent_no_op_and_sends_no_extra_mail(): void
    {
        Mail::fake();

        $service = app(OrderApprovalService::class);
        $order = $this->pendingCashOrder();

        $service->approve($order, OrderApprovalActor::PARTNER, User::factory()->create());
        $service->approve($order, OrderApprovalActor::PARTNER, User::factory()->create());

        Mail::assertQueued(CustomerOrderApproved::class, 1);
    }
}
