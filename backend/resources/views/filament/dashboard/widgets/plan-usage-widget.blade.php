<x-filament-widgets::widget class="fi-wi-plan-usage">
    <x-filament::section>
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <x-fp.label>{{ __('Credits this month') }}</x-fp.label>
                <p class="mt-2 font-heading text-lg font-bold tracking-tight text-gray-950 dark:text-white">{{ $planName }}</p>
                @if ($renewsAt)
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Renews :date', ['date' => $renewsAt->format(config('app.date_format', 'd/m/Y'))]) }}
                    </p>
                @endif
            </div>
        </div>

        @if ($bars !== [])
            <div class="mt-5 space-y-4">
                @foreach ($bars as $bar)
                    @php
                        $percent = $bar['total'] > 0 ? min(100, (int) round($bar['used'] / $bar['total'] * 100)) : 0;
                        $left = max(0, $bar['total'] - $bar['used']);
                    @endphp
                    <div>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-gray-950 dark:text-white">{{ $bar['label'] }}</span>
                            <span class="font-mono text-xs tracking-wide {{ $left === 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">
                                {{ __(':used of :total used', ['used' => $bar['used'], 'total' => $bar['total']]) }}
                            </span>
                        </div>
                        <div class="fp-usage-track mt-1.5" role="meter" aria-label="{{ $bar['label'] }}" aria-valuenow="{{ $bar['used'] }}" aria-valuemin="0" aria-valuemax="{{ $bar['total'] }}">
                            <div class="fp-usage-fill {{ $left === 0 ? 'fp-usage-exhausted' : '' }}" style="width: {{ $percent }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                {{ __('No audit credits on this plan. Pick a plan to run audits every month, or buy a single audit when you need one.') }}
            </p>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($changePlanUrl)
                <x-filament::button tag="a" href="{{ $changePlanUrl }}" color="gray" size="sm">
                    {{ __('Change plan') }}
                </x-filament::button>
            @endif
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
    </x-filament::section>
</x-filament-widgets::widget>
