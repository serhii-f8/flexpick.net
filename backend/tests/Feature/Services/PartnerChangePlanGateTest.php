<?php

namespace Tests\Feature\Services;

use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class PartnerChangePlanGateTest extends FeatureTest
{
    private function activeGatewaySubscription(Tenant $tenant): Subscription
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
        ]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }

    private function makePartner(Tenant $tenant): void
    {
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }

    public function test_a_direct_tenant_can_change_plan(): void
    {
        $tenant = $this->createTenant();
        $subscription = $this->activeGatewaySubscription($tenant);

        $this->assertTrue(app(SubscriptionService::class)->canChangeSubscriptionPlan($subscription));
    }

    public function test_a_partner_tenant_cannot_change_plan(): void
    {
        $tenant = $this->createTenant();
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->makePartner($tenant);

        $this->assertFalse(app(SubscriptionService::class)->canChangeSubscriptionPlan($subscription));
    }

    public function test_the_change_plan_page_is_forbidden_for_a_partner_tenant(): void
    {
        $this->withExceptionHandling();
        config()->set('app.customer_dashboard.show_subscriptions', true);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->makePartner($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $tenant))
            ->assertForbidden();
    }

    public function test_the_change_plan_page_loads_for_a_direct_tenant(): void
    {
        config()->set('app.customer_dashboard.show_subscriptions', true);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $tenant))
            ->assertSuccessful();
    }

    public function test_the_change_plan_route_refuses_a_partner_tenant(): void
    {
        $this->withExceptionHandling();
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $otherPlan = Plan::factory()->create(['product_id' => Product::factory()->create()->id, 'is_active' => true]);
        $this->makePartner($tenant);

        $response = $this->actingAs($user)->from('/dashboard')->get(route('subscription.change-plan', [
            'subscriptionUuid' => $subscription->uuid,
            'planSlug' => $otherPlan->slug,
            'tenantUuid' => $tenant->uuid,
        ]));

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', __('Plan changes are not available for this subscription.'));
    }
}
