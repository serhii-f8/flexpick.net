@php
    $deep = $payload['deep_review'] ?? null;

    $severityRank = ['critical' => 5, 'high' => 4, 'medium' => 3, 'low' => 2, 'info' => 1];

    // Grouped by file; files ordered by their worst finding, findings within a
    // file by severity, then line. A customer opens one file and sees
    // everything wrong with it instead of jumping around a flat severity list.
    $byFile = collect($payload['file_findings'] ?? [])
        // Arrays compare element-wise in PHP, so negating the rank sorts
        // severity descending and line ascending in one pass.
        ->sortBy(fn (array $f) => [-($severityRank[$f['severity']] ?? 0), $f['line'] ?? 0])
        ->groupBy('path')
        ->sortByDesc(fn ($findings) => $findings->max(fn (array $f) => $severityRank[$f['severity']] ?? 0));
@endphp

@if ($deep !== null)
    <h2>{{ __('Deep file review') }}</h2>

    @if ($deep['degraded'] ?? false)
        <p class="deep-notice">
            {{ __('The deep review could not be completed for this run. The automated analysis in this report is complete, and we have been notified.') }}
        </p>
    @else
        @if ($deep['truncated'] ?? false)
            <p class="muted">
                {{ __('Reviewed :reviewed of :selected selected files, in risk order.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                    'selected' => $deep['files_selected'] ?? 0,
                ]) }}
            </p>
        @else
            <p class="muted">
                {{ __('Reviewed :reviewed files, selected as the riskiest in this repository.', [
                    'reviewed' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @endif

        @if ($byFile->isEmpty())
            {{-- P6: a healthy verdict is a designed outcome, not an empty state. --}}
            <p>
                {{ __('No file-level issues were found across the :count files reviewed. The riskiest parts of this codebase hold up to close reading.', [
                    'count' => $deep['files_reviewed'] ?? 0,
                ]) }}
            </p>
        @else
            @foreach ($byFile as $path => $findings)
                <div class="deep-file">
                    <div class="risk-title">{{ $path }}</div>

                    @foreach ($findings as $finding)
                        <div class="risk">
                            <div class="risk-head">
                                <span class="badge badge-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span>
                                <span class="risk-title">{{ $finding['title'] }}</span>
                                @if (($finding['line'] ?? null) !== null)
                                    <span class="muted">{{ __('line :line', ['line' => $finding['line']]) }}</span>
                                @endif
                            </div>

                            @if ($unlocked)
                                <div class="risk-detail">
                                    <div>{{ $finding['evidence'] }}</div>
                                    <div><strong>{{ __('Fix') }}:</strong> {{ $finding['recommendation'] }}</div>
                                    <div class="muted">{{ __('Effort: :effort', ['effort' => $finding['effort']]) }}</div>

                                    @if (($finding['related_paths'] ?? []) !== [])
                                        <div class="muted">
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
