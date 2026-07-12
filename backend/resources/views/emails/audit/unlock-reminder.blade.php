<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your full codebase report is one click away') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $report->auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('You started unlocking the full audit report for :repo but didn\'t finish checkout.', ['repo' => $report->auditRequest->repo_url]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('The full report includes the evidence behind every risk, a prioritized fix-first plan, and a PDF export — for $5.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px">
                <a href="{{ $unlockUrl }}" style="color: #2563eb; text-decoration: underline;">{{ __('Finish unlocking my report') }}</a>
            </p>
        </td>
    </tr>
</x-layouts.email>
