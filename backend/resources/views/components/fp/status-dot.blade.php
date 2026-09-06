@props([
    'color' => 'gray',
])

<span {{ $attributes->class(['fp-status-dot', 'fp-status-dot-'.$color]) }} aria-hidden="true"></span>
