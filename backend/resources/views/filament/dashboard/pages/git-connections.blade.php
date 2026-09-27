<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($this->connections() as $provider => $connection)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $connection['label'] }}</h3>

                @if ($connection['connected'])
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Connected as :account', ['account' => $connection['account']]) }}</p>
                    <div class="mt-3 flex gap-2">
                        <a href="{{ $this->connectUrl($provider) }}" class="inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200">{{ __('Reconnect') }}</a>
                        <button type="button" wire:click="disconnect('{{ $provider }}')" wire:confirm="{{ __('Disconnect this account?') }}" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">{{ __('Disconnect') }}</button>
                    </div>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Not connected') }}</p>
                    <a href="{{ $this->connectUrl($provider) }}" class="mt-3 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('Connect') }}</a>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
