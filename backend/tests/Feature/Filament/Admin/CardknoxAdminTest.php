<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Filament\Admin\Resources\PaymentProviders\PaymentProviderResource;
use App\Filament\Admin\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Admin\Resources\Transactions\Pages\ViewTranscription;
use App\Livewire\Filament\CardknoxSettings;
use App\Models\Order;
use App\Services\ConfigService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxAdminTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_settings_page_is_registered(): void
    {
        $this->assertTrue(PaymentProviderResource::hasPage('cardknox-settings'));
    }

    public function test_settings_require_a_valid_pin(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)->test(CardknoxSettings::class)
            ->fillForm(['transaction_key' => 'key', 'ifields_key' => 'ifk', 'webhook_pin' => 'short'])
            ->call('save')
            ->assertHasFormErrors(['webhook_pin']);

        Livewire::actingAs($admin)->test(CardknoxSettings::class)
            ->fillForm(['transaction_key' => 'key_saved', 'ifields_key' => 'ifk', 'webhook_pin' => 'abcdefghijklmnop2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('key_saved', app(ConfigService::class)->get('services.cardknox.transaction_key'));
    }

    private function paidOrderCharge(): array
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves(['xRefNum' => '88'])));

        return [$order, $charge->transaction->fresh()];
    }

    public function test_admin_can_refund_a_cardknox_transaction(): void
    {
        [$order, $transaction] = $this->paidOrderCharge();
        $this->fakeGateway($this->gatewayApproves());
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewTranscription::class, ['record' => $transaction->getRouteKey()])
            ->callAction('cardknox_refund');

        $this->assertSame(TransactionStatus::REFUNDED->value, $transaction->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
    }

    public function test_admin_can_mark_a_transaction_disputed(): void
    {
        [$order, $transaction] = $this->paidOrderCharge();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewTranscription::class, ['record' => $transaction->getRouteKey()])
            ->callAction('cardknox_mark_disputed');

        $this->assertSame(TransactionStatus::DISPUTED->value, $transaction->fresh()->status);
        $this->assertSame(OrderStatus::DISPUTED->value, $order->fresh()->status);
    }

    public function test_admin_can_charge_a_past_due_subscription_now(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subDay(),
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => now()->addDays(2)->toDateTimeString()],
        ]);
        $this->fakeGateway($this->gatewayApproves());
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cardknox_charge_now');

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_cardknox_cannot_be_enabled_while_incomplete(): void
    {
        config(['services.cardknox.webhook_pin' => null]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $this->get(PaymentProviderResource::getUrl('edit', ['record' => $this->cardknoxProviderModel()], panel: 'admin'))
            ->assertOk()
            ->assertSee(__('Save the transaction key, iFields key and a webhook PIN in the Cardknox settings first.'));
    }
}
