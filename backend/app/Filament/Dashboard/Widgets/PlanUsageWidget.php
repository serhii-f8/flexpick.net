<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\Subscription;
use App\Services\AuditReport\AuditEntitlementService;
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

        $allowance = $tenant !== null ? $entitlements->subscriptionAllowance($tenant) : 0;
        $deepAiCredits = $tenant !== null ? $entitlements->deepAiCredits($tenant) : 0;

        $bars = [];

        if ($allowance > 0) {
            $bars[] = [
                'label' => __('Analyses this month'),
                'used' => $entitlements->dashboardRunsUsedThisMonth($user),
                'total' => $allowance,
                'color' => 'bg-primary-500',
            ];

            // Hidden entirely at zero, matching how the stats widget already
            // treats Deep AI: a plan without credits should not advertise them.
            if ($deepAiCredits > 0) {
                $bars[] = [
                    'label' => __('Deep AI credits'),
                    'used' => $entitlements->deepAiRunsUsedThisMonth($user),
                    'total' => $deepAiCredits,
                    'color' => 'bg-secondary-500',
                ];
            }
        } else {
            $bars[] = [
                'label' => __('Free audits'),
                'used' => $entitlements->freeRunsUsed($user->email),
                'total' => $entitlements->freeRunsLimit($user->email),
                'color' => 'bg-primary-500',
            ];
        }

        return [
            'planName' => $subscription?->plan?->name ?? __('Free'),
            'renewsAt' => $subscription?->ends_at ? Carbon::parse($subscription->ends_at) : null,
            'bars' => $bars,
            'showUpgrade' => $tenant === null
                || $allowance === 0
                || $entitlements->remainingDashboardRuns($user, $tenant) === 0,
        ];
    }
}
