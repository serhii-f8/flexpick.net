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
                <div class="mt-6 flex flex-wrap items-center justify-center gap-4">
                    <x-button-link.primary href="{{ route('register') }}" class="text-lg py-3! px-6">
                        {{ __('Sign up') }}
                    </x-button-link.primary>
                    <p class="m-0 text-sm">
                        {{ __('Already have an account?') }}
                        <x-link href="{{ route('login') }}">{{ __('Log in') }}</x-link>
                    </p>
                </div>
            @endguest
        </header>

        {{-- One-time products open first: most visitors want a single report, not a commitment. --}}
        <div x-data="{ catalog: 'products' }">
            <div class="fp-pricing-switch">
                <div class="fp-switch" role="tablist" aria-label="{{ __('Catalog') }}">
                    <button
                        type="button"
                        role="tab"
                        id="catalog-tab-products"
                        aria-controls="catalog-products"
                        :aria-selected="catalog === 'products' ? 'true' : 'false'"
                        @click="catalog = 'products'"
                    >
                        {{ __('One-time products') }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        id="catalog-tab-plans"
                        aria-controls="catalog-plans"
                        :aria-selected="catalog === 'plans' ? 'true' : 'false'"
                        @click="catalog = 'plans'"
                    >
                        {{ __('Subscriptions') }}
                    </button>
                </div>
            </div>

            <section id="catalog-products" role="tabpanel" aria-labelledby="catalog-tab-products" x-show="catalog === 'products'">
                <div class="fp-pricing-section-head">
                    <h2 class="fp-pricing-section-title">{{ __('Buy a single audit') }}</h2>
                    <p class="fp-pricing-lead">{{ __('No subscription. One repository, one report, delivered to your inbox.') }}</p>
                </div>
                <x-products.all sort-by="price" />
            </section>

            <section id="catalog-plans" role="tabpanel" aria-labelledby="catalog-tab-plans" x-show="catalog === 'plans'" x-cloak>
                <div class="fp-pricing-section-head">
                    <h2 class="fp-pricing-section-title">{{ __('Subscribe and keep auditing') }}</h2>
                    <p class="fp-pricing-lead">{{ __('Recurring credits, re-audit trends and the lowest price per report.') }}</p>
                </div>
                <x-plans.all calculate-saving-rates="true" />
            </section>
        </div><!-- /fp-pricing-tabs -->

        <x-plans.default-product />
    </div>
</x-layouts.app>
