<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class GitConnectionsPageTest extends FeatureTest
{
    public function test_shows_connected_and_unconnected_providers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'account_login' => 'octocat']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertSee('octocat')
            ->assertSee('Not connected');
    }

    public function test_disconnect_removes_the_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->call('disconnect', 'github');

        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'github']);
    }

    public function test_renders_the_connect_success_status(): void
    {
        $tenant = $this->actAsTenantMember();
        session()->flash('status', 'GitHub connected.');

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertSee('GitHub connected.');
    }

    /**
     * The OAuth callback redirects with withErrors(); ShareErrorsFromSession shares that
     * bag with views, and Livewire seeds a component's error bag from it on first render.
     */
    public function test_renders_the_connect_failure_error(): void
    {
        $tenant = $this->actAsTenantMember();
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'git_connection' => ['Connection was cancelled or failed. Please try again.'],
        ])));

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertSee('Connection was cancelled or failed. Please try again.');
    }

    public function test_renders_no_banner_without_a_flash(): void
    {
        $tenant = $this->actAsTenantMember();

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertDontSee('role="status"', false)
            ->assertDontSee('role="alert"', false);
    }

    private function actAsTenantMember(): Tenant
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $tenant;
    }
}
