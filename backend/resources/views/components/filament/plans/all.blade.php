@props([
    'buyRoute' => 'subscription.change-plan',
])

@php
    $intervals = array_keys($groupedPlans);
    $activeInterval = $preselectedInterval ?: ($intervals[0] ?? '');
    $currentPrice = isset($subscription) && $subscription !== null ? app(\App\Services\PlanService::class)->getPlanPrice($subscription->plan) : null;
@endphp

<section class="fp-plan-picker">
    @if(isset($subscription) && $subscription !== null)
        <div class="fp-current-strip mb-6">
            <span class="fp-mono-label">{{ __('Current plan') }}</span>
            <strong>{{ $subscription->plan->product->name }}</strong>
            @if ($currentPrice)
                <span>@money($currentPrice->price, $currentPrice->currency->code) / {{ __($subscription->plan->interval->name) }}</span>
            @endif
            @if ($subscription->ends_at)
                <span style="color: var(--fp-muted)">{{ __('Renews :date', ['date' => \Illuminate\Support\Carbon::parse($subscription->ends_at)->format(config('app.date_format', 'd/m/Y'))]) }}</span>
            @endif
        </div>
    @endif

    @if ($plans->isEmpty())
        <div class="fp-panel mx-auto max-w-2xl text-center" style="padding: 48px 32px;">
            <p class="m-0">{{ __('Nothing available yet — check back soon.') }}</p>
        </div>
    @else
    @if($isGrouped)
        <div x-data="{ interval: @js($activeInterval) }">
            @if (count($intervals) > 1)
                <div class="fp-pricing-switch">
                    <div class="fp-switch" role="tablist" aria-label="{{ __('Billing interval') }}">
                        @foreach($groupedPlans as $interval => $intervalPlans)
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="interval === @js($interval) ? 'true' : 'false'"
                                aria-controls="plans-{{ $interval }}"
                                @click="interval = @js($interval)"
                            >{{ str($interval)->title() }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach($groupedPlans as $interval => $intervalPlans)
                <div id="plans-{{ $interval }}" role="tabpanel" x-show="interval === @js($interval)" @if ($interval !== $activeInterval) x-cloak @endif>
                    @foreach($tierSections[$interval] as $section)
                        @if($section['title'] !== null)
                            <div class="fp-tier-heading">
                                <h3 class="fp-pricing-section-title">{{ __($section['title']) }}</h3>
                                <p class="fp-tier-headline">{{ __($section['headline']) }}</p>
                            </div>
                        @endif
                        <div class="fp-plan-grid">
                            @foreach($section['plans'] as $plan)
                                <x-filament.plans.one :plan="$plan" :subscription="$subscription" :buyRoute="$buyRoute" />
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        <div class="fp-plan-grid">
            @foreach($plans as $plan)
                <x-filament.plans.one :plan="$plan" :subscription="$subscription" :buyRoute="$buyRoute"/>
            @endforeach
        </div>
    @endif
    @endif
</section>
