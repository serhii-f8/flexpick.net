<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your free audits are used up — here\'s how to continue') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You\'ve used all of your free codebase audits, so we couldn\'t start this one.') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Two ways to keep going:') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $purchaseUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Run this audit now for $5 — full report included') }}</a>
            </p>
            <p style="margin: 12px 0 0; line-height: 24px">
                <a href="{{ url('/pricing') }}" style="color: #2563eb; text-decoration: underline;">{{ __('Or subscribe from $10/month for 5 analyses') }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
