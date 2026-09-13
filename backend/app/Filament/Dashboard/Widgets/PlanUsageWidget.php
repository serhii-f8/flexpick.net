<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
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

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 1];

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check() && $tenant !== null && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        /** @var Subscription|null $subscription */
        $subscription = app(SubscriptionService::class)
            ->findActiveTenantSubscriptions($tenant)
            ->first();

        $quotas = $entitlements->quotas($tenant);
        $metered = collect($quotas)->reject(fn (TierQuota $quota): bool => $quota->isLifetime);

        $bars = [];

        foreach ($metered->values() as $quota) {
            // Hidden entirely at zero: a plan without credits for a tier
            // should not advertise them.
            if ($quota->limit < 1) {
                continue;
            }

            $bars[] = [
                'label' => $quota->label,
                'used' => $quota->used,
                'total' => $quota->limit,
            ];
        }

        if ($bars === []) {
            $free = collect($quotas)->firstWhere(fn (TierQuota $quota): bool => $quota->isLifetime);

            // Only worth a bar if there was ever a free allotment to show --
            // at the production default (limit 0) a fresh signup never had
            // one, so "Free audits -- 0 of 0 used" would misreport "used up"
            // for someone who was never offered any. The upgrade button
            // above (still shown via $showUpgrade below) is their CTA.
            if ($free !== null && $free->limit > 0) {
                $bars[] = [
                    'label' => __('Free audits'),
                    'used' => $free->used,
                    'total' => $free->limit,
                ];
            }
        }

        return [
            'planName' => $subscription?->plan?->name ?? __('Free'),
            'renewsAt' => $subscription?->ends_at ? Carbon::parse($subscription->ends_at) : null,
            'bars' => $bars,
            'changePlanUrl' => $subscription !== null && app(SubscriptionService::class)->canChangeSubscriptionPlan($subscription)
                ? SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid])
                : null,
            // Show it when there is no paid allowance at all (a free user
            // who hasn't burned their runs yet still needs the conversion
            // surface), or when everything -- free and paid alike -- is
            // spent.
            'showUpgrade' => $metered->every(fn (TierQuota $quota): bool => $quota->limit < 1)
                || collect($quotas)->every(fn (TierQuota $quota): bool => ! $quota->hasRuns()),
        ];
    }
}
