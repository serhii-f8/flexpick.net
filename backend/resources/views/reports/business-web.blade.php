<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Codebase Health — Business Overview') }}</title>
    @vite('resources/css/app.css')
    <style>
        /* Same light reading surface as the developer report; see technical-web. */
        html, .reports-page { background: #faf8f4; color: #1c1917; }
        .reports-page h1, .reports-page h2, .reports-page h3 { color: #1c1917; font-family: inherit; }
    </style>
</head>
<body class="reports-page font-sans text-[15px] leading-relaxed">
@php($b = $business)
@php($urgencyChip = ['now' => 'bg-red-100 text-red-900', 'soon' => 'bg-amber-50 text-amber-800', 'later' => 'bg-lime-50 text-lime-800'])
<div class="mx-auto max-w-[860px] px-4 py-8">
    @if ($isSample)
        <div class="mb-3 rounded-lg bg-primary-500 px-3 py-2 text-center text-xs font-bold uppercase tracking-wider text-stone-900">{{ __('Sample report') }} — {{ __('the business overview every FlexPick audit includes') }}</div>
    @endif

    @include('reports.partials.web.report-tabs')

    {{-- 1. Verdict --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h1 class="mb-1 text-2xl font-bold">{{ __('Your codebase health, in plain words') }}</h1>
        <p class="text-xs text-stone-500">{{ $report->auditRequest->repo_url }} · {{ $report->created_at->format('Y-m-d') }}</p>
        <div class="mt-5 grid items-center gap-6 sm:grid-cols-[220px_1fr]">
            <div class="mx-auto w-full max-w-[220px]">@include('reports.partials.chart', ['svg' => $b['charts']['gauge']])</div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ __('Overall health') }}: {{ $b['band']->caption() }}</p>
                @if ($b['verdict'] !== null)
                    <p class="mt-1 text-lg font-semibold">{{ $b['verdict'] }}</p>
                @endif
                @if ($b['overview'] !== null)
                    <p class="mt-2">{{ $b['overview'] }}</p>
                @else
                    <p class="mt-2">{{ __('Your full findings are in the developer report.') }}</p>
                @endif
                @if ($b['delta'] !== null)
                    <p class="mt-2 text-sm font-semibold {{ $b['delta'] > 0 ? 'text-lime-700' : 'text-red-700' }}">
                        {{ $b['delta'] > 0 ? '▲' : '▼' }} {{ sprintf('%+d', $b['delta']) }}
                        {{ __('since your previous audit on :date', ['date' => $b['previousAt']->format('Y-m-d')]) }}
                    </p>
                @endif
                @if ($b['charts']['percentile'] !== null)
                    <div class="mt-4">
                        @include('reports.partials.chart', ['svg' => $b['charts']['percentile']])
                        <p class="mt-1 text-xs text-stone-500">{{ __('Healthier than :p% of the codebases we have audited.', ['p' => $b['percentile']]) }}</p>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- 2. Areas --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h2 class="mb-1 text-base font-bold">{{ __('Your codebase at a glance') }}</h2>
        <p class="mb-4 text-xs text-stone-500">{{ __('Each area is scored from 0 to 100. Higher is healthier.') }}</p>
        @include('reports.partials.chart', ['svg' => $b['charts']['areas']])
        @php($explained = collect($b['areas'])->whereNotNull('meaning'))
        @if ($explained->isNotEmpty())
            <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($explained as $area)
                    <div class="rounded-lg border border-stone-200 p-4">
                        <dt class="font-semibold">{{ $area['label'] }}</dt>
                        @if ($unlocked)
                            <dd class="mt-1 text-sm text-stone-700">{{ $area['meaning'] }}</dd>
                            <dd class="mt-1 text-sm"><strong>{{ __('How you are doing') }}:</strong> {{ $area['status'] }}</dd>
                        @else
                            <dd>@include('reports.partials.web.locked-text', ['lines' => 2])</dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- 3. Seriousness --}}
    <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
        <h2 class="mb-1 text-base font-bold">{{ __('How serious are the problems') }}</h2>
        <p class="mb-4 text-xs text-stone-500">{{ __('Every problem area we found, sorted by how soon it could hurt you.') }}</p>
        @include('reports.partials.chart', ['svg' => $b['charts']['severity']])
    </section>

    {{-- 4. Business areas --}}
    @if ($b['charts']['businessAreas'] !== null)
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-1 text-base font-bold">{{ __('What it affects in your business') }}</h2>
            <p class="mb-4 text-xs text-stone-500">{{ __('Which part of your business each key finding touches most.') }}</p>
            @include('reports.partials.chart', ['svg' => $b['charts']['businessAreas']])
        </section>
    @endif

    {{-- 5. Key findings --}}
    @if ($b['findings'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('What we found') }}</h2>
            <div class="grid gap-3">
                @foreach ($b['findings'] as $finding)
                    <article class="rounded-lg border border-stone-200 p-4">
                        <div class="flex flex-wrap items-start gap-2.5">
                            @if ($finding['urgency_label'] !== null)
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $urgencyChip[$finding['urgency']] }}">{{ $finding['urgency_label'] }}</span>
                            @endif
                            <h3 class="flex-1 font-semibold">{{ $finding['what'] }}</h3>
                        </div>
                        @if ($unlocked)
                            <div class="mt-2 text-sm text-stone-700">
                                <div><strong>{{ __('What it may cause') }}:</strong> {{ $finding['consequence'] }}</div>
                                <div class="mt-1"><strong>{{ __('What you gain by fixing it') }}:</strong> {{ $finding['gain'] }}</div>
                            </div>
                        @else
                            @include('reports.partials.web.locked-text', ['lines' => 2])
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @unless ($unlocked)
        @include('reports.partials.web.unlock-cta')
    @endunless

    {{-- 6. Roadmap --}}
    @if ($b['roadmap'] !== null && $b['roadmap'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('Your roadmap') }}</h2>
            @if ($unlocked)
                <ol class="relative border-l-2 border-primary-500 pl-6">
                    @foreach ($b['roadmap'] as $i => $step)
                        <li class="mb-5 last:mb-0">
                            <span class="absolute -left-[13px] flex h-6 w-6 items-center justify-center rounded-full bg-primary-500 text-xs font-bold text-stone-900">{{ $i + 1 }}</span>
                            <div class="font-semibold">{{ $step['step'] }}</div>
                            <div class="mt-1 text-sm text-stone-700">{{ $step['outcome'] }}</div>
                            <span class="mt-1 inline-block rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ __('Effort') }}: {{ $step['effort_label'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @else
                @include('reports.partials.web.locked-text', ['lines' => 4])
            @endif
        </section>
    @endif

    {{-- 7. Expert --}}
    @if ($b['expertSummary'] !== null)
        <section class="mb-5 rounded-xl border border-primary-500 bg-white p-7">
            <h2 class="mb-2 text-base font-bold">{{ __('Expert\'s note') }}</h2>
            <p class="text-sm text-stone-700">{{ $b['expertSummary'] }}</p>
        </section>
    @endif

    {{-- 8. Questions --}}
    @if ($b['questions'] !== null && $b['questions'] !== [])
        <section class="mb-5 rounded-xl border border-stone-200 bg-white p-7">
            <h2 class="mb-3 text-base font-bold">{{ __('Questions to ask your team') }}</h2>
            @if ($unlocked)
                <ul class="list-disc space-y-1.5 pl-5 text-sm text-stone-700">
                    @foreach ($b['questions'] as $question)
                        <li>{{ $question }}</li>
                    @endforeach
                </ul>
            @else
                @include('reports.partials.web.locked-text', ['lines' => 3])
            @endif
        </section>
    @endif

    {{-- 9. Hand-off --}}
    <section class="mb-5 rounded-xl bg-stone-900 p-7 text-stone-50">
        <h2 class="mb-1 text-base font-bold !text-stone-50">{{ __('Forward the developer report to your engineer') }}</h2>
        <p class="text-sm text-stone-300">{{ __('It has every finding with the exact files, evidence and recommended fix.') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <a class="inline-block rounded-lg bg-primary-500 px-5 py-2.5 font-bold text-stone-900 no-underline" href="{{ $tabUrls['technical'] }}">{{ __('Open the developer report') }}</a>
            @if (! $isSample && $unlocked && $report->pdf_path !== null)
                <a class="inline-block rounded-lg border border-stone-600 px-5 py-2.5 font-bold text-stone-50 no-underline" href="{{ route('reports.download', ['auditReport' => $report->uuid, 'variant' => 'business']) }}">{{ __('Business PDF') }}</a>
                <a class="inline-block rounded-lg border border-stone-600 px-5 py-2.5 font-bold text-stone-50 no-underline" href="{{ route('reports.download', ['auditReport' => $report->uuid, 'variant' => 'technical']) }}">{{ __('Developer PDF') }}</a>
            @endif
        </div>
    </section>

    <p class="text-center text-xs text-stone-500">
        {{ __('Scores are measured by automated analysis; the explanations are written for a non-technical reader. Reply to your report email to talk any of it through with an engineer.') }}
    </p>
</div>
</body>
</html>
