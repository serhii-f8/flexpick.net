{{--
    One plan (or one-off product) as a brand card. Used by the public pricing
    page and by the panel's change-plan page, so both render the same thing.
--}}
@props([
    'name',
    'price' => null,
    'interval' => null,
    'features' => [],
    'description' => null,
    'popular' => false,
    'current' => false,
    'partner' => null,
    'href' => null,
    'cta' => null,
    'disabled' => false,
    'disabledReason' => null,
])

<article {{ $attributes->class(['fp-plan-card', 'fp-plan-card-popular' => $popular, 'fp-plan-card-current' => $current]) }}>
    @if ($popular || $current)
        <div class="fp-plan-card-tags">
            @if ($current)
                <span class="fp-plan-tag fp-plan-tag-current">{{ __('Current plan') }}</span>
            @elseif ($popular)
                <span class="fp-plan-tag">{{ __('Most popular') }}</span>
            @endif
        </div>
    @endif

    <h3 class="fp-plan-card-name">{{ $name }}</h3>

    @if ($description)
        <p class="fp-plan-card-desc">{{ $description }}</p>
    @endif

    @if ($price !== null)
        <p class="fp-plan-card-price">
            <span class="fp-plan-card-amount">{{ $price }}</span>
            @if ($interval)
                <span class="fp-plan-card-interval">{{ $interval }}</span>
            @endif
        </p>
    @endif

    @if ($partner)
        <p class="fp-plan-card-partner">{{ __('Sold through :partner', ['partner' => $partner]) }}</p>
    @endif

    {{ $slot }}

    @if ($features !== [])
        <ul class="fp-plan-card-features">
            @foreach ($features as $feature)
                <li><span class="fp-check" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>
            @endforeach
        </ul>
    @endif

    <div class="fp-plan-card-cta">
        @if ($current)
            <span class="fp-btn fp-btn-ghost fp-btn-static">{{ __('Your plan') }}</span>
        @elseif ($disabled)
            <span class="fp-btn fp-btn-disabled" aria-disabled="true">{{ $cta }}</span>
            @if ($disabledReason)
                <p class="fp-plan-card-reason">{{ $disabledReason }}</p>
            @endif
        @elseif ($href && $cta)
            <a class="fp-btn {{ $popular ? 'fp-btn-primary' : 'fp-btn-ghost' }}" href="{{ $href }}">{{ $cta }}</a>
        @endif
    </div>
</article>
