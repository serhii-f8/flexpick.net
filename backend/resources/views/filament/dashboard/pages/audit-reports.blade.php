<x-filament-panels::page>
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
