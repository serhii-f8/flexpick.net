@php
    $presenter = app(\App\Services\AuditReport\ReportPresenter::class);
    $deep = $presenter->deepReviewMeta($payload);
    $byFile = $presenter->findingsByFile($payload);

    $badge = [
        'critical' => 'bg-red-100 text-red-900',
        'high' => 'bg-red-50 text-red-800',
        'medium' => 'bg-amber-50 text-amber-800',
        'low' => 'bg-lime-50 text-lime-800',
        'info' => 'bg-sky-50 text-sky-800',
    ];
@endphp

@if ($deep !== null)
    <h2 class="mb-3 text-base font-bold">{{ __('Deep file review') }}</h2>

    @if ($deep['degraded'] ?? false)
        <p class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('The deep review could not be completed for this run. The automated analysis in this report is complete, and we have been notified.') }}
        </p>
    @else
        @if ($deep['truncated'] ?? false)
            <p class="text-xs text-stone-500">
                {{ __('Reviewed :reviewed of :selected selected files, in risk order.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                    'selected' => $deep['files_selected'] ?? 0,
                ]) }}
            </p>
        @else
            <p class="text-xs text-stone-500">
                {{ __('Reviewed :reviewed files, selected as the riskiest in this repository.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @endif

        @if ($byFile->isEmpty())
            {{-- P6: a healthy verdict is a designed outcome, not an empty state. --}}
            <p class="mt-3 text-sm">
                {{ __('No file-level issues were found across the :count files reviewed. The riskiest parts of this codebase hold up to close reading.', [
                    'count' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @else
            @foreach ($byFile as $path => $findings)
                <div class="mt-5 border-t border-stone-200 pt-4">
                    <p class="break-all font-mono text-sm font-semibold">{{ $path }}</p>

                    @foreach ($findings as $finding)
                        <div class="mt-3 border-l-2 border-stone-200 pl-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $badge[$finding['severity']] ?? 'bg-stone-100 text-stone-700' }}">
                                    {{ $finding['severity'] }}
                                </span>
                                <span class="font-semibold">{{ $finding['title'] }}</span>
                                @if (($finding['line'] ?? null) !== null)
                                    <span class="text-xs text-stone-500">{{ __('line :line', ['line' => $finding['line']]) }}</span>
                                @endif
                            </div>

                            @if ($unlocked)
                                <div class="mt-2 space-y-1 text-sm text-stone-700">
                                    <div>{{ $finding['evidence'] }}</div>
                                    <div><strong>{{ __('Fix') }}:</strong> {{ $finding['recommendation'] }}</div>
                                    <div class="text-xs text-stone-500">{{ __('Effort: :effort', ['effort' => $finding['effort']]) }}</div>

                                    @if (($finding['related_paths'] ?? []) !== [])
                                        <div class="text-xs text-stone-500">
                                            {{ __('Also involves') }}: {{ implode(', ', $finding['related_paths']) }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    @endif
@endif
