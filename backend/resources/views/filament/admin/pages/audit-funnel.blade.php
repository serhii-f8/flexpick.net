<x-filament-panels::page>
    <x-filament::section>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-2">{{ __('Stage') }}</th>
                    <th class="py-2">{{ __('Last 7 days') }}</th>
                    <th class="py-2">{{ __('Last 30 days') }}</th>
                    <th class="py-2">{{ __('% of submitted (30d)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($last30 as $stage => $count)
                    <tr class="border-t">
                        <td class="py-2 font-medium">{{ $stage }}</td>
                        <td class="py-2">{{ $last7[$stage] }}</td>
                        <td class="py-2">{{ $count }}</td>
                        <td class="py-2 text-gray-500">
                            {{ $last30['submitted'] > 0 ? round($count / $last30['submitted'] * 100) . '%' : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-4 text-xs text-gray-500">
            {{ __('submitted → verified → queued → report_sent → report_viewed → unlock_started → unlock_paid is the paid-report funnel; awaiting_payment, run_purchased and failed are side branches.') }}
        </p>
    </x-filament::section>
</x-filament-panels::page>
