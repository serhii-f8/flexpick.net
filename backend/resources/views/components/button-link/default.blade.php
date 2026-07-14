@props(['elementType' => 'a', 'isDisabled' => false])

@php
    $class = 'inline-block cursor-pointer leading-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500/70 rounded-lg text-sm font-semibold px-5 py-2.5 text-center transition ';
@endphp

@if($elementType === 'a')
<a
    {{ $attributes->merge(['class' => $class]) }}
    {{ $attributes }}
    {{ $isDisabled ? 'disabled' : '' }}
>
    {{ $slot }}
</a>
@else
<button
    {{ $attributes->merge(['class' => $class]) }}
    {{ $attributes }}
    {{ $isDisabled ? 'disabled' : '' }}
>
    {{ $slot }}
</button>
@endif
