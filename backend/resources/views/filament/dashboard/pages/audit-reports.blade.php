<x-filament-panels::page>
    @if ($canRun)
        <x-filament::section class="mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow">
                    <label class="text-sm font-medium" for="audit-repo-url">{{ __('Repository URL') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="audit-repo-url" type="url" wire:model="repoUrl" placeholder="https://github.com/you/repo" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button wire:click="launchAudit">{{ __('Run new audit') }}</x-filament::button>
            </div>
            <p class="mt-2 text-sm text-gray-500">
                @if ($allowance > 0)
                    {{ __(':remaining of :allowance analyses left this month', ['remaining' => $remainingRuns, 'allowance' => $allowance]) }}
                @else
                    {{ __(':remaining free audits remaining', ['remaining' => $freeRunsRemaining]) }}
                @endif
            </p>
        </x-filament::section>
    @endif

    @forelse ($repoGroups as $repoUrl => $group)
        @php
            $current = $group['scores']->last();
            $delta = $deltas[rtrim($repoUrl, '/')] ?? null;
            $scoreColor = $current >= 70 ? 'text-success-500' : ($current >= 50 ? 'text-warning-500' : 'text-danger-500');
        @endphp

        <x-filament::section class="mb-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-medium">{{ $repoUrl }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ trans_choice('{1} :count audit|[2,*] :count audits', $group['reports']->count(), ['count' => $group['reports']->count()]) }}
                        · {{ $group['reports']->first()->created_at->diffForHumans() }}
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-2xl font-bold {{ $scoreColor }}">{{ $current }}</p>
                    @if ($delta !== null && $delta !== 0)
                        <p class="text-xs {{ $delta > 0 ? 'text-success-500' : 'text-danger-500' }}">
                            {{ sprintf('%+d', $delta) }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Only meaningful with history; single-audit repos show a score and no chart. --}}
            @if ($group['scores']->count() > 1)
                @php
                    $scores = $group['scores'];
                    $max = max(1, $scores->max());
                    $step = 200 / max(1, $scores->count() - 1);
                    $points = $scores->map(fn ($s, $i) => round($i * $step, 2).','.round(34 - ($s / $max) * 30, 2))->implode(' ');
                @endphp
                <svg viewBox="0 0 200 40" class="mt-3 h-10 w-full" fill="none" aria-hidden="true">
                    <polyline points="{{ $points }}" stroke="currentColor" stroke-width="2" class="text-primary-500" />
                </svg>
            @endif

            @if ($allowance > 0)
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select
                        class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        wire:change="setSchedule('{{ $repoUrl }}', $event.target.value)"
                        aria-label="{{ __('Audit schedule for :repo', ['repo' => $repoUrl]) }}"
                    >
                        @foreach (['off' => __('No schedule'), 'weekly' => __('Audit weekly'), 'monthly' => __('Audit monthly')] as $value => $optionLabel)
                            <option value="{{ $value }}" @selected(($schedules[rtrim($repoUrl, '/')] ?? 'off') === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>

                    <x-filament::button size="sm" color="gray" wire:click="launchAudit('{{ $repoUrl }}')">
                        {{ __('Re-run') }}
                    </x-filament::button>
                </div>
            @endif

            <div class="mt-4 divide-y divide-gray-200 border-t border-gray-200 pt-2 dark:divide-gray-700 dark:border-gray-700">
                @foreach ($group['reports'] as $report)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $report->created_at->format(config('app.datetime_format', 'd/m/Y H:i')) }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">{{ data_get($report->payload, 'scores.overall', '—') }}</span>
                            <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', $report) }}">
                                {{ __('PDF') }}
                            </x-filament::button>
                            <x-filament::button tag="a" size="xs" color="primary" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">
                                {{ __('View') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <div class="py-8 text-center">
                <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="mx-auto h-10 w-10 text-gray-400" />
                <p class="mt-2 font-medium">{{ __('No audit reports yet') }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter a repository URL above to run your first audit.') }}
                </p>
            </div>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
