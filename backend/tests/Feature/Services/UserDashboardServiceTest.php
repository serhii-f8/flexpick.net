<?php

namespace Tests\Feature\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\UserDashboardService;
use Tests\Feature\FeatureTest;

class UserDashboardServiceTest extends FeatureTest
{
    private UserDashboardService $userDashboardService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userDashboardService = app(UserDashboardService::class);
    }

    public function test_user_with_a_tenant_is_sent_to_the_tenant_dashboard(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['created_by' => $user->id]);
        $tenant->users()->attach($user);

        $url = $this->userDashboardService->getUserDashboardUrl($user);

        $this->assertSame(route('filament.dashboard.pages.dashboard', ['tenant' => $tenant]), $url);
    }

    public function test_admin_without_a_tenant_is_sent_to_the_admin_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $url = $this->userDashboardService->getUserDashboardUrl($admin);

        $this->assertSame(route('filament.admin.pages.dashboard'), $url);
    }

    public function test_non_admin_without_a_tenant_is_sent_home(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $url = $this->userDashboardService->getUserDashboardUrl($user);

        $this->assertSame(route('home'), $url);
    }
}
