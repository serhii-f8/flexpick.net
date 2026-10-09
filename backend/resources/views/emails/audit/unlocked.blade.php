<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your full codebase report is unlocked') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $report->auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Thanks for your purchase — every finding, recommendation, and the fix-first plan in your report is now visible, and both PDFs are attached to your dashboard downloads.') }}
            </p>
            <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                <a href="{{ $reportUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                    {{ __('Open my full report') }}
                </a>
            </p>
            @if ($technicalUrl !== null)
                <p style="margin: 16px 0 0; line-height: 24px; text-align: center;">
                    <a href="{{ $technicalUrl }}">{{ __('Open the developer report') }}</a>
                </p>
            @endif
        </td>
    </tr>
</x-layouts.email>
