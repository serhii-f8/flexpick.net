<?php

namespace App\Services\AuditReport;

use App\Console\Commands\RunScheduledAudits;
use App\Models\AuditSchedule;
use Illuminate\Support\Carbon;

/**
 * Projects the schedule's likely future occurrence dates within a given
 * calendar month, purely for display. This mirrors -- but does not
 * replace -- RunScheduledAudits::isDue()'s authoritative due-check.
 */
class ScheduleOccurrenceProjector
{
    /** @return list<Carbon> */
    public function upcomingDatesInMonth(AuditSchedule $schedule, Carbon $monthStart): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $today = Carbon::today();

        if ($schedule->frequency === 'weekly') {
            if ($schedule->day_of_week === null) {
                return [];
            }

            $dates = [];
            $cursor = $monthStart->copy();

            while ($cursor->lte($monthEnd)) {
                if ($cursor->dayOfWeek === $schedule->day_of_week && $cursor->gte($today)) {
                    $dates[] = $cursor->copy();
                }
                $cursor->addDay();
            }

            return $dates;
        }

        $anchorDay = $schedule->day_of_month ?? ($schedule->last_run_at ?? $schedule->created_at ?? $today)->day;
        $day = min($anchorDay, $monthEnd->day);
        $date = $monthStart->copy()->addDays($day - 1);

        // A quarterly schedule skips the two months after each run.
        if ($schedule->frequency === 'quarterly' && $schedule->last_run_at !== null
            && $date->lt($schedule->last_run_at->copy()->startOfDay()->addDays(RunScheduledAudits::QUARTER_MIN_DAYS))) {
            return [];
        }

        return ($date->gte($today) && $date->lte($monthEnd)) ? [$date] : [];
    }
}
