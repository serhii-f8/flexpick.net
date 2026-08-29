@php
    $monthStart = $calendarMonth->copy()->startOfMonth();
    $monthEnd = $calendarMonth->copy()->endOfMonth();
    $leadingBlanks = $monthStart->dayOfWeek;
    $upcomingDates = collect($calendarData['upcoming'])->map->toDateString();
@endphp

<div class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
    <div class="mb-2 flex items-center justify-between">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="prevCalendarMonth" :label="__('Previous month')" size="sm" />
        <p class="text-sm font-medium">{{ $monthStart->format('F Y') }}</p>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="nextCalendarMonth" :label="__('Next month')" size="sm" />
    </div>

    <div class="grid grid-cols-7 gap-1 text-center text-xs">
        @foreach ([__('Su'), __('Mo'), __('Tu'), __('We'), __('Th'), __('Fr'), __('Sa')] as $dayLabel)
            <div class="text-gray-400">{{ $dayLabel }}</div>
        @endforeach

        @for ($i = 0; $i < $leadingBlanks; $i++)
            <div></div>
        @endfor

        @for ($day = 1; $day <= $monthEnd->day; $day++)
            @php
                $date = $monthStart->copy()->addDays($day - 1);
                $dateKey = $date->toDateString();
                $run = $calendarData['past']->get($dateKey);
            @endphp

            <div class="flex flex-col items-center gap-0.5 rounded p-1" title="{{ $run?->status === 'skipped' ? __('Skipped: :reason', ['reason' => $run->reason]) : ($run !== null ? __('Audit completed') : ($upcomingDates->contains($dateKey) ? __('Scheduled') : '')) }}">
                <span>{{ $day }}</span>
                @if ($run?->status === 'completed')
                    <span class="audit-calendar-day-completed h-1.5 w-1.5 rounded-full bg-success-500"></span>
                @elseif ($run?->status === 'skipped')
                    <span class="audit-calendar-day-skipped h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                @elseif ($upcomingDates->contains($dateKey))
                    <span class="audit-calendar-day-scheduled h-1.5 w-1.5 rounded-full border border-primary-400"></span>
                @endif
            </div>
        @endfor
    </div>
</div>
