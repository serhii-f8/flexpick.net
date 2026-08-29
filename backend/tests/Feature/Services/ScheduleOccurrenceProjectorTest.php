<?php

namespace Tests\Feature\Services;

use App\Models\AuditSchedule;
use App\Services\AuditReport\ScheduleOccurrenceProjector;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class ScheduleOccurrenceProjectorTest extends FeatureTest
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_schedule_projects_every_matching_weekday_from_today_onward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10')); // exact date irrelevant; only its weekday and month matter
        $today = Carbon::now();
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => $today->dayOfWeek]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, $today->copy()->startOfMonth());

        $expected = [];
        $cursor = $today->copy();
        while ($cursor->month === $today->month) {
            $expected[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        $this->assertSame($expected, array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_weekly_schedule_without_day_of_week_projects_nothing(): void
    {
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => null]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::now()->startOfMonth());

        $this->assertSame([], $dates);
    }

    public function test_monthly_schedule_projects_the_anchor_day_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));
        $schedule = AuditSchedule::factory()->make([
            'frequency' => 'monthly',
            'last_run_at' => Carbon::parse('2026-07-15'),
        ]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-08-01'));

        $this->assertSame(['2026-08-15'], array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_monthly_schedule_clamps_the_anchor_day_to_a_shorter_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-01'));
        $schedule = AuditSchedule::factory()->make([
            'frequency' => 'monthly',
            'last_run_at' => Carbon::parse('2026-01-31'),
        ]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-02-01'));

        $this->assertSame(['2026-02-28'], array_map(fn ($d) => $d->toDateString(), $dates)); // 2026 is not a leap year
    }

    public function test_a_past_month_projects_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        $today = Carbon::now();
        $schedule = AuditSchedule::factory()->make(['frequency' => 'weekly', 'day_of_week' => $today->dayOfWeek]);

        $dates = app(ScheduleOccurrenceProjector::class)->upcomingDatesInMonth($schedule, Carbon::parse('2026-07-01'));

        $this->assertSame([], $dates);
    }
}
