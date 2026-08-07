@php($scores = collect($scores)->except('overall'))

<div class="space-y-2">
    @foreach ($scores as $key => $value)
        @php($label = __(ucfirst(str_replace('_', ' ', $key))))
        <div>
            <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                <span>{{ $label }}</span>
                <span class="font-medium">{{ $value }}</span>
            </div>
            <div
                class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                role="meter"
                aria-valuenow="{{ $value }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="{{ $label }}"
            >
                <div
                    class="h-full {{ $value >= 70 ? 'bg-success-500' : ($value >= 50 ? 'bg-warning-500' : 'bg-danger-500') }}"
                    style="width: {{ max(0, min(100, (int) $value)) }}%"
                ></div>
            </div>
        </div>
    @endforeach
</div>
