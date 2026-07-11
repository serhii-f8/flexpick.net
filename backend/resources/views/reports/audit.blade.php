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

    <h2>{{ __('Health scores') }} <span class="muted">(0–100, {{ __('higher is healthier') }})</span></h2>
    <table>
        <tr>
            @foreach ($payload['scores'] as $dimension => $score)
                <th>{{ str_replace('_', ' ', $dimension) }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($payload['scores'] as $score)
                <td class="score">{{ $score }}</td>
            @endforeach
        </tr>
    </table>

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
