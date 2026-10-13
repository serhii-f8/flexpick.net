<?php

namespace Tests\Feature\Http\Controllers\PaymentProviders;

use App\Constants\SubscriptionStatus;
use App\Models\Order;
use App\Models\UserCardknoxData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPaymentControllerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private const CARD = ['xCardNum' => 'sut_card', 'xCVV' => 'sut_cvv', 'exp_month' => '12', 'exp_year' => '30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function signed(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute($route, now()->addHour(), $parameters);
    }

    public function test_page_requires_a_valid_signature(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);

        $this->actingAs($subscription->user)
            ->get(route('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]))
            ->assertForbidden();
    }

    public function test_page_is_forbidden_to_another_user(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $intruder = $this->createUser($this->createTenant());

        $this->actingAs($intruder)
            ->get($this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]))
            ->assertForbidden();
    }

    public function test_page_renders_ifields_and_the_amount(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 4900]), ['status' => SubscriptionStatus::NEW->value], withCard: false);

        $this->actingAs($subscription->user)
            ->get($this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid, 'quantity' => 1]))
            ->assertOk()
            ->assertSee('data-ifields-id="card-number"', false)
            ->assertSee('ifields_test_key')
            ->assertSee('49.00');
    }

    public function test_paying_activates_the_subscription_and_redirects_to_success(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_saved']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.subscription.submit', ['subscriptionUuid' => $subscription->uuid, 'quantity' => 1]), self::CARD)
            ->assertRedirect(route('checkout.subscription.success'));

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('tok_saved', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }

    public function test_decline_returns_to_the_form_with_the_reason(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $this->fakeGateway($this->gatewayApproves(), $this->gatewayDeclines('Do not honor'));
        $page = $this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]);

        $this->actingAs($subscription->user)
            ->from($page)
            ->post($this->signed('payments-providers.cardknox.pay.subscription.submit', ['subscriptionUuid' => $subscription->uuid]), self::CARD)
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'Do not honor']);

        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->fresh()->status);
    }

    public function test_order_without_save_card_keeps_existing_card(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $existing = UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token;
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_one_time']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.order.submit', ['orderUuid' => $order->uuid]), self::CARD)
            ->assertRedirect(route('checkout.product.success'));

        $this->assertSame($existing, UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
        Http::assertSent(fn ($request): bool => ($request['xToken'] ?? null) === 'tok_one_time');
    }

    public function test_order_with_save_card_replaces_the_card(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_new']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.order.submit', ['orderUuid' => $order->uuid]), self::CARD + ['save_card' => '1']);

        $this->assertSame('tok_new', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }

    public function test_updating_the_card_retries_a_past_due_renewal(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subDay(),
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => now()->addDays(2)->toDateTimeString()],
        ]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_fresh']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.card.submit', ['subscriptionUuid' => $subscription->uuid]), self::CARD)
            ->assertRedirect();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('tok_fresh', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }
}
