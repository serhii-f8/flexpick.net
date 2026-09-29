<x-filament-panels::page>
    @if (session('status'))
        <div role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->has('git_connection'))
        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {{ $errors->first('git_connection') }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($this->connections() as $provider => $connection)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $connection['label'] }}</h3>

                @if ($connection['connected'])
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Connected as :account', ['account' => $connection['account']]) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Connected by :name', ['name' => $connection['connected_by']]) }}</p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="connect('{{ $provider }}')" class="inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200">{{ __('Reconnect') }}</button>
                        <button type="button" wire:click="disconnect('{{ $provider }}')" wire:confirm="{{ __('Disconnect this account?') }}" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">{{ __('Disconnect') }}</button>
                    </div>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Not connected') }}</p>
                    <button type="button" wire:click="connect('{{ $provider }}')" class="mt-3 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('Connect') }}</button>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
