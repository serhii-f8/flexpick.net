<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\FeatureTest;

class GitConnectionsPageTest extends FeatureTest
{
    public function test_shows_connected_and_unconnected_providers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS]);
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
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS]);
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->call('disconnect', 'github');

        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'github']);
    }

    public function test_a_member_without_the_settings_permission_cannot_access_the_page(): void
    {
        $this->actAsTenantMember([]);

        $this->assertFalse(GitConnections::canAccess());
    }

    public function test_a_member_with_the_settings_permission_can_access_the_page(): void
    {
        $this->actAsTenantMember();

        $this->assertTrue(GitConnections::canAccess());
    }

    public function test_the_page_refuses_to_mount_for_a_member_without_the_permission(): void
    {
        $tenant = $this->actAsTenantMember([]);

        $this->expectException(HttpException::class);

        Livewire::test(GitConnections::class, ['tenant' => $tenant]);
    }

    /** Server-side gate: a component mounted while permitted must still refuse once the caller lacks it. */
    public function test_a_member_without_the_permission_cannot_disconnect(): void
    {
        $tenant = $this->actAsTenantMember();
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);
        $component = Livewire::test(GitConnections::class, ['tenant' => $tenant]);

        $this->actingAs($this->createUser($tenant));

        try {
            $component->call('disconnect', 'github');
            $this->fail('disconnect() ran for a member without the permission.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseHas('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'github']);
    }

    public function test_a_member_without_the_permission_cannot_start_a_connect(): void
    {
        $tenant = $this->actAsTenantMember();
        $component = Livewire::test(GitConnections::class, ['tenant' => $tenant]);

        $this->actingAs($this->createUser($tenant));

        try {
            $component->call('connect', 'github');
            $this->fail('connect() ran for a member without the permission.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull(session('git_connection_nonces'));
    }

    public function test_connect_plants_a_nonce_and_redirects_carrying_only_the_nonce(): void
    {
        $tenant = $this->actAsTenantMember();

        $component = Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->call('connect', 'github');

        $url = $component->effects['redirect'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('/auth/github/redirect', parse_url($url, PHP_URL_PATH));
        $this->assertSame('git_connection', $query['intent']);
        $this->assertArrayNotHasKey('tenant_id', $query);
        $this->assertNotEmpty($query['nonce']);
        $this->assertArrayHasKey($query['nonce'], session('git_connection_nonces'));
    }

    public function test_connect_rejects_an_unknown_provider(): void
    {
        $tenant = $this->actAsTenantMember();

        $this->expectException(NotFoundHttpException::class);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->call('connect', 'google');
    }

    public function test_it_shows_who_connected_each_account(): void
    {
        $tenant = $this->actAsTenantMember();
        $connector = User::factory()->create(['name' => 'Ada Connector']);
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github', 'account_login' => 'octocat', 'connected_by_user_id' => $connector->id,
        ]);
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'gitlab', 'account_login' => 'tanuki', 'connected_by_user_id' => null,
        ]);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertSee('Ada Connector')
            ->assertSee('a former member');
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

    private function actAsTenantMember(array $permissions = [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS]): Tenant
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, $permissions);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $tenant;
    }
}
