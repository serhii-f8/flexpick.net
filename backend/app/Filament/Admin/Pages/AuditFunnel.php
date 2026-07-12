<?php

namespace App\Filament\Admin\Pages;

use App\Services\AuditReport\AuditFunnelStats;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditFunnel extends Page
{
    protected string $view = 'filament.admin.pages.audit-funnel';

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit Funnel');
    }

    public static function canAccess(): bool
    {
        return auth()->user()
            && auth()->user()->hasPermissionTo('update settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Audit Funnel');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Audit Funnel');
    }

    public function getViewData(): array
    {
        $stats = app(AuditFunnelStats::class);

        return [
            'last7' => $stats->counts(7),
            'last30' => $stats->counts(30),
        ];
    }
}
