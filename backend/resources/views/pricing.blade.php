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
