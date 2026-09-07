<x-filament-panels::page>
    <p class="text-sm" style="color: var(--fp-muted)">
        {{ __('Your customers pay the prices below and settle in cash with you. A price can never go below the platform price; the difference is your margin.') }}
    </p>

    <x-filament::section :heading="__('Subscription packages')">
        @livewire('filament.dashboard.partner-plan-pricing-table')
    </x-filament::section>

    <x-filament::section :heading="__('One-time reports')">
        @livewire('filament.dashboard.partner-product-pricing-table')
    </x-filament::section>
</x-filament-panels::page>
