<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your audit needs payment — here\'s how to continue') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('This audit needs to be paid for before we can start it.') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Two ways to keep going:') }}
            </p>
            {{-- The price comes from the catalog (config/pricing.php) so it can
                 never drift from what checkout actually charges. --}}
            @php($diagnosticPrice = number_format((\App\Constants\AuditTier::DIAGNOSTIC->priceCents() ?? 0) / 100))
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $purchaseUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Run this audit now for $:price — full report included', ['price' => $diagnosticPrice]) }}</a>
            </p>
            {{-- /pricing requires authentication; this email's reader is not
                 logged in, so register is the reachable next step. Cheapest
                 plan pulled from the catalog for the same reason as the price
                 above -- it drifted out of sync with a hardcoded "$10" once. --}}
            @php($cheapestPlan = collect(config('pricing.subscriptions'))->sortBy('price')->first())
            @php($subscribePrice = number_format(($cheapestPlan['price'] ?? 0) / 100))
            <p style="margin: 12px 0 0; line-height: 24px">
                <a href="{{ route('register') }}" style="color: #2563eb; text-decoration: underline;">{{ __('Or subscribe from $:price/month for :count analyses', ['price' => $subscribePrice, 'count' => $cheapestPlan['audit_diagnostic_credits'] ?? 0]) }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
