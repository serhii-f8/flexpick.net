<x-filament-panels::page>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 font-medium">{{ __('Stage') }}</th>
                        <th class="py-2 font-medium">{{ __('Last 7 days') }}</th>
                        <th class="py-2 font-medium">{{ __('Last 30 days') }}</th>
                        <th class="py-2 font-medium">{{ __('% of submitted (30d)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($last30 as $stage => $count)
                        <tr>
                            <td class="py-2 font-medium text-gray-950 dark:text-white">{{ $stage }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $last7[$stage] }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $count }}</td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                {{ $last30['submitted'] > 0 ? round($count / $last30['submitted'] * 100) . '%' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('submitted → verified → queued → report_sent → report_viewed → unlock_started → unlock_paid is the paid-report funnel; awaiting_payment, run_purchased and failed are side branches.') }}
        </p>
    </x-filament::section>
</x-filament-panels::page>
