<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health Report') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; margin: 0; padding: 32px 16px; font-size: 15px; line-height: 1.5; }
        .page { max-width: 860px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 28px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 14px; }
        .muted { color: #78716c; font-size: 12px; }
        .sample-banner { background: #d4a853; color: #1c1917; text-align: center; font-weight: bold; padding: 8px; border-radius: 6px; margin-bottom: 20px; letter-spacing: 0.08em; text-transform: uppercase; font-size: 12px; }
        .score-hero { display: flex; align-items: baseline; gap: 16px; flex-wrap: wrap; }
        .score-big { font-size: 52px; font-weight: bold; }
        .scores-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; }
        .score-tile { border: 1px solid #e7e5e4; border-radius: 6px; padding: 12px; text-align: center; }
        .score-tile .value { font-size: 22px; font-weight: bold; }
        .score-tile .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
        .risk { border-top: 1px solid #e7e5e4; padding: 14px 0; }
        .risk-head { display: flex; gap: 10px; align-items: center; }
        .badge { font-size: 10px; font-weight: bold; padding: 3px 8px; border-radius: 99px; text-transform: uppercase; }
        .badge-high { background: #fee2e2; color: #b91c1c; }
        .badge-medium { background: #fef3c7; color: #b45309; }
        .badge-low { background: #ecfccb; color: #4d7c0f; }
        .risk-title { font-weight: bold; }
        .risk-detail { margin-top: 8px; color: #44403c; }
        .locked-block { position: relative; margin-top: 8px; }
        .locked-blur { filter: blur(5px); user-select: none; pointer-events: none; color: #44403c; }
        .lock-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
        .lock-pill { background: #1c1917; color: #fafaf9; font-size: 12px; padding: 6px 14px; border-radius: 99px; }
        .cta-card { text-align: center; background: #1c1917; color: #fafaf9; }
        .cta-card h2 { color: #fafaf9; }
        .btn { display: inline-block; background: #d4a853; color: #1c1917; font-weight: bold; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 6px; }
        .btn-ghost { background: transparent; color: #fafaf9; border: 1px solid #57534e; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
    </style>
</head>
<body>
@php($payload = $report->payload)
<div class="page">
    @if ($isSample)
        <div class="sample-banner">{{ __('Sample report') }} — {{ __('this is what every FlexPick audit looks like') }}</div>
    @endif

    <div class="card">
        <h1>{{ __('Codebase Health Report') }}</h1>
        <p class="muted">{{ $report->auditRequest->repo_url }} · {{ $report->created_at->format('Y-m-d') }}</p>
        <div class="score-hero">
            <span class="score-big">{{ $payload['scores']['overall'] }}</span>
            <span class="muted">{{ __('overall health, 0–100 (higher is healthier)') }}</span>
        </div>
        @if ($percentile !== null)
            <p class="muted">{{ __('This codebase scores better than :p% of repositories we\'ve audited.', ['p' => $percentile]) }}</p>
        @endif
        @if ($deltas !== null && ($deltas['deltas']['overall'] ?? 0) !== 0)
            <p class="muted" style="color: {{ $deltas['deltas']['overall'] > 0 ? '#4d7c0f' : '#b91c1c' }};">
                {{ sprintf('%+d', $deltas['deltas']['overall']) }}
                {{ __('since your previous audit on :date', ['date' => $deltas['previous_at']->format('Y-m-d')]) }}
            </p>
        @endif
        <p style="margin-top: 14px;">{{ $payload['summary'] }}</p>
    </div>

    @php($notMeasured = $report->auditRequest->metrics['not_measured'] ?? [])
    <div class="card">
        <h2>{{ __('Health scores') }}</h2>
        <div class="scores-grid">
            @foreach ($payload['scores'] as $dimension => $score)
                @continue($dimension === 'overall')
                <div class="score-tile">
                    <div class="value">{{ $score }}</div>
                    <div class="label">{{ str_replace('_', ' ', $dimension) }}</div>
                    @if ($deltas !== null && ($deltas['deltas'][$dimension] ?? 0) !== 0)
                        <div style="font-size: 11px; font-weight: bold; color: {{ $deltas['deltas'][$dimension] > 0 ? '#4d7c0f' : '#b91c1c' }};">
                            {{ sprintf('%+d', $deltas['deltas'][$dimension]) }}
                        </div>
                    @endif
                </div>
            @endforeach
            @foreach ($notMeasured as $dimension)
                <div class="score-tile">
                    <div class="value" style="color: #78716c; font-size: 13px;" title="{{ __('This analysis did not run the scanner this score depends on.') }}">{{ __('Not measured') }}</div>
                    <div class="label">{{ str_replace('_', ' ', $dimension) }}</div>
                </div>
            @endforeach
        </div>
    </div>

    @php($groups = $payload['groups'] ?? [])
    @if ($groups !== [])
        <div class="card">
            <h2>{{ __('What we found') }}</h2>
            @foreach ($groups as $group)
                <div class="risk">
                    <div class="risk-head">
                        <span class="badge badge-{{ $group['severity'] }}">{{ $group['severity'] }}</span>
                        <span class="risk-title">{{ $group['rule_family'] }}</span>
                        <span class="muted">{{ $group['directory'] }} ·
                            {{ trans_choice('{1} :count finding|[2,*] :count findings', $group['count'], ['count' => $group['count']]) }}
                        </span>
                    </div>
                    @if ($unlocked)
                        <div class="risk-detail">
                            <div><strong>{{ __('What it is') }}:</strong> {{ $group['narrative']['what'] }}</div>
                            <div style="margin-top: 4px;"><strong>{{ __('What it affects') }}:</strong> {{ $group['narrative']['affects'] }}</div>
                            <div style="margin-top: 4px;"><strong>{{ __('What fixing it buys you') }}:</strong> {{ $group['narrative']['benefit'] }}</div>
                        </div>
                    @else
                        <div class="locked-block">
                            <div class="locked-blur">
                                <div><strong>{{ __('What it is') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                                <div style="margin-top: 4px;"><strong>{{ __('What it affects') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                                <div style="margin-top: 4px;"><strong>{{ __('What fixing it buys you') }}:</strong> {{ str_repeat('█▌ ', 10) }}</div>
                            </div>
                            <div class="lock-overlay"><span class="lock-pill">🔒 {{ __('Unlock to read') }}</span></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @php($metrics = $report->auditRequest->metrics)
    @if (is_array($metrics) && $metrics !== [])
        <div class="card">
            <h2>{{ __('Repository facts') }}</h2>
            <div class="scores-grid">
                <div class="score-tile"><div class="value">{{ number_format($metrics['files_total'] ?? 0) }}</div><div class="label">{{ __('source files') }}</div></div>
                <div class="score-tile"><div class="value">{{ number_format($metrics['loc_total'] ?? 0) }}</div><div class="label">{{ __('lines of code') }}</div></div>
                <div class="score-tile"><div class="value">{{ $metrics['duplication_pct'] ?? 0 }}%</div><div class="label">{{ __('duplicated lines') }}</div></div>
                <div class="score-tile"><div class="value">{{ $metrics['test_ratio_pct'] ?? 0 }}%</div><div class="label">{{ __('test file ratio') }}</div></div>
                <div class="score-tile"><div class="value">{{ ($metrics['has_ci'] ?? false) ? __('yes') : __('no') }}</div><div class="label">{{ __('CI configured') }}</div></div>
                <div class="score-tile"><div class="value">{{ array_sum(array_column($metrics['secret_findings'] ?? [], 'count')) }}</div><div class="label">{{ __('potential secrets') }}</div></div>
                @if (isset($metrics['dependency_audit']) && ! isset($metrics['dependency_audit']['error']))
                    <div class="score-tile"><div class="value">{{ $metrics['dependency_audit']['vulnerable_count'] }}</div><div class="label">{{ __('vulnerable dependencies') }}</div></div>
                @endif
            </div>
            @php($langs = collect($metrics['languages'] ?? [])->sortByDesc('loc')->take(5))
            @if ($langs->isNotEmpty())
                <p class="muted" style="margin-top: 14px;">
                    {{ __('Languages') }}:
                    {{ $langs->map(fn ($stats, $ext) => strtoupper($ext).' '.number_format($stats['loc']).' loc')->implode(' · ') }}
                </p>
            @endif
            @isset($metrics['tooling'])
                <p class="muted" style="margin-top: 8px;">
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
                <table style="margin-top: 12px;">
                    <tr><th>{{ __('Largest files') }}</th><th>{{ __('Lines') }}</th></tr>
                    @foreach ($largest as $file)
                        <tr><td>{{ $file['path'] }}</td><td>{{ number_format($file['loc']) }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if (($metrics['git']['last_commit_at'] ?? null) !== null)
                <p class="muted" style="margin-top: 12px;">
                    {{ __('Last commit') }}: {{ \Illuminate\Support\Carbon::parse($metrics['git']['last_commit_at'])->format('Y-m-d') }}
                </p>
            @endif
            @php($hotspots = array_slice($metrics['hotspots'] ?? [], 0, 5))
            @if ($hotspots !== [])
                <table style="margin-top: 12px;">
                    <tr><th>{{ __('Change hotspots (last :n commits)', ['n' => config('audit.clone_depth')]) }}</th><th>{{ __('Changes') }}</th><th>{{ __('Lines') }}</th></tr>
                    @foreach ($hotspots as $spot)
                        <tr><td>{{ $spot['path'] }}</td><td>{{ $spot['changes'] }}</td><td>{{ number_format($spot['loc']) }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if (($metrics['git']['contributors'] ?? 0) > 0)
                <p class="muted" style="margin-top: 12px;">
                    {{ __(':c contributor(s) in the last :n commits — top contributor authored :p% of them.', [
                        'c' => $metrics['git']['contributors'],
                        'n' => $metrics['git']['commits_analyzed'],
                        'p' => $metrics['git']['top_contributor_pct'],
                    ]) }}
                </p>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Risks, ranked by impact') }}</h2>
        @foreach (collect($payload['risks'])->sortBy(fn ($r) => array_search($r['impact'], ['high', 'medium', 'low'])) as $risk)
            <div class="risk">
                <div class="risk-head">
                    <span class="badge badge-{{ $risk['impact'] }}">{{ $risk['impact'] }}</span>
                    <span class="risk-title">{{ $risk['title'] }}</span>
                </div>
                @if ($unlocked)
                    <div class="risk-detail">
                        <div><strong>{{ __('Evidence') }}:</strong> {{ $risk['evidence'] }}</div>
                        <div style="margin-top: 4px;"><strong>{{ __('Recommendation') }}:</strong> {{ $risk['recommendation'] }}</div>
                    </div>
                @else
                    <div class="locked-block">
                        <div class="locked-blur">
                            <div><strong>{{ __('Evidence') }}:</strong> {{ str_repeat('█▌ ', 14) }}</div>
                            <div style="margin-top: 4px;"><strong>{{ __('Recommendation') }}:</strong> {{ str_repeat('█▌ ', 18) }}</div>
                        </div>
                        <div class="lock-overlay"><span class="lock-pill">🔒 {{ __('Unlock to read') }}</span></div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($unlocked)
        <div class="card">
            <h2>{{ __('What to fix first') }}</h2>
            <table>
                <tr><th>#</th><th>{{ __('Step') }}</th><th>{{ __('Why') }}</th><th>{{ __('Effort') }}</th></tr>
                @foreach ($payload['fix_first_plan'] as $i => $step)
                    <tr><td>{{ $i + 1 }}</td><td>{{ $step['step'] }}</td><td>{{ $step['why'] }}</td><td>{{ $step['effort'] }}</td></tr>
                @endforeach
            </table>
            @if (! $isSample && $report->pdf_path !== null)
                <p style="margin-top: 16px;"><a href="{{ route('reports.download', ['auditReport' => $report->uuid]) }}">{{ __('Download PDF') }}</a></p>
            @endif
        </div>
    @else
        <div class="card cta-card">
            <h2>{{ __('Unlock full report') }}</h2>
            <p style="color: #d6d3d1;">{{ __('Get every finding\'s evidence and recommendation, the prioritized fix-first plan, and PDF export.') }}</p>
            <a class="btn" href="{{ $unlockUrl }}">{{ __('Unlock for $5') }}</a>
            <a class="btn btn-ghost" href="{{ url('/pricing') }}">{{ __('Or subscribe from $10/mo') }}</a>
        </div>
    @endif

    <p class="muted" style="text-align: center;">
        {{ __('Scores and findings are derived from automated static analysis at generation time. Reply to your report email to discuss any finding with an engineer.') }}
    </p>
</div>
</body>
</html>
