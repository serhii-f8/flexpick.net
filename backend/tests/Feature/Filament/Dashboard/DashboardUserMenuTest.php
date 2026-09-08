<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Pages\Dashboard;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

/**
 * Covers the avatar dropdown (Panel::userMenuItems()), distinct from
 * DashboardMenuItemsTest which covers the sidebar.
 */
class DashboardUserMenuTest extends FeatureTest
{
    public function test_buy_more_or_upgrade_links_to_pricing_when_the_tenant_has_no_subscription_yet(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $items = Filament::getCurrentPanel()->getUserMenuItems();

        $this->assertArrayHasKey('buy-more', $items);
        $this->assertSame(__('Buy More / Upgrade'), $items['buy-more']->getLabel());
        $this->assertSame(route('pricing'), $items['buy-more']->getUrl());
    }

    /**
     * The bug this guards against: a tenant that already has an active
     * subscription must not be sent to /pricing's plan-purchase flow, which
     * would silently spin up a second workspace instead of upgrading this
     * one (a tenant can only ever hold one active subscription).
     */
    public function test_buy_more_or_upgrade_links_to_change_plan_when_the_tenant_has_a_changeable_subscription(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $plan = Plan::factory()->create(['type' => PlanType::FLAT_RATE->value, 'is_active' => true]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $items = Filament::getCurrentPanel()->getUserMenuItems();

        $this->assertSame(
            SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid]),
            $items['buy-more']->getUrl(),
        );
    }

    /**
     * Registration alone (the test above) would not have caught a page that
     * still renders fine but silently drops the item -- assert it actually
     * appears in the rendered dashboard HTML, the same way
     * DashboardMenuItemsTest verifies the sidebar.
     */
    public function test_buy_more_or_upgrade_appears_on_the_rendered_dashboard_page(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Buy More / Upgrade'));
    }
}
