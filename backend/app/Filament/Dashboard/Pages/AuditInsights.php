<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Dashboard\Widgets\AuditInsightsStatsWidget;
use App\Services\AuditReport\AuditInsightsService;
use App\Services\PartnerCapabilityService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Anonymized, platform-wide audit statistics for partners to quote in their
 * own marketing. Aggregates only — see AuditInsightsService.
 */
class AuditInsights extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-insights';

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static ?string $slug = 'audit-insights';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit Insights');
    }

    public function getTitle(): string
    {
        return __('Audit Insights');
    }

    public static function canAccess(): bool
    {
        return app(PartnerCapabilityService::class)->userCanAccessPartnerArea(Filament::getTenant(), auth()->user());
    }

    protected function getHeaderWidgets(): array
    {
        return $this->getViewData()['stats'] === null ? [] : [AuditInsightsStatsWidget::class];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $insights = app(AuditInsightsService::class);

        return [
            'stats' => $insights->stats(),
            'codebasesNeeded' => $insights->codebasesNeeded(),
        ];
    }
}
