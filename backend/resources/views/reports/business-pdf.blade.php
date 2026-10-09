<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Codebase Health — Business Overview') }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; margin: 32px; font-size: 13px; }
        h1 { font-size: 22px; margin-bottom: 2px; }
        h2 { font-size: 15px; margin-top: 26px; border-bottom: 1px solid #b98e41; padding-bottom: 4px; }
        .muted { color: #78716c; font-size: 11px; }
        .verdict { font-size: 16px; font-weight: bold; margin: 4px 0 6px; }
        .chip { font-size: 9px; font-weight: bold; padding: 2px 6px; border-radius: 8px; text-transform: uppercase; }
        .chip-now { background: #fee2e2; color: #b91c1c; }
        .chip-soon { background: #fef3c7; color: #b45309; }
        .chip-later { background: #ecfccb; color: #4d7c0f; }
        .card { border: 1px solid #e8e2d6; padding: 10px; margin-top: 8px; }
        .card-title { font-weight: bold; }
        table.layout { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: top; padding: 0; }
        .step-no { width: 26px; font-weight: bold; color: #b98e41; }
    </style>
</head>
<body>
    @php($b = $business)

    <h1>{{ __('Your codebase health, in plain words') }}</h1>
    <p class="muted">{{ $report->auditRequest->repo_url }} · {{ __('Generated :date by FlexPick', ['date' => $report->created_at->format('Y-m-d')]) }}</p>

    <table class="layout">
        <tr>
            <td style="width: 200px;">@include('reports.partials.chart', ['svg' => $b['charts']['gauge'], 'pdf' => true, 'width' => '190px'])</td>
            <td>
                <p class="muted">{{ __('Overall health') }}: {{ $b['band']->caption() }}</p>
                @if ($b['verdict'] !== null)<p class="verdict">{{ $b['verdict'] }}</p>@endif
                <p>{{ $b['overview'] ?? __('Your full findings are in the developer report.') }}</p>
                @if ($b['delta'] !== null)
                    <p class="muted">{{ sprintf('%+d', $b['delta']) }} {{ __('since your previous audit on :date', ['date' => $b['previousAt']->format('Y-m-d')]) }}</p>
                @endif
                @if ($b['charts']['percentile'] !== null)
                    @include('reports.partials.chart', ['svg' => $b['charts']['percentile'], 'pdf' => true, 'width' => '300px'])
                    <p class="muted">{{ __('Healthier than :p% of the codebases we have audited.', ['p' => $b['percentile']]) }}</p>
                @endif
            </td>
        </tr>
    </table>

    <h2>{{ __('Your codebase at a glance') }}</h2>
    <p class="muted">{{ __('Each area is scored from 0 to 100. Higher is healthier.') }}</p>
    @include('reports.partials.chart', ['svg' => $b['charts']['areas'], 'pdf' => true, 'width' => '420px'])
    @foreach (collect($b['areas'])->whereNotNull('meaning') as $area)
        <div class="card">
            <div class="card-title">{{ $area['label'] }}</div>
            <div>{{ $area['meaning'] }}</div>
            <div><strong>{{ __('How you are doing') }}:</strong> {{ $area['status'] }}</div>
        </div>
    @endforeach

    <h2>{{ __('How serious are the problems') }}</h2>
    @include('reports.partials.chart', ['svg' => $b['charts']['severity'], 'pdf' => true, 'width' => '420px'])

    @if ($b['charts']['businessAreas'] !== null)
        <h2>{{ __('What it affects in your business') }}</h2>
        @include('reports.partials.chart', ['svg' => $b['charts']['businessAreas'], 'pdf' => true, 'width' => '420px'])
    @endif

    @if ($b['findings'] !== [])
        <h2>{{ __('What we found') }}</h2>
        @foreach ($b['findings'] as $finding)
            <div class="card">
                @if ($finding['urgency_label'] !== null)<span class="chip chip-{{ $finding['urgency'] }}">{{ $finding['urgency_label'] }}</span>@endif
                <div class="card-title">{{ $finding['what'] }}</div>
                <div><strong>{{ __('What it may cause') }}:</strong> {{ $finding['consequence'] }}</div>
                <div><strong>{{ __('What you gain by fixing it') }}:</strong> {{ $finding['gain'] }}</div>
            </div>
        @endforeach
    @endif

    @if ($b['roadmap'] !== null && $b['roadmap'] !== [])
        <h2>{{ __('Your roadmap') }}</h2>
        <table class="layout">
            @foreach ($b['roadmap'] as $i => $step)
                <tr>
                    <td class="step-no">{{ $i + 1 }}.</td>
                    <td style="padding-bottom: 8px;">
                        <strong>{{ $step['step'] }}</strong><br>
                        {{ $step['outcome'] }}<br>
                        <span class="muted">{{ __('Effort') }}: {{ $step['effort_label'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($b['expertSummary'] !== null)
        <h2>{{ __('Expert\'s note') }}</h2>
        <p>{{ $b['expertSummary'] }}</p>
    @endif

    @if ($b['questions'] !== null && $b['questions'] !== [])
        <h2>{{ __('Questions to ask your team') }}</h2>
        <ul>
            @foreach ($b['questions'] as $question)
                <li>{{ $question }}</li>
            @endforeach
        </ul>
    @endif

    <p class="muted" style="margin-top: 28px;">{{ __('The developer report, sent alongside this one, has every finding with the exact files, evidence and recommended fix.') }}</p>
</body>
</html>
