@props([
    'subscription' => null,
    'buyRoute' => 'subscription.change-plan',
    'plan',
])

@inject('planService', 'App\Services\PlanService')

@php
    $price = $planService->getPlanPrice($plan);
    $effectivePrice = $price !== null ? ($plan->partner_price ?? $price->price) : null;
    $tenant = \Filament\Facades\Filament::getTenant();
    $tenantUserCount = $tenant ? $tenant->users()->count() : 0;
    $exceedsMaxUsers = $plan->max_users_per_tenant > 0 && $tenantUserCount > $plan->max_users_per_tenant;
    $isCurrent = $subscription !== null && $subscription->plan_id === $plan->id;
    $intervalLabel = $plan->interval_count > 1
        ? __('per :count :interval', ['count' => $plan->interval_count, 'interval' => __(str($plan->interval->name)->plural())])
        : __('per :interval', ['interval' => __($plan->interval->name)]);
    if ($plan->type === \App\Constants\PlanType::SEAT_BASED->value) {
        $intervalLabel = __('per seat').' · '.$intervalLabel;
    }
    $features = collect($plan->product->features ?? [])->pluck('feature')->filter()->values()->all();
@endphp

<x-fp.plan-card
    :name="$plan->product->name"
    :description="$plan->product->description"
    :price="$effectivePrice !== null && $effectivePrice > 0 ? money($effectivePrice, $price->currency->code) : null"
    :interval="$effectivePrice !== null && $effectivePrice > 0 ? $intervalLabel : null"
    :features="$features"
    :popular="(bool) $plan->product->is_popular"
    :current="$isCurrent"
    :partner="$plan->partner_price !== null ? $plan->partner_tenant_name : null"
    :href="route($buyRoute, ['planSlug' => $plan->slug, 'subscriptionUuid' => $subscription?->uuid, 'tenantUuid' => \Filament\Facades\Filament::getTenant()->uuid])"
    :cta="__('Switch to :plan', ['plan' => $plan->product->name])"
    :disabled="$exceedsMaxUsers"
    :disabled-reason="$exceedsMaxUsers ? __('This plan supports a maximum of :max users, but your workspace currently has :count users. Please remove :excess user(s) before switching to this plan.', ['max' => $plan->max_users_per_tenant, 'count' => $tenantUserCount, 'excess' => $tenantUserCount - $plan->max_users_per_tenant]) : null"
>
    @if($price !== null && $plan->type === \App\Constants\PlanType::SEAT_BASED->value && $price->type === \App\Constants\PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value)
        <p class="fp-plan-card-desc">{{ __('Includes :count seats, +:price/extra seat', ['count' => $price->included_seats, 'price' => money($price->extra_seat_price, $price->currency->code)]) }}</p>
    @endif
    @if($price?->type === \App\Constants\PlanPriceType::USAGE_BASED_PER_UNIT->value)
        <p class="fp-plan-card-desc">+ @money($price->price_per_unit, $price->currency->code) / {{ __($plan->meter?->name) }}</p>
    @elseif($price?->type === \App\Constants\PlanPriceType::USAGE_BASED_TIERED_GRADUATED->value
            || $price?->type === \App\Constants\PlanPriceType::USAGE_BASED_TIERED_VOLUME->value)
        <div class="fp-plan-card-desc">
            @php $start = 0; $startingPhrase = __('From'); @endphp
            @foreach($price->tiers as $tier)
                <p class="m-0 mt-1">
                    {{ $startingPhrase }} {{ $start }}–{{ $tier[\App\Constants\PlanPriceTierConstants::UNTIL_UNIT] }} {{ __(strtolower(str()->plural($plan->meter->name))) }}:
                    @money($tier[\App\Constants\PlanPriceTierConstants::PER_UNIT], $price->currency->code) / {{ __($plan->meter->name) }}
                    @if ($tier[\App\Constants\PlanPriceTierConstants::FLAT_FEE] > 0)
                        + @money($tier['flat_fee'], $price->currency->code)
                    @endif
                </p>
                @php $start = intval($tier[\App\Constants\PlanPriceTierConstants::UNTIL_UNIT]) + 1; @endphp
                @if($price->type === \App\Constants\PlanPriceType::USAGE_BASED_TIERED_GRADUATED->value)
                    @php $startingPhrase = __('Next'); @endphp
                @endif
            @endforeach
        </div>
    @endif
</x-fp.plan-card>
