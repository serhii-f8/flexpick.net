<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\SessionConstants;
use App\Constants\SubscriptionType;
use App\Dto\CartDto;
use App\Dto\SubscriptionCheckoutDto;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\FeatureTest;

/**
 * A finished purchase lands the buyer straight on the dashboard of the
 * workspace it was made for, with the thank-you carried as a notification
 * instead of a dead-end page.
 */
class CheckoutSuccessRedirectTest extends FeatureTest
{
    /** @return array{0: User, 1: Tenant} */
    private function buyer(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);

        return [$user, $tenant];
    }

    private function dashboardOf(Tenant $tenant): string
    {
        return route('filament.dashboard.pages.dashboard', ['tenant' => $tenant]);
    }

    public function test_a_product_purchase_lands_on_the_workspace_dashboard_with_a_thank_you(): void
    {
        [$user, $tenant] = $this->buyer();
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);
        $cart = new CartDto;
        $cart->orderId = (string) $order->id;

        $response = $this->withSession([SessionConstants::CART_DTO => $cart])
            ->get(route('checkout.product.success'));

        $response->assertRedirect($this->dashboardOf($tenant));
        $response->assertSessionHas('filament.notifications');
        $response->assertSessionMissing(SessionConstants::CART_DTO);
        $this->assertStringContainsString(
            __('Thank you for your purchase!'),
            json_encode(session('filament.notifications')),
        );
    }

    public function test_a_subscription_purchase_lands_on_the_workspace_dashboard(): void
    {
        [$user, $tenant] = $this->buyer();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create()->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
        ]);
        $dto = new SubscriptionCheckoutDto;
        $dto->subscriptionId = (string) $subscription->id;

        $response = $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $dto])
            ->get(route('checkout.subscription.success'));

        $response->assertRedirect($this->dashboardOf($tenant));
        $response->assertSessionHas('filament.notifications');
        $response->assertSessionMissing(SessionConstants::SUBSCRIPTION_CHECKOUT_DTO);
    }

    public function test_a_cash_subscription_lands_on_the_workspace_dashboard(): void
    {
        [$user, $tenant] = $this->buyer();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create()->id,
            'type' => SubscriptionType::LOCALLY_MANAGED,
        ]);
        $dto = new SubscriptionCheckoutDto;
        $dto->subscriptionId = (string) $subscription->id;

        $response = $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $dto])
            ->get(route('checkout.subscription.success'));

        $response->assertRedirect($this->dashboardOf($tenant));
        $response->assertSessionHas('filament.notifications');
    }

    public function test_converting_a_cash_subscription_lands_on_the_workspace_dashboard(): void
    {
        [$user, $tenant] = $this->buyer();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create()->id,
            'type' => SubscriptionType::LOCALLY_MANAGED,
        ]);
        $dto = new SubscriptionCheckoutDto;
        $dto->subscriptionId = (string) $subscription->id;

        $response = $this->withSession([SessionConstants::SUBSCRIPTION_CHECKOUT_DTO => $dto])
            ->get(route('checkout.convert-local-subscription.success'));

        $response->assertRedirect($this->dashboardOf($tenant));
        $response->assertSessionHas('filament.notifications');
    }

    public function test_a_success_url_without_a_purchase_in_the_session_goes_home(): void
    {
        $this->buyer();

        $this->get(route('checkout.product.success'))->assertRedirect(route('home'));
        $this->get(route('checkout.subscription.success'))->assertRedirect(route('home'));
    }
}
