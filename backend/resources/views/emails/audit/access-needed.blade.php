<x-layouts.email>
    <x-slot name="preview">
        {{ __('One more step for your codebase audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            @if ($auditRequest->repo_url)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We couldn't access :url — it may be private, or too large for automated analysis.", ['url' => $auditRequest->repo_url]) }}
                </p>
            @else
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("You didn't include a repository link, so we couldn't start the automated analysis.") }}
                </p>
            @endif
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Reply to this email with a public repository URL, or grant read access to our review account, and we\'ll take it from there. Happy to sign an NDA first.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
