<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\TenancyPermissionConstants;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Where a partner sets their selling prices against the platform's (spec §7).
 */
class PartnerPricingSettings extends Page
{
    protected string $view = 'filament.dashboard.pages.partner-pricing-settings';

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $slug = 'pricing-settings';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Pricing Settings');
    }

    public function getTitle(): string
    {
        return __('Pricing Settings');
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
        );
    }
}
