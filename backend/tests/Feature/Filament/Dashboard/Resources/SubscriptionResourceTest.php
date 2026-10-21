<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\PlanType;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\CurrencyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTest;

class SubscriptionResourceTest extends FeatureTest
{
    public function test_list(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);

        $response = $this->get(SubscriptionResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertStatus(200);
    }

    public function test_list_fails_when_user_has_no_permission(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->actingAs($user);
        $this->expectException(HttpException::class);

        $this->get(SubscriptionResource::getUrl('index', [], true, 'dashboard', tenant: $tenant));
    }

    public function test_change_plan(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);

        // create subscription for this user
        $subscription = Subscription::factory()->for($user)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->get(SubscriptionResource::getUrl('change-plan', [
            'record' => $subscription->uuid,
        ], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertStatus(200);
    }

    public function test_cancel()
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);

        // create subscription for this user
        $subscription = Subscription::factory()->for($user)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->get(SubscriptionResource::getUrl('cancel', [
            'record' => $subscription->uuid,
        ], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertStatus(200);
    }

    public function test_confirm_cancellation()
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);

        // create subscription for this user
        $subscription = Subscription::factory()->for($user)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->get(SubscriptionResource::getUrl('confirm-cancellation', [
            'record' => $subscription->uuid,
        ], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertStatus(200);
    }

    public function test_change_plan_marks_the_current_plan_and_hides_a_lone_interval_switcher(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);

        $product = Product::factory()->create(['name' => 'Studio Current']);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 5900,
        ]);

        $subscription = Subscription::factory()->for($user)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->get(SubscriptionResource::getUrl('change-plan', [
            'record' => $subscription->uuid,
        ], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertSee('Studio Current');
        $response->assertSee(__('Current plan'));
        $response->assertDontSee(__('You are currently on the'));
        $response->assertDontSee(__('Change Subscription Plan'));
        $response->assertSee(__('Change plan'));
    }

    /**
     * Workspace members get the view permission only: they can see billing,
     * but every subscription-management page refuses them.
     */
    public function test_a_view_only_member_cannot_open_the_management_pages(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        ]);

        $this->actingAs($user);
        $this->withExceptionHandling();

        $subscription = Subscription::factory()->for($user)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->get(SubscriptionResource::getUrl('view', [
            'record' => $subscription->uuid,
        ], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertDontSee(__('Manage Subscription'));

        foreach (['change-plan', 'cancel', 'confirm-cancellation', 'add-discount'] as $page) {
            $this->get(SubscriptionResource::getUrl($page, [
                'record' => $subscription->uuid,
            ], true, 'dashboard', tenant: $tenant))->assertForbidden();
        }
    }

    public function test_the_member_role_can_view_billing_but_not_manage_subscriptions(): void
    {
        $role = Role::query()
            ->where('name', TenancyPermissionConstants::ROLE_USER)
            ->where('is_tenant_role', true)
            ->firstOrFail();

        $this->assertTrue($role->hasPermissionTo(TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS));
        $this->assertTrue($role->hasPermissionTo(TenancyPermissionConstants::PERMISSION_VIEW_ORDERS));
        $this->assertTrue($role->hasPermissionTo(TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS));
        $this->assertFalse($role->hasPermissionTo(TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS));
    }
}
