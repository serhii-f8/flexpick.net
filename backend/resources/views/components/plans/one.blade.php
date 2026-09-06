@props(['plan'])

@inject('planService', 'App\Services\PlanService')

@php
    $price = $planService->getPlanPrice($plan);
    $effectivePrice = $price !== null ? ($plan->partner_price ?? $price->price) : null;
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
    :price="$effectivePrice !== null ? money($effectivePrice, $price->currency->code) : null"
    :interval="$effectivePrice !== null ? $intervalLabel : null"
    :features="$features"
    :popular="(bool) $plan->product->is_popular"
    :partner="$plan->partner_price !== null ? $plan->partner_tenant_name : null"
    :href="route('checkout.subscription', $plan->slug)"
    :cta="__('Choose :plan', ['plan' => $plan->product->name])"
>
    @if($price !== null && $plan->type === \App\Constants\PlanType::SEAT_BASED->value && $price->type === \App\Constants\PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value)
        <p class="fp-plan-card-desc">{{ __('Includes :count seats, +:price/extra seat', ['count' => $price->included_seats, 'price' => money($price->extra_seat_price, $price->currency->code)]) }}</p>
    @endif
    @if(($price?->setup_fee ?? 0) > 0)
        <p class="fp-plan-card-desc">+ @money($price->setup_fee, $price->currency->code) {{ __('setup fee') }}</p>
    @endif

    {{-- $price is null when the plan has no price row in the store currency, and
         plans.meter_id is nullable at the DB level (only the admin form enforces
         the invariant) — both are null-safe here. --}}
    @if($price?->type === \App\Constants\PlanPriceType::USAGE_BASED_PER_UNIT->value)
        <p class="fp-plan-card-desc">+ @money($price->price_per_unit, $price->currency->code) / {{ __($plan->meter?->name) }}</p>
    @elseif($price?->type === \App\Constants\PlanPriceType::USAGE_BASED_TIERED_GRADUATED->value
            || $price?->type === \App\Constants\PlanPriceType::USAGE_BASED_TIERED_VOLUME->value)
        <div class="fp-plan-card-desc">
            @php $start = 0; $startingPhrase = __('From'); @endphp
            @foreach($price->tiers as $tier)
                <p class="m-0 mt-1">
                    {{ $startingPhrase }} {{ $start }}–{{ $tier[\App\Constants\PlanPriceTierConstants::UNTIL_UNIT] }} {{ __(strtolower(str()->plural($plan->meter?->name ?? ''))) }}:
                    @money($tier[\App\Constants\PlanPriceTierConstants::PER_UNIT], $price->currency->code) / {{ __($plan->meter?->name) }}
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
