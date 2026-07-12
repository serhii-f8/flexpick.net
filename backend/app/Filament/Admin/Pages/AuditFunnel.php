<?php

namespace App\Filament\Admin\Pages;

use App\Services\AuditReport\AuditFunnelStats;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditFunnel extends Page
{
    protected string $view = 'filament.admin.pages.audit-funnel';

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
