<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitConnectionService;
use App\Services\GitProviders\GitProviderResolver;
use App\Services\TenantPermissionService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class GitConnections extends Page
{
    protected string $view = 'filament.dashboard.pages.git-connections';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 2;

    /** @return array<string, array{label: string, connected: bool, account: ?string, connected_by: ?string}> */
    public function connections(): array
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();
        $existing = TenantGitConnection::query()->with('connectedBy')->where('tenant_id', $tenant->id)->get()->keyBy('provider');

        $providers = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];

        $result = [];
        foreach ($providers as $key => $label) {
            $connection = $existing->get($key);
            $result[$key] = [
                'label' => $label,
                'connected' => $connection !== null,
                'account' => $connection?->account_login,
                'connected_by' => $connection === null ? null : ($connection->connectedBy?->name ?? __('a former member')),
            ];
        }

        return $result;
    }

    public static function canAccess(): bool
    {
        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            Filament::getTenant(),
            auth()->user(),
            TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS
        );
    }

    /**
     * The only way to start a connection. Livewire requests are CSRF-protected,
     * unlike a plain link, so a third party can't bind an account by luring a
     * member to a URL.
     */
    public function connect(string $provider): void
    {
        abort_unless(in_array($provider, GitProviderResolver::KNOWN_PROVIDER_NAMES, true), 404);
        abort_unless(static::canAccess(), 403);

        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        $nonce = app(GitConnectionService::class)->beginConnect($tenant, auth()->user(), $provider);

        $this->redirect(route('auth.oauth.redirect', [
            'provider' => $provider,
            'intent' => 'git_connection',
            'nonce' => $nonce,
        ]));
    }

    public function disconnect(string $provider): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        TenantGitConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->delete();
    }
}
