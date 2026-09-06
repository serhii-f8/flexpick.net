<div class="fp-timeline">
    <p class="fp-timeline-status">
        <x-fp.status-dot :color="$statusColor" />
        <span class="font-medium text-gray-950 dark:text-white">{{ $statusLabel }}</span>
    </p>
    @if ($statusHint !== '')
        <p class="fp-timeline-hint {{ $blocked ? 'fp-timeline-hint-blocked' : '' }}">{{ $statusHint }}</p>
    @endif
    @if ($failureReason)
        <p class="fp-timeline-failure">{{ $failureReason }}</p>
    @endif

    <ol class="fp-timeline-steps">
        @foreach ($steps as $step)
            <li class="fp-timeline-step fp-timeline-step-{{ $step['state'] }}">
                <span class="fp-timeline-marker" aria-hidden="true"></span>
                <span class="fp-timeline-label">{{ $step['label'] }}</span>
                <span class="fp-timeline-when">
                    @if ($step['at'])
                        {{ $step['at']->format(config('app.datetime_format', 'd/m/Y H:i')) }}
                    @elseif ($step['state'] === 'current')
                        {{ __('now') }}
                    @elseif ($step['state'] === 'failed')
                        {{ __('failed') }}
                    @elseif ($step['state'] === 'skipped')
                        —
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
</div>
