<div class="fp-results">
    <div class="fp-results-head">
        <x-fp.score :value="$overall" size="xl" />
    </div>

    @if (is_string($summary) && $summary !== '')
        <p class="fp-results-summary">{{ $summary }}</p>
    @endif

    @if ($categories->isNotEmpty())
        <div class="fp-results-meters">
            @foreach ($categories as $label => $value)
                <x-fp.meter :label="$label" :value="$value" />
            @endforeach
        </div>
    @endif

    <div class="fp-results-foot">
        <div>
            <x-fp.label>{{ __('Risks') }}</x-fp.label>
            @if ($risks->isEmpty())
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('None found') }}</p>
            @else
                <div class="fp-risk-mix mt-1.5 text-gray-700 dark:text-gray-300">
                    @foreach (['high' => 'danger', 'medium' => 'warning', 'low' => 'gray'] as $impact => $color)
                        @if ($risks->has($impact))
                            <span><x-fp.status-dot :color="$color" /> {{ $risks[$impact] }} {{ __($impact) }}</span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        @if ($fixFirst->isNotEmpty())
            <div>
                <x-fp.label>{{ __('Fix first') }}</x-fp.label>
                <ol class="fp-fix-first mt-1.5">
                    @foreach ($fixFirst as $step)
                        <li>
                            <span>{{ $step['step'] ?? '' }}</span>
                            @if (isset($step['effort']))
                                <span class="fp-effort" title="{{ __('Effort') }}">{{ $step['effort'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
</div>
