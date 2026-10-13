<div class="space-y-3 text-sm">
    <p>{{ __('In the Cardknox (Sola) portal, open Account Settings → Keys and create an API key and an iFields key. Paste both here.') }}</p>
    <p>{{ __('Then open Settings → Gateway Settings → Webhook and set the postback URL to:') }}</p>
    <code class="block break-all rounded bg-gray-100 p-2 dark:bg-gray-800">{{ route('payments-providers.cardknox.webhook') }}</code>
    <p>{{ __('Set a webhook PIN there and enter the same PIN here. Cardknox cannot be enabled until all three values are saved.') }}</p>
    <p>{{ __('Cardknox accepts USD only. Renewals are charged by the app every hour; Horizon and the scheduler must be running.') }}</p>
</div>
