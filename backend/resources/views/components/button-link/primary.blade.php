<x-button-link.default
    {{ $attributes }}
    {{ $attributes->merge(['class' => 'text-ink bg-primary-500 hover:bg-primary-400']) }}
>
    {{ $slot }}
</x-button-link.default>
