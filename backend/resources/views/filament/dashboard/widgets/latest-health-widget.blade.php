<x-filament-widgets::widget class="fi-wi-latest-health">
    <x-filament::section>
        @if ($state === 'empty')
            <div class="flex flex-col gap-4 py-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="max-w-md">
                    <x-fp.label>{{ __('Codebase health') }}</x-fp.label>
                    <h2 class="mt-2 font-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ __('No health report yet') }}
                    </h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Paste a repository URL and you will get a scored report with the risks that matter most, usually within the hour.') }}
                    </p>
                </div>
                <x-filament::button tag="a" href="{{ $runUrl }}" color="primary" icon="heroicon-o-play">
                    {{ __('Run your first audit') }}
                </x-filament::button>
            </div>
        @elseif ($state === 'pending')
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <x-fp.label>{{ __('Latest audit') }}</x-fp.label>
                    <p class="fp-repo mt-2 text-gray-950 dark:text-white">{{ $repo }}</p>
                    <p class="mt-3 flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                        <x-fp.status-dot :color="$statusColor" />
                        <span class="font-medium">{{ $statusLabel }}</span>
                        <span class="text-gray-500 dark:text-gray-400">· {{ __('submitted :when', ['when' => $submittedAt->diffForHumans()]) }}</span>
                    </p>
                    @if ($statusHint !== '')
                        <p class="mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ $statusHint }}</p>
                    @endif
                </div>
                <x-filament::button tag="a" href="{{ $viewUrl }}" color="gray" size="sm">
                    {{ __('View status') }}
                </x-filament::button>
            </div>
        @else
            <div class="flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
                <div class="min-w-0 flex-1">
                    <x-fp.label>{{ __('Codebase health') }}</x-fp.label>
                    <p class="fp-repo mt-2 text-gray-950 dark:text-white">{{ $repo }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $tier }} · {{ $completedAt->diffForHumans() }}
                    </p>

                    <div class="mt-5 flex flex-wrap items-end gap-x-4 gap-y-2">
                        <x-fp.score :value="$score" size="xl" />
                        @if ($delta !== null && $delta !== 0)
                            <span class="fp-delta {{ $delta > 0 ? 'fp-trend-up' : 'fp-trend-down' }}" title="{{ __('Change since the previous audit of this repository') }}">
                                {{ $delta > 0 ? '▲' : '▼' }} {{ sprintf('%+d', $delta) }} {{ __('since last audit') }}
                            </span>
                        @endif
                    </div>

                    @if ($risks->isNotEmpty())
                        <div class="fp-risk-mix mt-4 text-gray-700 dark:text-gray-300">
                            @foreach (['high' => 'danger', 'medium' => 'warning', 'low' => 'gray'] as $impact => $color)
                                @if ($risks->has($impact))
                                    <span><x-fp.status-dot :color="$color" /> {{ $risks[$impact] }} {{ __($impact) }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex w-full flex-col gap-3 md:w-64">
                    @if (count($chartPoints) > 1)
                        <div>
                            <x-fp.label>{{ __('Trend') }}</x-fp.label>
                            <div class="mt-2">
                                @include('filament.dashboard.partials.sparkline', ['points' => $chartPoints])
                            </div>
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button tag="a" href="{{ $reportUrl }}" target="_blank" rel="noopener" color="primary" size="sm">
                            {{ __('Open report') }}
                        </x-filament::button>
                        <x-filament::button tag="a" href="{{ $viewUrl }}" color="gray" size="sm">
                            {{ __('Details') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
