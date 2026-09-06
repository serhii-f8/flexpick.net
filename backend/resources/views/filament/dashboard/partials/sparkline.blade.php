{{-- Score history on ScoreChartBuilder's fixed 0-100 axis: y = 34 - (score / 100) * 30.
     Gridlines sit on the 75 / 50 / 25 bands. --}}
@props(['points'])

@if (count($points) > 1)
    <svg viewBox="0 0 200 40" preserveAspectRatio="none" class="fp-sparkline" fill="none" aria-hidden="true">
        <line x1="0" y1="11.5" x2="200" y2="11.5" stroke="currentColor" stroke-width="0.5" class="fp-sparkline-grid" />
        <line x1="0" y1="19" x2="200" y2="19" stroke="currentColor" stroke-width="0.5" class="fp-sparkline-grid" />
        <line x1="0" y1="26.5" x2="200" y2="26.5" stroke="currentColor" stroke-width="0.5" class="fp-sparkline-grid" />

        @foreach ($points as $i => $point)
            @if ($i > 0)
                @php($previousPoint = $points[$i - 1])
                <line x1="{{ $previousPoint->x }}" y1="{{ $previousPoint->y }}" x2="{{ $point->x }}" y2="{{ $point->y }}" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" class="{{ $point->colorClass }}" />
            @endif

            <circle cx="{{ $point->x }}" cy="{{ $point->y }}" r="{{ $point->delta !== null ? min(4, 1.5 + abs($point->delta) / 10) : 1.5 }}" fill="currentColor" class="{{ $point->colorClass }}">
                <title>{{ $point->tooltip }}</title>
            </circle>
        @endforeach
    </svg>
@endif
