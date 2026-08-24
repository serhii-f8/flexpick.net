<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health Report') }}</title>
    @vite('resources/css/app.css')
    <style>
        /* The bundle's daisyUI theme is dark by default; this document is a
           reading surface and is deliberately light. Scoped to this page.
           daisyUI paints `:root` itself (background + color), and the
           shared landing-page rule gives h1/h2/h3 a light-on-dark color and
           the Syne display font — both bleed through even though nothing on
           this page opts into them, so both are neutralized explicitly here. */
        html, .reports-page { background: #faf8f4; color: #1c1917; }
        .reports-page h1, .reports-page h2, .reports-page h3 { color: #1c1917; font-family: inherit; }
    </style>
</head>
<body class="reports-page font-sans text-[15px] leading-relaxed">
<div class="mx-auto max-w-[860px] px-4 py-8">
    @php($payload = $report->payload)
    @if ($isSample)
        <div class="mb-3 rounded-lg bg-primary-500 px-3 py-2 text-center text-xs font-bold uppercase tracking-wider text-stone-900">{{ __('Sample report') }} — {{ __('every section a FlexPick audit can produce') }}</div>
        <div class="mb-5 rounded-xl border border-stone-200 bg-white px-5 py-4">
            <p class="text-sm text-stone-700">{{ __('This page shows one repository reported at every level, so you can see exactly what each tier adds. Each section is tagged with the lowest tier that includes it — and every tier also includes everything below it.') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ([\App\Constants\AuditTier::DIAGNOSTIC, \App\Constants\AuditTier::DEEP_AI, \App\Constants\AuditTier::EXPERT] as $legendTier)
                    @include('reports.partials.web.sample-tier-badge', ['tier' => $legendTier->value])
                @endforeach
            </div>
        </div>
    @endif

    <div class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h1 class="mb-1 text-2xl font-bold">{{ __('Codebase Health Report') }}</h1>
        <p class="text-xs text-stone-500">{{ $report->auditRequest->repo_url }} · {{ $report->created_at->format('Y-m-d') }}</p>
        <div class="flex flex-wrap items-baseline gap-4">
            <span class="text-5xl font-bold text-primary-600">{{ $payload['scores']['overall'] }}</span>
            <span class="text-xs text-stone-500">{{ __('overall health, 0–100 (higher is healthier)') }}</span>
        </div>
        @if ($percentile !== null)
            <p class="text-xs text-stone-500">{{ __('This codebase scores better than :p% of repositories we\'ve audited.', ['p' => $percentile]) }}</p>
        @endif
        @if ($deltas !== null && ($deltas['deltas']['overall'] ?? 0) !== 0)
            <p class="text-xs {{ $deltas['deltas']['overall'] > 0 ? 'text-lime-700' : 'text-red-700' }}">
                {{ sprintf('%+d', $deltas['deltas']['overall']) }}
                {{ __('since your previous audit on :date', ['date' => $deltas['previous_at']->format('Y-m-d')]) }}
            </p>
        @endif
        <p class="mt-3.5">{{ $payload['summary'] }}</p>
    </div>

    @php($notMeasured = $report->auditRequest->metrics['not_measured'] ?? [])
    <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
        @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
        <h2 class="text-base font-bold mb-3">{{ __('Health scores') }}</h2>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3">
            @foreach ($payload['scores'] as $dimension => $score)
                @continue($dimension === 'overall')
                <div class="rounded-lg border border-stone-200 p-3 text-center">
                    <div class="text-xl font-bold">{{ $score }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-stone-500">{{ str_replace('_', ' ', $dimension) }}</div>
                    @if ($deltas !== null && ($deltas['deltas'][$dimension] ?? 0) !== 0)
                        <div class="text-[11px] font-bold {{ $deltas['deltas'][$dimension] > 0 ? 'text-lime-700' : 'text-red-700' }}">
                            {{ sprintf('%+d', $deltas['deltas'][$dimension]) }}
                        </div>
                    @endif
                </div>
            @endforeach
            @foreach ($notMeasured as $dimension)
                <div class="rounded-lg border border-stone-200 p-3 text-center">
                    <div class="text-[13px] text-stone-500" title="{{ __('This analysis did not run the scanner this score depends on.') }}">{{ __('Not measured') }}</div>
                    <div class="text-[11px] uppercase tracking-wider text-stone-500">{{ str_replace('_', ' ', $dimension) }}</div>
                </div>
            @endforeach
        </div>
    </div>

    @php($groups = $payload['groups'] ?? [])
    @php($groupBadge = [
        'critical' => 'bg-red-100 text-red-900',
        'high' => 'bg-red-50 text-red-800',
        'medium' => 'bg-amber-50 text-amber-800',
        'low' => 'bg-lime-50 text-lime-800',
        'info' => 'bg-sky-50 text-sky-800',
    ])
    @if ($groups !== [])
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
        <h2 class="text-base font-bold mb-3">{{ __('What we found') }}</h2>
            @foreach ($groups as $group)
                <div class="border-t border-stone-200 py-3.5">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $groupBadge[$group['severity']] ?? 'bg-stone-100 text-stone-700' }}">{{ $group['severity'] }}</span>
                        <span class="font-semibold">{{ $group['rule_family'] }}</span>
                        <span class="text-xs text-stone-500">{{ $group['directory'] }} ·
                            {{ trans_choice('{1} :count finding|[2,*] :count findings', $group['count'], ['count' => $group['count']]) }}
                        </span>
                    </div>
                    @if ($unlocked)
                        <div class="mt-2 text-sm text-stone-700">
                            <div><strong>{{ __('What it is') }}:</strong> {{ $group['narrative']['what'] }}</div>
                            <div class="mt-1"><strong>{{ __('What it affects') }}:</strong> {{ $group['narrative']['affects'] }}</div>
                            <div class="mt-1"><strong>{{ __('What fixing it buys you') }}:</strong> {{ $group['narrative']['benefit'] }}</div>
                        </div>
                    @else
                        <div class="relative mt-2">
                            <div class="blur-[5px] select-none pointer-events-none text-stone-700">
                                <div><strong>{{ __('What it is') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                                <div class="mt-1"><strong>{{ __('What it affects') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                                <div class="mt-1"><strong>{{ __('What fixing it buys you') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center"><span class="rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50">🔒 {{ __('Unlock to read') }}</span></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @php($metrics = $report->auditRequest->metrics)
    @if (is_array($metrics) && $metrics !== [])
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
        <h2 class="text-base font-bold mb-3">{{ __('Repository facts') }}</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3">
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ number_format($metrics['files_total'] ?? 0) }}</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('source files') }}</div></div>
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ number_format($metrics['loc_total'] ?? 0) }}</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('lines of code') }}</div></div>
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ $metrics['duplication_pct'] ?? 0 }}%</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('duplicated lines') }}</div></div>
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ $metrics['test_ratio_pct'] ?? 0 }}%</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('test file ratio') }}</div></div>
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ ($metrics['has_ci'] ?? false) ? __('yes') : __('no') }}</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('CI configured') }}</div></div>
                <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ array_sum(array_column($metrics['secret_findings'] ?? [], 'count')) }}</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('potential secrets') }}</div></div>
                @if (isset($metrics['dependency_audit']) && ! isset($metrics['dependency_audit']['error']))
                    <div class="rounded-lg border border-stone-200 p-3 text-center"><div class="text-xl font-bold">{{ $metrics['dependency_audit']['vulnerable_count'] }}</div><div class="text-[11px] uppercase tracking-wider text-stone-500">{{ __('vulnerable dependencies') }}</div></div>
                @endif
            </div>
            @php($langs = collect($metrics['languages'] ?? [])->sortByDesc('loc')->take(5))
            @if ($langs->isNotEmpty())
                <p class="mt-3.5 text-xs text-stone-500">
                    {{ __('Languages') }}:
                    {{ $langs->map(fn ($stats, $ext) => strtoupper($ext).' '.number_format($stats['loc']).' loc')->implode(' · ') }}
                </p>
            @endif
            @isset($metrics['tooling'])
                <p class="mt-2 text-xs text-stone-500">
                    {{ __('Engineering setup') }}:
                    {{ __('error monitoring') }} {{ $metrics['tooling']['error_monitoring'] ? '✓' : '✗' }} ·
                    {{ __('linter') }} {{ $metrics['tooling']['linter'] ? '✓' : '✗' }} ·
                    {{ __('static analysis') }} {{ $metrics['tooling']['static_analysis'] ? '✓' : '✗' }} ·
                    {{ __('.env.example') }} {{ $metrics['tooling']['env_example'] ? '✓' : '✗' }} ·
                    {{ __('Docker') }} {{ $metrics['tooling']['dockerized'] ? '✓' : '✗' }}
                </p>
            @endisset
            @php($largest = array_slice($metrics['largest_files'] ?? [], 0, 5))
            @if ($largest !== [])
                <table class="mt-3 w-full border-collapse">
                    <tr><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Largest files') }}</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Lines') }}</th></tr>
                    @foreach ($largest as $file)
                        <tr><td class="border-b border-stone-200 p-2 align-top">{{ $file['path'] }}</td><td class="border-b border-stone-200 p-2 align-top">{{ number_format($file['loc']) }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if (($metrics['git']['last_commit_at'] ?? null) !== null)
                <p class="mt-3 text-xs text-stone-500">
                    {{ __('Last commit') }}: {{ \Illuminate\Support\Carbon::parse($metrics['git']['last_commit_at'])->format('Y-m-d') }}
                </p>
            @endif
            @php($hotspots = array_slice($metrics['hotspots'] ?? [], 0, 5))
            @if ($hotspots !== [])
                <table class="mt-3 w-full border-collapse">
                    <tr><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Change hotspots (last :n commits)', ['n' => config('audit.clone_depth')]) }}</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Changes') }}</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Lines') }}</th></tr>
                    @foreach ($hotspots as $spot)
                        <tr><td class="border-b border-stone-200 p-2 align-top">{{ $spot['path'] }}</td><td class="border-b border-stone-200 p-2 align-top">{{ $spot['changes'] }}</td><td class="border-b border-stone-200 p-2 align-top">{{ number_format($spot['loc']) }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if (($metrics['git']['contributors'] ?? 0) > 0)
                <p class="mt-3 text-xs text-stone-500">
                    {{ __(':c contributor(s) in the last :n commits — top contributor authored :p% of them.', [
                        'c' => $metrics['git']['contributors'],
                        'n' => $metrics['git']['commits_analyzed'],
                        'p' => $metrics['git']['top_contributor_pct'],
                    ]) }}
                </p>
            @endif
        </div>
    @endif

    @php($riskBadge = [
        'high' => 'bg-red-50 text-red-800',
        'medium' => 'bg-amber-50 text-amber-800',
        'low' => 'bg-lime-50 text-lime-800',
    ])
    <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
        @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
        <h2 class="text-base font-bold mb-3">{{ __('Risks, ranked by impact') }}</h2>
        @foreach (collect($payload['risks'])->sortBy(fn ($r) => array_search($r['impact'], ['high', 'medium', 'low'])) as $risk)
            <div class="border-t border-stone-200 py-3.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $riskBadge[$risk['impact']] ?? 'bg-stone-100 text-stone-700' }}">{{ $risk['impact'] }}</span>
                    <span class="font-semibold">{{ $risk['title'] }}</span>
                </div>
                @if ($unlocked)
                    <div class="mt-2 text-sm text-stone-700">
                        <div><strong>{{ __('Evidence') }}:</strong> {{ $risk['evidence'] }}</div>
                        <div class="mt-1"><strong>{{ __('Recommendation') }}:</strong> {{ $risk['recommendation'] }}</div>
                    </div>
                @else
                    <div class="relative mt-2">
                        <div class="blur-[5px] select-none pointer-events-none text-stone-700">
                            <div><strong>{{ __('Evidence') }}:</strong> {{ str_repeat('█▌ ', 14) }}</div>
                            <div class="mt-1"><strong>{{ __('Recommendation') }}:</strong> {{ str_repeat('█▌ ', 18) }}</div>
                        </div>
                        <div class="absolute inset-0 flex items-center justify-center"><span class="rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50">🔒 {{ __('Unlock to read') }}</span></div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if (($payload['deep_review'] ?? null) !== null)
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'deep_ai'])
            @include('reports.partials.web.deep-findings', ['payload' => $payload, 'unlocked' => $unlocked])
        </div>
    @endif

    @if (($payload['expert_review'] ?? null) !== null)
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'expert'])
            @include('reports.partials.web.expert-review', ['payload' => $payload])
        </div>
    @endif

    @if ($unlocked)
        <div class="rounded-xl border border-stone-200 bg-white p-7 mb-5">
            @includeWhen($isSample, 'reports.partials.web.sample-tier-badge', ['tier' => 'diagnostic'])
        <h2 class="text-base font-bold mb-3">{{ __('What to fix first') }}</h2>
            <table class="w-full border-collapse">
                <tr><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">#</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Step') }}</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Why') }}</th><th class="border-b border-stone-200 p-2 text-left text-[11px] uppercase tracking-wider text-stone-500">{{ __('Effort') }}</th></tr>
                @foreach ($payload['fix_first_plan'] as $i => $step)
                    <tr><td class="border-b border-stone-200 p-2 align-top">{{ $i + 1 }}</td><td class="border-b border-stone-200 p-2 align-top">{{ $step['step'] }}</td><td class="border-b border-stone-200 p-2 align-top">{{ $step['why'] }}</td><td class="border-b border-stone-200 p-2 align-top">{{ $step['effort'] }}</td></tr>
                @endforeach
            </table>
            @if (! $isSample && $report->pdf_path !== null)
                <p class="mt-4"><a href="{{ route('reports.download', ['auditReport' => $report->uuid]) }}">{{ __('Download PDF') }}</a></p>
            @endif
        </div>
    @else
        <div class="rounded-xl bg-stone-900 p-7 text-center text-stone-50 mb-5">
            <h2 class="text-base font-bold mb-3">{{ __('Unlock full report') }}</h2>
            <p class="text-stone-300">{{ __('Get every finding\'s evidence and recommendation, the prioritized fix-first plan, and PDF export.') }}</p>
            <a class="inline-block rounded-lg bg-primary-500 px-6 py-3 font-bold text-stone-900 no-underline" href="{{ $unlockUrl }}">{{ __('Unlock for $5') }}</a>
            @php($cheapestPlan = collect(config('pricing.subscriptions'))->sortBy('price')->first())
            <a class="inline-block rounded-lg border border-stone-600 px-6 py-3 font-bold text-stone-50 no-underline" href="{{ route('register') }}">{{ __('Or subscribe from $:price/mo', ['price' => number_format(($cheapestPlan['price'] ?? 0) / 100)]) }}</a>
        </div>
    @endif

    <p class="text-xs text-stone-500 text-center">
        {{ __('Scores and findings are derived from automated static analysis at generation time. Reply to your report email to discuss any finding with an engineer.') }}
    </p>
</div>
</body>
</html>
