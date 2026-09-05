<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your order expired before payment was confirmed') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <h1 class="sm-leading-8" style="margin: 0 0 24px; font-size: 24px; font-weight: 600; color: #000">
                {{ __('Hello,') }}
            </h1>
            <p style="margin: 0; line-height: 24px">
                {{ __('Your order :uuid expired because payment was not confirmed in time. You can place it again at any point.', ['uuid' => $order->uuid]) }}
            </p>
        </td>
    </tr>
</x-layouts.email>
