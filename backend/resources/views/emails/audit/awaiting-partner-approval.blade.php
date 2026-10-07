<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your audit is booked') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Your audit is booked. The order (:amount) is with :partner, who will confirm your payment — we start the analysis as soon as they do, and email you the report.', ['amount' => $amount, 'partner' => $partner?->name ?? __('your FlexPick partner')]) }}
            </p>
            @if ($partner !== null)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Questions about payment? Contact :name at', ['name' => $partner->name]) }}
                    <a href="mailto:{{ $partner->email }}" style="color: #2563eb; text-decoration: underline;">{{ $partner->email }}</a>.
                </p>
            @endif
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('If the repository is private, connect your GitHub, GitLab or Bitbucket account from your dashboard meanwhile, so we can read it.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
