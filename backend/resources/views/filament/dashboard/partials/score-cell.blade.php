@php
    $score = data_get($getRecord()->report?->payload, 'scores.overall');
@endphp
<div class="fi-ta-text px-3 py-4">
    <x-fp.score :value="is_int($score) ? $score : null" size="sm" />
</div>
