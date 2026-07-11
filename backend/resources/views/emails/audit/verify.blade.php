<x-layouts.email>
    <x-slot name="preview">
        {{ __('Confirm your email to start your free audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Confirm your email address and we\'ll start your free codebase audit right away.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                <a href="{{ $verificationUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                    {{ __('Confirm my email') }}
                </a>
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                {{ __('This link expires in :hours hours. If you didn\'t request an audit from FlexPick, you can ignore this email.', ['hours' => config('audit.verification_link_hours')]) }}
            </p>
        </td>
    </tr>
</x-layouts.email>
