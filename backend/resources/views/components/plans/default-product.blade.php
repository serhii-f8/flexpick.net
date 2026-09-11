@if ($product)
    <div class="fp-panel mt-8 mx-auto max-w-3xl">
        <div class="fp-panel-body text-center">
            <h3 class="fp-pricing-section-title">{{ __('Start for free') }}</h3>
            <p class="mt-2 text-sm" style="color: var(--fp-muted)">{{ __('Start now and upgrade as you go. No credit card required.') }}</p>
            @if($product->features)
                <ul class="fp-plan-card-features mx-auto max-w-md border-0 pt-2">
                    @foreach($product->features as $feature)
                        <li><span class="fp-check" aria-hidden="true">✓</span><span>{{$feature['feature']}}</span></li>
                    @endforeach
                </ul>
            @endif
            <a href="{{route('plan.start')}}" class="fp-btn fp-btn-primary mt-5 w-auto px-8">{{ __('Start now') }}</a>
        </div>
    </div>
@endif
