<x-layouts.focus-center>
    <x-slot name="title">
        {{ __('Partner access only') }}
    </x-slot>

    <div class="mx-4">
        <div class="card max-w-xl bg-base-100 border border-white/10 mx-auto text-center">
            <div class="card-body">
                <x-heading.h3 class="text-cream-100">
                    {{ __('Pricing is available through partner links only') }}
                </x-heading.h3>
                <p>
                    {{ __('FlexPick is sold through partners. Open the link your partner gave you to see their prices, or log in if you already have an account.') }}
                </p>
                <x-button-link.primary href="{{ route('login') }}" class="mt-4 mx-auto">
                    {{ __('Log in') }}
                </x-button-link.primary>
            </div>
        </div>
    </div>
</x-layouts.focus-center>
