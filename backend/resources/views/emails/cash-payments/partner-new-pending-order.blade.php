<x-layouts.email>
    <x-slot name="preview">
        {{ __('A cash order is waiting for your confirmation') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <h1 class="sm-leading-8" style="margin: 0 0 24px; font-size: 24px; font-weight: 600; color: #000">
                {{ __('Hello,') }}
            </h1>
            <p style="margin: 0; line-height: 24px">
                {{ __('One of your customers has placed a cash order at :app.', ['app' => config('app.name')]) }}
                <br><br>
                {{ __('Order number:') }} {{ $order->uuid }}<br>
                {{ __('Customer:') }} {{ $order->user?->email }}<br>
                {{ __('Amount:') }} {{ money($amountDue, $order->currency?->code ?? config('app.default_currency')) }}
                <br><br>
                {{ __('Approve it in your dashboard once you have received the cash.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
