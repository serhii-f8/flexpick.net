@php
    // The pipeline writes this log incrementally and may die mid-write, so every
    // field here is treated as optional rather than guaranteed.
    $entries = collect($getState() ?? [])->filter(fn ($entry): bool => is_array($entry))->values();
@endphp

@if ($entries->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No processing activity recorded yet.') }}</p>
@else
    <ol class="relative ms-1 space-y-4 border-s border-gray-200 ps-6 dark:border-gray-700">
        @foreach ($entries as $entry)
            @php
                $step = (string) ($entry['step'] ?? __('unknown step'));
                $isFailure = str_contains(mb_strtolower($step), 'fail');

                try {
                    $at = isset($entry['at']) ? \Illuminate\Support\Carbon::parse($entry['at']) : null;
                } catch (\Throwable) {
                    $at = null;
                }
            @endphp

            <li class="relative">
                <span @class([
                    'absolute -start-[1.9rem] mt-1.5 size-3 rounded-full ring-4 ring-white dark:ring-gray-900',
                    'bg-danger-500' => $isFailure,
                    'bg-primary-500' => ! $isFailure,
                ])></span>

                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span @class([
                        'text-sm font-medium',
                        'text-danger-600 dark:text-danger-400' => $isFailure,
                        'text-gray-950 dark:text-white' => ! $isFailure,
                    ])>{{ $step }}</span>

                    <span
                        class="text-xs text-gray-500 dark:text-gray-400"
                        @if ($at) title="{{ $at->toDateTimeString() }}" @endif
                    >{{ $at?->diffForHumans() ?? (string) ($entry['at'] ?? '—') }}</span>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $entry['message'] ?? '' }}</p>
            </li>
        @endforeach
    </ol>
@endif
