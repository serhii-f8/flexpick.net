@php
    $expertReview = $payload['expert_review'] ?? null;
@endphp

@if ($expertReview !== null)
    <h2>{{ __('Human expert review') }}</h2>

    <p class="deep-notice" style="border-left-color: #16a34a;">
        {{ __('Reviewed by a human expert.') }}
    </p>

    <p>{{ $expertReview['expert_summary'] }}</p>

    @if (trim($expertReview['review_notes'] ?? '') !== '')
        <div class="risk-detail">
            {{ $expertReview['review_notes'] }}
        </div>
    @endif

    <p class="muted">
        {{ __('Reviewed by :name on :date', [
            'name' => $expertReview['reviewed_by'],
            'date' => \Illuminate\Support\Carbon::parse($expertReview['reviewed_at'])->format(config('app.datetime_format')),
        ]) }}
    </p>
@endif
