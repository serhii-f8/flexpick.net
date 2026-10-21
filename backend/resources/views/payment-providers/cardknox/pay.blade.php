<x-layouts.app>
    @push('head')
        <script src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifields.min.js"></script>
    @endpush

    @php
        $fieldClass = 'mt-2 flex h-12 w-full items-center overflow-hidden rounded-xl border border-white/10 bg-surface transition focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/30';
        $labelClass = 'block text-sm font-medium text-cream-200';
    @endphp

    <div class="mx-auto w-full max-w-lg px-4 py-16">
        <h1 class="text-2xl font-semibold text-cream-100">{{ $title }}</h1>
        <p class="mt-2 text-cream-200/80">{{ $summary }}</p>

        @if ($errors->any())
            <div class="mt-6 rounded-xl border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form id="cardknox-form" method="POST" action="{{ $action }}"
              class="mt-8 space-y-5 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
            @csrf

            <div>
                <span class="{{ $labelClass }}">{{ __('Card number') }}</span>
                <div class="{{ $fieldClass }}">
                    <iframe data-ifields-id="card-number" data-ifields-placeholder="1234 1234 1234 1234"
                            src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                            title="{{ __('Card number') }}" class="h-full w-full border-0"></iframe>
                </div>
                <input data-ifields-id="card-number-token" name="xCardNum" type="hidden">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <label class="{{ $labelClass }}">{{ __('Month') }}
                    <input name="exp_month" inputmode="numeric" maxlength="2" placeholder="MM" required
                           autocomplete="cc-exp-month"
                           class="{{ $fieldClass }} px-4 text-base text-cream-100 placeholder:text-cream-200/40 focus:outline-none">
                </label>
                <label class="{{ $labelClass }}">{{ __('Year') }}
                    <input name="exp_year" inputmode="numeric" maxlength="2" placeholder="YY" required
                           autocomplete="cc-exp-year"
                           class="{{ $fieldClass }} px-4 text-base text-cream-100 placeholder:text-cream-200/40 focus:outline-none">
                </label>
                <div>
                    <span class="{{ $labelClass }}">{{ __('CVV') }}</span>
                    <div class="{{ $fieldClass }}">
                        <iframe data-ifields-id="cvv" data-ifields-placeholder="123"
                                src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                                title="{{ __('CVV') }}" class="h-full w-full border-0"></iframe>
                    </div>
                    <input data-ifields-id="cvv-token" name="xCVV" type="hidden">
                </div>
            </div>

            @if ($offerSaveCard)
                <label class="flex cursor-pointer items-center gap-3 text-sm text-cream-200">
                    <input type="checkbox" name="save_card" value="1"
                           class="size-4 rounded border-white/20 bg-surface accent-primary-500">
                    {{ __('Save this card for future payments') }}
                </label>
            @endif

            <p data-ifields-id="card-data-error" class="text-sm text-red-300 empty:hidden" role="alert"></p>

            <div class="flex items-center gap-4 pt-2">
                <button id="cardknox-submit" type="submit"
                        class="rounded-xl bg-primary-500 px-6 py-3 font-semibold text-ink transition hover:bg-primary-400 disabled:cursor-wait disabled:opacity-50">
                    {{ $buttonLabel }}
                </button>
                <a href="{{ $cancelUrl }}" class="text-sm text-cream-200/80 underline hover:text-cream-100">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setAccount(@js($ifieldsKey), @js(config('app.name')), '1.0');

            // The card inputs live inside Cardknox iframes, so page CSS can't reach them.
            const ifieldStyle = {
                'box-sizing': 'border-box',
                width: '100%',
                height: '46px',
                margin: '0',
                padding: '0 16px',
                border: 'none',
                outline: 'none',
                // Opaque on purpose: the iframe's own page is white behind a transparent input.
                background: '#16130f',
                color: '#f5f5f0',
                'font-size': '16px',
                'font-family': 'ui-sans-serif, system-ui, sans-serif',
            };
            setIfieldStyle('card-number', ifieldStyle);
            setIfieldStyle('cvv', ifieldStyle);

            const form = document.getElementById('cardknox-form');
            const button = document.getElementById('cardknox-submit');

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                button.disabled = true;

                // Swaps the iframe contents for single-use tokens in the hidden inputs.
                getTokens(
                    function () { form.submit(); },
                    function () { button.disabled = false; },
                    30000
                );
            });
        });
    </script>
</x-layouts.app>
