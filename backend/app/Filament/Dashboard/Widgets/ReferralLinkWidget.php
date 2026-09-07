<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\Referrals\ReferralResource;
use App\Services\ReferralService;
use Filament\Widgets\Widget;

class ReferralLinkWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.referral-link-widget';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected static ?int $sort = 9;

    protected static bool $isLazy = false;

    /** @return array{total_referrals: int, rewarded_referrals: int, total_rewards: int|float|string} */
    public function getReferralStats(): array
    {
        return app(ReferralService::class)->getReferralStats(auth()->user());
    }

    public function getReferralLink(): string
    {
        $referralService = app(ReferralService::class);

        return $referralService->getReferralLink(auth()->user());
    }

    public static function canView(): bool
    {
        return ReferralResource::canAccess();
    }
}
