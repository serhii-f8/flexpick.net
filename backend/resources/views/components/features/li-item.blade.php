<li {{ $attributes->merge(['class' => 'inline-flex items-start gap-2.5']) }}>
    <span class="fp-check" aria-hidden="true">✓</span>
    <span>{{ $slot }}</span>
</li>
