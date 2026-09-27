<?php

namespace App\Filament\Dashboard\Pages;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class GitConnections extends Page
{
    protected string $view = 'filament.dashboard.pages.git-connections';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 2;

    /** @return array<string, array{label: string, connected: bool, account: ?string}> */
    public function connections(): array
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();
        $existing = TenantGitConnection::query()->where('tenant_id', $tenant->id)->get()->keyBy('provider');

        $providers = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];

        $result = [];
        foreach ($providers as $key => $label) {
            $connection = $existing->get($key);
            $result[$key] = [
                'label' => $label,
                'connected' => $connection !== null,
                'account' => $connection?->account_login,
            ];
        }

        return $result;
    }

    public function connectUrl(string $provider): string
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        return route('auth.oauth.redirect', [
            'provider' => $provider,
            'intent' => 'git_connection',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function disconnect(string $provider): void
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        TenantGitConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->delete();
    }
}
