<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\Dashboard;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
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
            ->assertSee(__('Run audit'));
    }

    public function test_run_audit_header_action_hidden_without_entitlement(): void
    {
        config(['audit.free_reports_limit' => 0]);
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
}
