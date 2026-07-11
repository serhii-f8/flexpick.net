<x-layouts.email>
    <x-slot name="preview">
        {{ __('About your codebase audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            <p style="margin: 16px 0 0; line-height: 24px">
                {{ __('Our automated analysis hit a snag with your repository. A human is on it — we\'ll follow up personally within one business day.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
