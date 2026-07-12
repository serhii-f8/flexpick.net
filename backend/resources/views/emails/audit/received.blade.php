<x-layouts.email>
    <x-slot name="preview">
        {{ __('We received your audit request') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Thanks for requesting a free codebase audit. Our analysis usually completes within the hour — your health report will land in this inbox.') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('No charge, no strings, honest verdict.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $statusUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Track your audit\'s progress live') }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
