<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\Dashboard;
use App\Filament\Dashboard\Widgets\LatestHealthWidget;
use App\Filament\Dashboard\Widgets\PlanUsageWidget;
use App\Filament\Dashboard\Widgets\ReferralLinkWidget;
use App\Filament\Dashboard\Widgets\ReferralStatsWidget;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Tests\Feature\FeatureTest;

class DashboardPageTest extends FeatureTest
{
    public function test_run_audit_header_action_is_present_for_entitled_user(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Run an audit'));
    }

    /**
     * The production default is zero free runs, so a fresh signup clears no
     * quota arm -- but every tier is priced, and being able to buy one is
     * itself access.
     */
    public function test_run_audit_header_action_present_for_a_fresh_signup_at_the_production_default(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Run an audit'));
    }

    public function test_run_audit_header_action_hidden_without_entitlement_or_a_buyable_tier(): void
    {
        // An empty catalog is what makes this a real negative now: with one,
        // any authenticated user can always reach a purchase.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertDontSee(__('Run audit'));
    }

    public function test_the_home_page_leads_with_health_and_credits_not_the_account_card(): void
    {
        config(['audit.free_reports_limit' => 3, 'app.referral.enabled' => true]);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $widgets = array_values((new Dashboard)->getVisibleWidgets());

        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(ReferralStatsWidget::class, $widgets);

        $this->assertSame(
            [LatestHealthWidget::class, PlanUsageWidget::class],
            array_slice($widgets, 0, 2),
            'Health and credits lead the home page.'
        );
        $this->assertSame(ReferralLinkWidget::class, end($widgets), 'Referrals close the page.');
    }
}
