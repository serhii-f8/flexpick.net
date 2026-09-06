<x-layouts.focus-center>
    @php
        $planName = $newPlan->product->name;
        $intervalLabel = $newPlan->interval_count > 1
            ? __('per :count :interval', ['count' => $newPlan->interval_count, 'interval' => __(str($newPlan->interval->name)->plural())])
            : __('per :interval', ['interval' => __($newPlan->interval->name)]);
        $features = collect($newPlan->product->features ?? [])->pluck('feature')->filter()->values()->all();
    @endphp

    <div class="fp-checkout">
        <header class="fp-checkout-head">
            <p class="fp-eyebrow">{{ __('Change plan') }}</p>
            <h1 class="fp-pricing-title">{{ __('Switch to :plan', ['plan' => $planName]) }}</h1>
            <p class="fp-pricing-lead">{{ __('The new plan starts immediately. Cancel any time.') }}</p>
        </header>

        <div class="fp-checkout-grid">
            <section class="fp-panel">
                <div class="fp-panel-bar">
                    <span class="fp-mono-label">{{ __('What you get') }}</span>
                </div>
                <div class="fp-panel-body">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="fp-plan-card-name text-xl">{{ $planName }}</h2>
                        @if ($newPlan->has_trial)
                            <span class="fp-trial-pill">{{ $newPlan->trial_interval_count }} {{ $newPlan->trialInterval()->firstOrFail()->name }} {{ __('free trial') }}</span>
                        @endif
                    </div>
                    <p class="fp-plan-card-desc mt-1">
                        {{ ucfirst($newPlan->interval->adverb) }} {{ __('subscription') }} · {{ $intervalLabel }}
                    </p>

                    @if ($features !== [])
                        <ul class="fp-plan-card-features mt-4">
                            @foreach($features as $feature)
                                <li><span class="fp-check" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <aside class="fp-panel">
                <div class="fp-panel-bar">
                    <span class="fp-mono-label">{{ __('Order summary') }}</span>
                </div>
                <div class="fp-panel-body">
                    <div class="fp-checkout-rows">
                        @if ($subscription->plan?->product)
                            <div class="fp-checkout-row fp-checkout-row-muted">
                                <span>{{ __('Current plan') }}</span>
                                <span>{{ $subscription->plan->product->name }}</span>
                            </div>
                        @endif

                        @if ($newPlan->type === \App\Constants\PlanType::SEAT_BASED->value && $totals->basePrice !== null)
                            <div class="fp-checkout-row">
                                <span>{{ __('Base price') }} <span class="text-xs" style="color: var(--fp-muted)">({{ __('includes :count seats', ['count' => $totals->includedSeats]) }})</span></span>
                                <span>@money($totals->basePrice, $totals->currencyCode)</span>
                            </div>
                            @if ($totals->extraSeats > 0)
                                <div class="fp-checkout-row">
                                    <span>{{ __('Extra seats') }} <span class="text-xs" style="color: var(--fp-muted)">({{ $totals->extraSeats }} &times; @money($totals->extraSeatPrice, $totals->currencyCode))</span></span>
                                    <span>@money($totals->extraSeats * $totals->extraSeatPrice, $totals->currencyCode)</span>
                                </div>
                            @endif
                        @endif

                        <div class="fp-checkout-row">
                            <span>
                                {{ __('New plan') }}: {{ $planName }}
                                @if ($newPlan->type === \App\Constants\PlanType::SEAT_BASED->value && $totals->basePrice === null)
                                    <span class="text-xs" style="color: var(--fp-muted)">({{ $totals->quantity }} &times; @money($totals->pricePerSeat, $totals->currencyCode) / {{ __('seat') }})</span>
                                @endif
                            </span>
                            <span>@money($totals->subtotal, $totals->currencyCode) <span class="text-xs" style="color: var(--fp-muted)">{{ $intervalLabel }}</span></span>
                        </div>

                        @if (!$isProrated)
                            <div class="fp-checkout-row fp-checkout-row-total">
                                <span>{{ __('Due now') }}</span>
                                <span class="fp-checkout-amount">@money($totals->amountDue, $totals->currencyCode)</span>
                            </div>
                        @endif
                    </div>

                    @if ($isProrated)
                        <p class="fp-note">
                            {{ __('You will be charged a prorated amount that covers the difference between your current plan and your new plan for the remainder of the current billing period.') }}
                        </p>
                    @endif

                    <form action="" method="post" class="mt-4">
                        @csrf

                        <button type="submit" class="fp-btn fp-btn-primary">
                            {{ __('Confirm switch to :plan', ['plan' => $planName]) }}
                        </button>

                        <p class="fp-fine-print">
                            {{ __('Your plan renews automatically until you cancel. You can cancel from your subscription settings at least one day before each renewal date.') }}
                            {{ __('By continuing, you agree to our') }} <a href="{{ route('terms-of-service') }}">{{ __('Terms of Service') }}</a> {{ __('and') }} <a href="{{ route('privacy-policy') }}">{{ __('Privacy Policy') }}</a>.
                        </p>
                    </form>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.focus-center>
