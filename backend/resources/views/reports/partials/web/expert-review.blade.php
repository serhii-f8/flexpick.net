@php
    $expertReview = $payload['expert_review'] ?? null;
@endphp

@if ($expertReview !== null)
    <h2 class="mb-3 text-base font-bold">{{ __('Human expert review') }}</h2>

    <p class="rounded-lg border border-stone-200 border-l-4 border-l-green-600 bg-green-50 px-4 py-3 text-sm text-green-900">
        {{ __('Reviewed by a human expert.') }}
    </p>

    <p class="mt-3">{{ $expertReview['expert_summary'] }}</p>

    @if (trim($expertReview['review_notes'] ?? '') !== '')
        <div class="mt-2 text-sm text-stone-700">
            {{ $expertReview['review_notes'] }}
        </div>
    @endif

    <p class="mt-3 text-xs text-stone-500">
        {{ __('Reviewed by :name on :date', [
            'name' => $expertReview['reviewed_by'],
            'date' => \Illuminate\Support\Carbon::parse($expertReview['reviewed_at'])->format(config('app.datetime_format')),
        ]) }}
    </p>
@endif
