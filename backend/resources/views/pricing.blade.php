<x-layouts.app>
    <x-slot name="title">
        {{ __('Plans & Pricing') }}
    </x-slot>

    <div class="fp-pricing">
        <header class="fp-pricing-head">
            <p class="fp-eyebrow">{{ __('Plans & Pricing') }}</p>
            <h1 class="fp-pricing-title">{{ __('Pick the plan that fits') }}</h1>
            <p class="fp-pricing-lead">
                {{ __('Every plan includes full reports, PDF export and re-audit trends. Switch or cancel whenever you like.') }}
            </p>

            @guest
                <p class="mt-4 text-sm">
                    <x-link href="{{ route('register') }}">{{ __('Create an account') }}</x-link>
                    {{ __('or') }}
                    <x-link href="{{ route('login') }}">{{ __('log in') }}</x-link>
                    {{ __('to manage your subscription.') }}
                </p>
            @endguest
        </header>

        <x-plans.all calculate-saving-rates="true" show-default-product="1"/>

        <section class="fp-pricing-section">
            <div class="fp-pricing-section-head">
                <h2 class="fp-pricing-section-title">{{ __('Or buy a single audit') }}</h2>
                <p class="fp-pricing-lead">{{ __('No subscription. One repository, one report, delivered to your inbox.') }}</p>
            </div>
            <x-products.all sort-by="price" />
        </section>
    </div>
</x-layouts.app>
