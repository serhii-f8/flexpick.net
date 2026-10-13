<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PlanType;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxProvider;
use App\Services\PaymentProviders\PaymentService;
use App\Services\SubscriptionUsageService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxProviderTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function provider(): CardknoxProvider
    {
        return app(PaymentService::class)->getPaymentProviderBySlug('cardknox');
    }

    public function test_it_is_registered_as_a_redirect_provider_with_full_capabilities(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(CardknoxProvider::class, $provider);
        $this->assertTrue($provider->isRedirectProvider());
        $this->assertFalse($provider->isOverlayProvider());
        $this->assertTrue($provider->supportsSkippingTrial());
        $this->assertTrue($provider->supportsSeatBasedWithIncludedSeats());
        $this->assertTrue($provider->supportsOneTimePurchaseProductQuantity());
        $this->assertTrue($provider->supportsSetupFees());
        $this->assertSame('Cardknox', $provider->getName());
    }

    public function test_supports_every_plan_type_priced_in_usd(): void
    {
        foreach ([PlanType::FLAT_RATE, PlanType::SEAT_BASED, PlanType::USAGE_BASED] as $type) {
            $this->assertTrue($this->provider()->supportsPlan($this->cardknoxPlan(['type' => $type->value])));
        }
    }

    public function test_checkout_links_are_signed_pay_page_urls_carrying_quantity_and_discount(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan);
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id]);

        $subscriptionLink = $this->provider()->createSubscriptionCheckoutRedirectLink($plan, $subscription, null, 3);
        $orderLink = $this->provider()->createProductCheckoutRedirectLink($order);
        $cardLink = $this->provider()->getChangePaymentMethodLink($subscription);

        $this->assertStringContainsString('/payments-providers/cardknox/pay/subscription/'.$subscription->uuid, $subscriptionLink);
        $this->assertStringContainsString('quantity=3', $subscriptionLink);
        $this->assertStringContainsString('signature=', $subscriptionLink);
        $this->assertStringContainsString('/payments-providers/cardknox/pay/order/'.$order->uuid, $orderLink);
        $this->assertStringContainsString('/payments-providers/cardknox/card/'.$subscription->uuid, $cardLink);
    }

    public function test_cancel_discard_discount_and_usage_are_local(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value]));

        $this->assertTrue($this->provider()->cancelSubscription($subscription));
        $this->assertTrue($this->provider()->discardSubscriptionCancellation($subscription));
        $this->assertTrue($this->provider()->reportUsage($subscription, 5));

        $this->assertTrue(app(SubscriptionUsageService::class)->reportUsage(5, $subscription));
        $this->assertSame(5, (int) $subscription->usages()->sum('unit_count'));

        Http::assertNothingSent();
    }
}
