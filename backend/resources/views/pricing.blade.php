<x-layouts.app>
    <x-slot name="title">
        {{ __('Plans & Pricing') }}
    </x-slot>

    <div class="mx-4 mt-16">
        <x-heading.h6 class="text-center mt-10 text-primary-500">
            {{ __('Plans & Pricing') }}
        </x-heading.h6>
        <x-heading.h2 class="text-primary-900 text-center">
            {{ __('Pick the plan that fits') }}
        </x-heading.h2>

        @guest
            <p class="text-center mt-4">
                <x-link href="{{ route('register') }}">{{ __('Create an account') }}</x-link>
                {{ __('or') }}
                <x-link href="{{ route('login') }}">{{ __('log in') }}</x-link>
                {{ __('to manage your subscription.') }}
            </p>
        @endguest
    </div>

    <div class="pricing">
        <x-plans.all calculate-saving-rates="true" show-default-product="1"/>
        <x-products.all />
    </div>
</x-layouts.app>
