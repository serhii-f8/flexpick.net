<x-layouts.email>
    <x-slot name="preview">
        {{ __('Still want your codebase audit?') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You asked for a codebase audit yesterday but haven\'t confirmed your email yet — the audit only starts after that click.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $verifyUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Confirm my email and start the audit') }}</a>
            </p>
            <p style="margin: 16px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                {{ __('This link is valid for 48 hours. If you didn\'t request an audit, you can ignore this email.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
