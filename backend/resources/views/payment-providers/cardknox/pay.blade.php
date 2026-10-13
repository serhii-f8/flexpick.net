<x-layouts.app>
    @push('head')
        <script src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifields.min.js"></script>
    @endpush

    <div class="mx-auto w-full max-w-md px-4 py-16">
        <h1 class="text-2xl font-semibold text-cream-100">{{ $title }}</h1>
        <p class="mt-2 text-cream-300">{{ $summary }}</p>

        @if ($errors->any())
            <div class="mt-6 rounded-md border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form id="cardknox-form" method="POST" action="{{ $action }}" class="mt-8 space-y-4">
            @csrf

            <div>
                <span class="block text-sm text-cream-300">{{ __('Card number') }}</span>
                <iframe data-ifields-id="card-number" data-ifields-placeholder="{{ __('Card number') }}"
                        src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                        title="{{ __('Card number') }}" class="mt-1 h-12 w-full rounded-md bg-cream-50"></iframe>
                <input data-ifields-id="card-number-token" name="xCardNum" type="hidden">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <label class="block text-sm text-cream-300">{{ __('Month') }}
                    <input name="exp_month" inputmode="numeric" maxlength="2" placeholder="MM" required
                           class="mt-1 h-12 w-full rounded-md bg-cream-50 px-3 text-ink">
                </label>
                <label class="block text-sm text-cream-300">{{ __('Year') }}
                    <input name="exp_year" inputmode="numeric" maxlength="2" placeholder="YY" required
                           class="mt-1 h-12 w-full rounded-md bg-cream-50 px-3 text-ink">
                </label>
                <div>
                    <span class="block text-sm text-cream-300">{{ __('CVV') }}</span>
                    <iframe data-ifields-id="cvv" data-ifields-placeholder="CVV"
                            src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                            title="{{ __('CVV') }}" class="mt-1 h-12 w-full rounded-md bg-cream-50"></iframe>
                    <input data-ifields-id="cvv-token" name="xCVV" type="hidden">
                </div>
            </div>

            @if ($offerSaveCard)
                <label class="flex items-center gap-2 text-sm text-cream-300">
                    <input type="checkbox" name="save_card" value="1">
                    {{ __('Save this card for future payments') }}
                </label>
            @endif

            <p data-ifields-id="card-data-error" class="text-sm text-red-300" role="alert"></p>

            <div class="flex items-center gap-4 pt-2">
                <button id="cardknox-submit" type="submit"
                        class="rounded-md bg-amber-400 px-5 py-3 font-semibold text-ink disabled:opacity-50">
                    {{ $buttonLabel }}
                </button>
                <a href="{{ $cancelUrl }}" class="text-sm text-cream-300 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setAccount(@js($ifieldsKey), @js(config('app.name')), '1.0');

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
