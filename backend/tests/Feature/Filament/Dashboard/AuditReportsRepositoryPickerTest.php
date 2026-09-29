<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsRepositoryPickerTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRepositoryAccess();
    }

    /** @return array{0: User, 1: Tenant} a member with audit allowance who may manage git connections */
    private function manager(bool $mayManage = true): array
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);

        if ($mayManage) {
            $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot
                ->givePermissionTo(TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS);
        }

        $this->actAsTenantUser($user, $tenant);
        RateLimiter::clear("repo-picker:{$user->id}");

        return [$user, $tenant];
    }

    private function connect(Tenant $tenant, string $provider = 'github'): TenantGitConnection
    {
        return TenantGitConnection::factory()->create(['tenant_id' => $tenant->id, 'provider' => $provider]);
    }

    private function fakeGithub(array $names = ['acme/api', 'acme/web']): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response(array_map(fn ($n) => [
                'full_name' => $n,
                'html_url' => "https://github.com/{$n}",
                'private' => true,
                'default_branch' => 'main',
                'pushed_at' => '2026-09-01T10:00:00Z',
            ], $names)),
            'api.github.com/repos/*/branches*' => Http::response([['name' => 'main'], ['name' => 'develop']]),
        ]);
    }

    public function test_without_a_connection_the_form_is_unchanged_and_offers_to_connect(): void
    {
        [$user, $tenant] = $this->manager();

        Livewire::test(AuditReports::class)
            ->assertSee('Repository URL')
            ->assertSee('Private repository?')
            ->assertSee('Connect an account')
            ->assertDontSee('Paste a URL instead');
    }

    public function test_a_member_without_the_permission_sees_no_picker_and_no_connect_link(): void
    {
        [$user, $tenant] = $this->manager(mayManage: false);
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->assertSee('Repository URL')
            ->assertDontSee('acme/api')
            ->assertDontSee('Paste a URL instead')
            ->assertDontSee('Connect an account');

        Http::assertNothingSent();
    }

    public function test_with_a_connection_the_picker_lists_repositories_and_the_url_box_is_a_fallback(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->assertSet('pickerProvider', 'github')
            ->assertSee('acme/api')
            ->assertSee('acme/web')
            ->assertSee('Paste a URL instead')
            ->assertDontSee('Private repository?')
            ->call('useUrlInput')
            ->assertSee('Private repository?')
            ->assertSee('Choose from a connected account')
            ->call('usePicker')
            ->assertSee('acme/api');
    }

    public function test_choosing_a_repository_sets_the_url_and_loads_its_branches(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->assertSet('repoUrl', 'https://github.com/acme/api')
            ->assertSet('branchesByRepo', ['https://github.com/acme/api' => ['main', 'develop']])
            ->assertSee('Repo default branch')
            ->call('clearRepository')
            ->assertSet('repoUrl', null)
            ->assertSet('branch', null);
    }

    public function test_a_url_that_is_not_in_the_accounts_listing_is_rejected(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/someone-else/secret')
            ->assertSet('repoUrl', null)
            ->call('chooseRepository', 'https://gitlab.com/acme/api')
            ->assertSet('repoUrl', null);
    }

    public function test_a_forged_provider_is_ignored_and_a_member_without_the_permission_gets_a_403(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('selectProvider', 'gitlab') // no gitlab connection
            ->assertSet('pickerProvider', 'github')
            ->call('selectProvider', 'not-a-provider')
            ->assertSet('pickerProvider', 'github');

        [$plain, $plainTenant] = $this->manager(mayManage: false);
        $this->connect($plainTenant);

        Livewire::test(AuditReports::class)
            ->set('pickerProvider', 'github') // client-forged public property
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->assertSet('repoUrl', null);
    }

    public function test_search_narrows_the_list_and_paging_walks_it(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub(array_merge(array_map(fn ($i) => "acme/svc-{$i}", range(1, 25)), ['acme/website']));

        Livewire::test(AuditReports::class)
            ->assertSee('acme/svc-1')
            ->assertDontSee('acme/svc-21')
            ->call('nextRepositoryPage')
            ->assertSet('repoPage', 2)
            ->assertSee('acme/svc-21')
            ->call('previousRepositoryPage')
            ->assertSet('repoPage', 1)
            ->set('repoSearch', 'web')
            ->assertSet('repoPage', 1)
            ->assertSee('acme/website')
            ->assertDontSee('acme/svc-1')
            ->set('repoSearch', 'no-such-repo')
            ->assertSee('No repositories found');
    }

    public function test_a_revoked_token_shows_reconnect_and_the_url_box_stays_reachable(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        Http::fake(['api.github.com/user/repos*' => Http::response([], 401)]);

        Livewire::test(AuditReports::class)
            ->assertSee('Reconnect your GitHub account')
            ->assertSee('Paste a URL instead')
            ->call('useUrlInput')
            ->assertSee('Repository URL');
    }

    public function test_an_outage_shows_try_again_and_the_url_box_stays_reachable(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        Http::fake(['api.github.com/user/repos*' => Http::response([], 503)]);

        Livewire::test(AuditReports::class)
            ->assertSee("Couldn't reach GitHub")
            ->assertSee('Paste a URL instead')
            ->call('useUrlInput')
            ->assertSee('Repository URL');
    }

    public function test_a_picked_repository_launches_exactly_like_a_pasted_url(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->call('launchAudit');

        $request = AuditRequest::where('user_id', $user->id)->sole();
        $this->assertSame('https://github.com/acme/api', $request->repo_url);
        $this->assertSame($tenant->id, $request->tenant_id);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_email_link_still_prefills_the_url_box(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::withQueryParams(['repo' => 'https://github.com/acme/api'])
            ->test(AuditReports::class)
            ->assertSet('repoUrl', 'https://github.com/acme/api')
            ->assertSet('pickerMode', 'url');
    }
}
