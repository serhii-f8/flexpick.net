<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Current plan') }}
                </p>
                <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ $planName }}</p>
                @if ($renewsAt)
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Renews :date', ['date' => $renewsAt->format(config('app.date_format', 'd/m/Y'))]) }}
                    </p>
                @endif
            </div>

            @if ($showUpgrade)
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource::getUrl() }}"
                    color="primary"
                    size="sm"
                >
                    {{ __('Upgrade') }}
                </x-filament::button>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($bars as $bar)
                @php($percent = $bar['total'] > 0 ? min(100, (int) round($bar['used'] / $bar['total'] * 100)) : 0)
                <div>
                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ $bar['label'] }}</span>
                        <span class="font-medium">
                            {{ __(':used of :total used', ['used' => $bar['used'], 'total' => $bar['total']]) }}
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full {{ $bar['color'] }}" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
