<x-button-link.default
    {{ $attributes }}
    {{ $attributes->merge(['class' => 'text-cream-100 bg-transparent border border-white/15 hover:border-primary-500/60']) }}
>
    {{ $slot }}
</x-button-link.default>
