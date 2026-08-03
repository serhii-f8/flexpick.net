<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Codebase Health Report') }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; margin: 32px; font-size: 13px; }
        h1 { font-size: 22px; margin-bottom: 2px; }
        h2 { font-size: 15px; margin-top: 26px; border-bottom: 1px solid #d6d3d1; padding-bottom: 4px; }
        .muted { color: #78716c; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #78716c; }
        .impact-high { color: #b91c1c; font-weight: bold; }
        .impact-medium { color: #b45309; font-weight: bold; }
        .impact-low { color: #4d7c0f; font-weight: bold; }
        .score { font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>
    @php($payload = $report->payload)

    <h1>{{ __('Codebase Health Report') }}</h1>
    <p class="muted">
        {{ $report->auditRequest->repo_url }} ·
        {{ __('Generated :date by FlexPick automated analysis', ['date' => $report->created_at->format('Y-m-d')]) }}
    </p>

    <h2>{{ __('Summary') }}</h2>
    <p>{{ $payload['summary'] }}</p>

    @php($notMeasured = $report->auditRequest->metrics['not_measured'] ?? [])
    <h2>{{ __('Health scores') }} <span class="muted">(0–100, {{ __('higher is healthier') }})</span></h2>
    <table>
        <tr>
            @foreach ($payload['scores'] as $dimension => $score)
                <th>{{ str_replace('_', ' ', $dimension) }}</th>
            @endforeach
            @foreach ($notMeasured as $dimension)
                <th>{{ str_replace('_', ' ', $dimension) }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($payload['scores'] as $score)
                <td class="score">{{ $score }}</td>
            @endforeach
            @foreach ($notMeasured as $dimension)
                <td class="muted">{{ __('Not measured') }}</td>
            @endforeach
        </tr>
    </table>

    @php($groups = $payload['groups'] ?? [])
    @if ($groups !== [])
        <h2>{{ __('What we found') }}</h2>
        <table>
            <tr><th>{{ __('Rule family') }}</th><th>{{ __('Location') }}</th><th>{{ __('Severity') }}</th><th>{{ __('Count') }}</th></tr>
            @foreach ($groups as $group)
                <tr>
                    <td>{{ $group['rule_family'] }}</td>
                    <td>{{ $group['directory'] }}</td>
                    <td class="impact-{{ $group['severity'] }}">{{ strtoupper($group['severity']) }}</td>
                    <td>{{ $group['count'] }}</td>
                </tr>
                <tr>
                    <td colspan="4">
                        <div><strong>{{ __('What it is') }}:</strong> {{ $group['narrative']['what'] }}</div>
                        <div><strong>{{ __('What it affects') }}:</strong> {{ $group['narrative']['affects'] }}</div>
                        <div><strong>{{ __('What fixing it buys you') }}:</strong> {{ $group['narrative']['benefit'] }}</div>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @php($metrics = $report->auditRequest->metrics)
    @if (is_array($metrics) && $metrics !== [])
        <h2>{{ __('Repository facts') }}</h2>
        <table>
            <tr><th>{{ __('Fact') }}</th><th>{{ __('Value') }}</th></tr>
            <tr><td>{{ __('Source files') }}</td><td>{{ number_format($metrics['files_total'] ?? 0) }}</td></tr>
            <tr><td>{{ __('Lines of code') }}</td><td>{{ number_format($metrics['loc_total'] ?? 0) }}</td></tr>
            <tr><td>{{ __('Duplicated lines') }}</td><td>{{ $metrics['duplication_pct'] ?? 0 }}%</td></tr>
            <tr><td>{{ __('Test file ratio') }}</td><td>{{ $metrics['test_ratio_pct'] ?? 0 }}%</td></tr>
            <tr><td>{{ __('CI configured') }}</td><td>{{ ($metrics['has_ci'] ?? false) ? __('yes') : __('no') }}</td></tr>
            <tr><td>{{ __('Potential secrets') }}</td><td>{{ array_sum(array_column($metrics['secret_findings'] ?? [], 'count')) }}</td></tr>
        </table>
    @endif

    <h2>{{ __('Risks, ranked by impact') }}</h2>
    <table>
        <tr><th>{{ __('Risk') }}</th><th>{{ __('Impact') }}</th><th>{{ __('Evidence') }}</th><th>{{ __('Recommendation') }}</th></tr>
        @foreach (collect($payload['risks'])->sortBy(fn ($r) => array_search($r['impact'], ['high', 'medium', 'low'])) as $risk)
            <tr>
                <td>{{ $risk['title'] }}</td>
                <td class="impact-{{ $risk['impact'] }}">{{ strtoupper($risk['impact']) }}</td>
                <td>{{ $risk['evidence'] }}</td>
                <td>{{ $risk['recommendation'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('What to fix first') }}</h2>
    <table>
        <tr><th>#</th><th>{{ __('Step') }}</th><th>{{ __('Why') }}</th><th>{{ __('Effort') }}</th></tr>
        @foreach ($payload['fix_first_plan'] as $i => $step)
            <tr><td>{{ $i + 1 }}</td><td>{{ $step['step'] }}</td><td>{{ $step['why'] }}</td><td>{{ $step['effort'] }}</td></tr>
        @endforeach
    </table>

    <p class="muted" style="margin-top: 28px;">
        {{ __('Scores and findings are derived from automated static analysis of the repository at the time of generation. Reply to your report email to discuss any finding with an engineer.') }}
    </p>
</body>
</html>
