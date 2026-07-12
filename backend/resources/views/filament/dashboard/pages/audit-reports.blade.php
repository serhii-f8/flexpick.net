<x-filament-panels::page>
    @if ($allowance > 0)
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
                {{ __(':remaining of :allowance analyses left this month', ['remaining' => $remainingRuns, 'allowance' => $allowance]) }}
            </p>
        </x-filament::section>
    @endif

    @foreach ($repoGroups as $repoUrl => $group)
        @if ($group['scores']->count() > 1)
            <x-filament::section class="mb-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ $repoUrl }}</p>
                        <p class="text-sm text-gray-500">{{ __('Health trend across :n audits', ['n' => $group['scores']->count()]) }}</p>
                    </div>
                    @php
                        $scores = $group['scores'];
                        $max = max(1, $scores->max());
                        $points = $scores->map(fn ($s, $i) => ($i * (120 / max(1, $scores->count() - 1))).','.(36 - ($s / $max) * 32))->implode(' ');
                    @endphp
                    <div class="flex items-center gap-3">
                        <svg width="120" height="40" viewBox="0 0 120 40" fill="none">
                            <polyline points="{{ $points }}" stroke="currentColor" stroke-width="2" class="text-primary-500" />
                        </svg>
                        @if ($allowance > 0)
                            <x-filament::button size="sm" color="gray" wire:click="launchAudit('{{ $repoUrl }}')">{{ __('Re-run') }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endforeach

    <div class="space-y-4">
        @forelse ($reports as $report)
            <div class="rounded-lg border p-4">
                <div class="font-medium">{{ $report->auditRequest->repo_url ?? __('Repository audit') }}</div>
                <div class="text-sm text-gray-500">{{ $report->created_at->format(config('app.datetime_format', 'd/m/Y H:i')) }}</div>
                <div class="mt-2 flex gap-4">
                    <a class="text-primary-600 underline" href="{{ route('reports.download', $report) }}">{{ __('Download PDF') }}</a>
                    <a class="text-primary-600 underline" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">{{ __('View online') }}</a>
                </div>
            </div>
        @empty
            <p>{{ __('No audit reports yet.') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
