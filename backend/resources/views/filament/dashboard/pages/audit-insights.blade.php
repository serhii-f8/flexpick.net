<x-filament-panels::page>
    <p class="text-sm" style="color: var(--fp-muted)">
        {{ __('Anonymized statistics across every codebase audited on the platform. Use them in your own pitches, posts and proposals — they contain aggregated numbers only, never a client, repository or file.') }}
    </p>

    @if ($stats === null)
        <x-filament::section>
            <p class="text-sm">
                {{ __('Not enough audits yet to publish statistics without describing individual clients. They appear here once :count more codebases have been audited.', ['count' => $codebasesNeeded]) }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('Most common issues')" :description="__('Share of audited codebases where each issue was found')">
            <div class="flex flex-col gap-4">
                @foreach ($stats['top_issues'] as $issue)
                    <div class="fp-meter">
                        <div class="fp-meter-head">
                            <span class="fp-meter-label">{{ $issue['label'] }}</span>
                            <span class="fp-meter-value">{{ $issue['pct'] }}%</span>
                        </div>
                        <div class="fp-meter-track" role="meter" aria-label="{{ $issue['label'] }}" aria-valuenow="{{ $issue['pct'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="fp-meter-fill" style="width: {{ $issue['pct'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Ready to quote')" :description="__('Copy these as they are, or reword them — the numbers are what matter.')">
            @php
                $metrics = $stats['metrics'];
                $quotes = array_filter([
                    $metrics['secrets_pct'] !== null
                        ? __(':pct% of the codebases we audit have API keys or other secrets committed to the code.', ['pct' => $metrics['secrets_pct']]) : null,
                    $metrics['no_tests_pct'] !== null
                        ? __(':pct% have no automated tests at all, and :thin% have tests for fewer than one file in ten.', ['pct' => $metrics['no_tests_pct'], 'thin' => $metrics['thin_tests_pct']]) : null,
                    $metrics['vulnerable_dependencies_pct'] !== null
                        ? __(':pct% depend on at least one package with a publicly known vulnerability.', ['pct' => $metrics['vulnerable_dependencies_pct']]) : null,
                    $metrics['no_ci_pct'] !== null
                        ? __(':pct% ship without a CI pipeline checking their changes.', ['pct' => $metrics['no_ci_pct']]) : null,
                    $metrics['high_duplication_pct'] !== null
                        ? __('In :pct%, at least a tenth of the code is copy-pasted.', ['pct' => $metrics['high_duplication_pct']]) : null,
                    __('Based on :audits audits of :codebases codebases — :lines lines of code.', [
                        'audits' => number_format($stats['audits_total']),
                        'codebases' => number_format($stats['codebases']),
                        'lines' => number_format($stats['loc_analyzed']),
                    ]),
                ]);
            @endphp
            <ul class="list-disc ps-5 space-y-2 text-sm">
                @foreach ($quotes as $quote)
                    <li>{{ $quote }}</li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::section :heading="__('How these numbers are computed')" collapsible collapsed>
            <ul class="list-disc ps-5 space-y-1.5 text-sm" style="color: var(--fp-muted)">
                <li>{{ __('Only completed audits count. Failed runs and repositories we could not analyze are left out.') }}</li>
                <li>{{ __('Percentages are per codebase, using each repository\'s most recent audit, so a repository audited every week counts once.') }}</li>
                <li>{{ __('"Exposed secrets" counts likely credentials only — values that look like test fixtures or placeholders are excluded.') }}</li>
                <li>{{ __('"No automated tests" means no test files were found. "Thin coverage" means fewer than 10% of files are tests.') }}</li>
                <li>{{ __('A check that could not be measured for a codebase (for example, a failed vulnerability lookup) leaves that codebase out of that percentage only.') }}</li>
                <li>{{ __('Statistics are refreshed hourly. Last updated :time.', ['time' => \Carbon\Carbon::parse($stats['generated_at'])->diffForHumans()]) }}</li>
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
