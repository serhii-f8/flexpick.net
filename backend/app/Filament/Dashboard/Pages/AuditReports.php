<?php

namespace App\Filament\Dashboard\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

    public function getHeading(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && auth()->user()->auditReports()->exists();
    }

    public function getViewData(): array
    {
        return [
            'reports' => auth()->user()->auditReports()->with('auditRequest')->latest()->get(),
        ];
    }
}
