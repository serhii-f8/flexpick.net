<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your FlexPick account is ready') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Thanks for confirming your email. We created your FlexPick account with this address, so you can follow your audit and give us access to your code.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                <a href="{{ $setPasswordUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                    {{ __('Set your password') }}
                </a>
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                {{ __('Then, from your dashboard:') }}
            </p>
            <ul style="margin: 8px 0 0; padding-left: 20px; line-height: 24px">
                <li>{{ __('see the current status of your report;') }}</li>
                <li>{{ __('connect your GitHub, GitLab or Bitbucket account if the repository is private, so we can read it.') }}</li>
            </ul>
            <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                {{ __('This link works once and expires in :days days. After that, use "Forgot password" on the login page.', ['days' => config('audit.set_password_link_days', 7)]) }}
            </p>
        </td>
    </tr>
</x-layouts.email>
