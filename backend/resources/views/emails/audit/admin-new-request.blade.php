<x-layouts.email>
    <x-slot name="preview">
        {{ __('New audit request') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                <strong>{{ $auditRequest->name }}</strong> &lt;{{ $auditRequest->email }}&gt;
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Repo:') }} {{ $auditRequest->repo_url ?? __('(none)') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Message:') }} {{ $auditRequest->message ?? __('(none)') }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Status:') }} {{ $auditRequest->status }}
            </p>
        </td>
    </tr>
</x-layouts.email>
