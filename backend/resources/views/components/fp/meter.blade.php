@props([
    'label',
    'value' => null,
])

@php
    $score = is_int($value) ? max(0, min(100, $value)) : null;
    $band = $score !== null ? \App\Support\ScoreBand::fromScore($score) : null;
@endphp

<div {{ $attributes->class(['fp-meter', $band?->cssClass()]) }}>
    <div class="fp-meter-head">
        <span class="fp-meter-label">{{ $label }}</span>
        <span class="fp-meter-value">{{ $score ?? '—' }}</span>
    </div>
    <div
        class="fp-meter-track"
        role="meter"
        aria-label="{{ $label }}"
        aria-valuenow="{{ $score ?? 0 }}"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <div class="fp-meter-fill" style="width: {{ $score ?? 0 }}%"></div>
    </div>
</div>
