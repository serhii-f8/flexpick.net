<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\Referrals\ReferralResource;
use App\Services\CurrencyService;
use App\Services\PartnerMarginService;
use App\Services\ReferralService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReferralStatsWidget extends BaseWidget
{
    /**
     * Rendered on the Referrals page only. On the home page the counts fold
     * into ReferralLinkWidget so referrals take one quiet row, not four.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $referralService = app(ReferralService::class);
        $user = auth()->user();
        $stats = $referralService->getReferralStats($user);

        return [
            Stat::make(__('Total Referrals'), $stats['total_referrals'])
                ->description(__('Total number of users you\'ve referred')),
            Stat::make(__('Successful'), $stats['rewarded_referrals'])
                ->description(__('Referrals that earned rewards'))
                ->color('success'),
            Stat::make(__('Rewards Earned'), $stats['total_rewards'])
                ->description(__('Total rewards you\'ve received'))
                ->color('success'),
            Stat::make(__('Margin earned'), money(
                app(PartnerMarginService::class)->earnedMargin(Filament::getTenant()),
                app(CurrencyService::class)->getCurrency()->code,
            ))
                ->description(__('Your share of every approved order from the customers you referred'))
                ->color('success'),
        ];
    }

    public static function canView(): bool
    {
        return ReferralResource::canAccess();
    }
}
