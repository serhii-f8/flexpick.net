@php
    $intervals = array_keys($groupedPlans);
    $activeInterval = $preselectedInterval ?: ($intervals[0] ?? '');
@endphp

@if ($plans->isEmpty())
    <div class="fp-panel mx-auto max-w-2xl text-center" style="padding: 48px 32px;">
        <p class="m-0">{{ __('Nothing available yet — check back soon.') }}</p>
    </div>
@else
@isset($warnBeforePlanPurchase)
    <div class="fp-notice-warning">
        <x-fp.status-dot color="warning" class="mt-1.5" />
        <p class="m-0">
            <strong>{{ __('Buying a plan here starts a brand-new workspace.') }}</strong>
            {{ __("Your current plan is managed by your partner and can't be changed from here — contact them to upgrade it instead. This page is still fine for one-time report packages.") }}
        </p>
    </div>
@endisset
@if (count($groupedPlans) === 0)
    <div class="fp-plan-grid">
        @foreach($plans as $plan)
            <x-plans.one :plan="$plan" />
        @endforeach
    </div>
@else
    <div x-data="{ interval: @js($activeInterval) }">
        @if (count($intervals) > 1)
            <div class="fp-pricing-switch">
                <div class="fp-switch" role="tablist" aria-label="{{ __('Billing interval') }}">
                    @foreach($groupedPlans as $interval => $intervalPlans)
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="interval === @js($interval) ? 'true' : 'false'"
                            aria-controls="pricing-{{ $interval }}"
                            @click="interval = @js($interval)"
                        >
                            {{ ucfirst(__($intervalPlans[0]?->interval?->adverb)) }}
                            @if(isset($intervalSavingPercentage[$interval]) && $intervalSavingPercentage[$interval] > 0)
                                <span class="fp-switch-saving">{{ __('save :percent%', ['percent' => $intervalSavingPercentage[$interval]]) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach($groupedPlans as $interval => $intervalPlans)
            <div id="pricing-{{ $interval }}" role="tabpanel" x-show="interval === @js($interval)" @if ($interval !== $activeInterval) x-cloak @endif>
                @foreach($tierSections[$interval] as $section)
                    @if($section['title'] !== null)
                        <div class="fp-tier-heading">
                            <h3 class="fp-pricing-section-title">{{ __($section['title']) }}</h3>
                            <p class="fp-tier-headline">{{ __($section['headline']) }}</p>
                        </div>
                    @endif
                    <div class="fp-plan-grid">
                        @foreach($section['plans'] as $plan)
                            <x-plans.one :plan="$plan" />
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
@endif

@isset($defaultProduct)
    <x-plans.default-product :product="$defaultProduct" />
@endisset
