@php
    $intervals = array_keys($groupedPlans);
    $activeInterval = $preselectedInterval ?: ($intervals[0] ?? '');
@endphp

@if ($plans->isEmpty())
    <div class="fp-panel mx-auto max-w-2xl text-center" style="padding: 48px 32px;">
        <p class="m-0">{{ __('Nothing available yet — check back soon.') }}</p>
    </div>
@else
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

@if (isset($defaultProduct))
    <div class="fp-panel mt-8 mx-auto max-w-3xl">
        <div class="fp-panel-body text-center">
            <h3 class="fp-pricing-section-title">{{ __('Start for free') }}</h3>
            <p class="mt-2 text-sm" style="color: var(--fp-muted)">{{ __('Start now and upgrade as you go. No credit card required.') }}</p>
            @if($defaultProduct->features)
                <ul class="fp-plan-card-features mx-auto max-w-md border-0 pt-2">
                    @foreach($defaultProduct->features as $feature)
                        <li><span class="fp-check" aria-hidden="true">✓</span><span>{{$feature['feature']}}</span></li>
                    @endforeach
                </ul>
            @endif
            <a href="{{route('plan.start')}}" class="fp-btn fp-btn-primary mt-5 w-auto px-8">{{ __('Start now') }}</a>
        </div>
    </div>
@endif
