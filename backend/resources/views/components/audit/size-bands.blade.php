{{-- Generated from the same bands AuditRunSizer charges by (AuditSizeBands). --}}
@php($lines = app(\App\Services\AuditReport\AuditSizeBands::class)->describe())

<div {{ $attributes->merge(['class' => 'fp-size-bands']) }}>
    <p class="fp-mono-label">{{ __('Large repositories') }}</p>
    <p class="mt-1 text-sm">{{ __('Bigger codebases take more runs of the audit type you choose:') }}</p>
    <ul class="mt-2 list-disc ps-5 text-sm">
        @foreach ($lines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</div>
