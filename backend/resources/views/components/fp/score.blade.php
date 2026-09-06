@props([
    'value' => null,
    'size' => 'lg',
    'caption' => true,
])

@php
    $band = is_int($value) ? \App\Support\ScoreBand::fromScore($value) : null;
@endphp

<div {{ $attributes->class(['fp-score', 'fp-score-'.$size, $band?->cssClass()]) }}>
    <span class="fp-score-value">{{ $band ? $value : '—' }}</span>
    @if ($caption && $band)
        <span class="fp-score-caption">{{ $band->caption() }}</span>
    @endif
</div>
