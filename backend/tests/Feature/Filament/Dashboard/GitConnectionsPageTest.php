<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Filament\Facades\Filament;
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
}
