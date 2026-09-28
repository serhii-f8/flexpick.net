<x-layouts.email>
    <x-slot name="preview">
        {{ $tooLarge ? __('Your repository is larger than our self-serve audits cover') : __('Your audit needs more credit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('We sized :url before analyzing it:', ['url' => $auditRequest->repo_url]) }}
                {{ $auditRequest->failure_reason }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __("This audit request is now closed and won't restart on its own. You haven't been charged for it.") }}
            </p>
            @if ($tooLarge)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Codebases this size need a scoped audit — reply to this email and we\'ll put one together with you.') }}
                </p>
            @else
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Buy more credit or upgrade your plan, then start a new audit — the repository is already filled in.') }}
                </p>
                <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                    <a href="{{ $rerunUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                        {{ __('Buy credit and run again') }}
                    </a>
                </p>
            @endif
        </td>
    </tr>
</x-layouts.email>
