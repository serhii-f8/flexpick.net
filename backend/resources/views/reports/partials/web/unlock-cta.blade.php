{{-- Shared by both reports: one unlock opens the business and the developer report. --}}
<div class="rounded-xl bg-stone-900 p-7 text-center text-stone-50 mb-5">
    <h2 class="text-base font-bold mb-3">{{ __('Unlock full report') }}</h2>
    <p class="text-stone-300">{{ __('Get every finding\'s evidence and recommendation, the prioritized fix-first plan, and PDF export.') }}</p>
    <a class="inline-block rounded-lg bg-primary-500 px-6 py-3 font-bold text-stone-900 no-underline" href="{{ $unlockUrl }}">{{ ($quoteCatalogPrices ?? true) ? __('Unlock for $5') : __('Unlock the full report') }}</a>
    @php($cheapestPlan = collect(config('pricing.subscriptions'))->sortBy('price')->first())
    <a class="inline-block rounded-lg border border-stone-600 px-6 py-3 font-bold text-stone-50 no-underline" href="{{ route('register') }}">{{ ($quoteCatalogPrices ?? true) ? __('Or subscribe from $:price/mo', ['price' => number_format(($cheapestPlan['price'] ?? 0) / 100)]) : __('Or subscribe to a plan') }}</a>
</div>
