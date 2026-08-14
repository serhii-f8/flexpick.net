<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\Subscription;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\TierQuota;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class PlanUsageWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.plan-usage-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        /** @var Subscription|null $subscription */
        $subscription = app(SubscriptionService::class)
            ->findActiveTenantSubscriptions($tenant)
            ->first();

        $quotas = $entitlements->quotas($user, $tenant);
        $metered = collect($quotas)->reject(fn (TierQuota $quota): bool => $quota->isLifetime);

        $colors = ['bg-primary-500', 'bg-secondary-500', 'bg-warning-500'];
        $bars = [];

        foreach ($metered->values() as $index => $quota) {
            // Hidden entirely at zero: a plan without credits for a tier
            // should not advertise them.
            if ($quota->limit < 1) {
                continue;
            }

            $bars[] = [
                'label' => $quota->label,
                'used' => $quota->used,
                'total' => $quota->limit,
                'color' => $colors[$index % count($colors)],
            ];
        }

        if ($bars === []) {
            $free = collect($quotas)->firstWhere(fn (TierQuota $quota): bool => $quota->isLifetime);

            $bars[] = [
                'label' => __('Free audits'),
                'used' => $free?->used ?? 0,
                'total' => $free?->limit ?? 0,
                'color' => 'bg-primary-500',
            ];
        }

        return [
            'planName' => $subscription?->plan?->name ?? __('Free'),
            'renewsAt' => $subscription?->ends_at ? Carbon::parse($subscription->ends_at) : null,
            'bars' => $bars,
            'showUpgrade' => collect($quotas)->every(fn (TierQuota $quota): bool => ! $quota->hasRuns()),
        ];
    }
}
