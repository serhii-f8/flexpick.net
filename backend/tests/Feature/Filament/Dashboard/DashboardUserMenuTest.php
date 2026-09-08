<?php

namespace Tests\Feature\Filament\Dashboard;

use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

/**
 * Covers the avatar dropdown (Panel::userMenuItems()), distinct from
 * DashboardMenuItemsTest which covers the sidebar.
 */
class DashboardUserMenuTest extends FeatureTest
{
    public function test_buy_more_or_upgrade_links_to_pricing_for_any_tenant_user(): void
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
}
