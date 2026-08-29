<?php

namespace Tests\Unit\Models;

use App\Models\AuditSchedule;
use Tests\Feature\FeatureTest;

class AuditScheduleTest extends FeatureTest
{
    public function test_branch_day_of_week_and_last_commit_sha_are_mass_assignable(): void
    {
        $schedule = AuditSchedule::factory()->create([
            'branch' => 'develop',
            'day_of_week' => 3,
            'last_commit_sha' => 'abc123',
        ]);

        $schedule->refresh();

        $this->assertSame('develop', $schedule->branch);
        $this->assertSame(3, $schedule->day_of_week);
        $this->assertIsInt($schedule->day_of_week);
        $this->assertSame('abc123', $schedule->last_commit_sha);
    }

    public function test_the_new_columns_default_to_null(): void
    {
        $schedule = AuditSchedule::factory()->create();

        $this->assertNull($schedule->branch);
        $this->assertNull($schedule->day_of_week);
        $this->assertNull($schedule->last_commit_sha);
        $this->assertNull($schedule->day_of_month);
    }

    public function test_day_of_month_is_mass_assignable_and_casts_to_int(): void
    {
        $schedule = AuditSchedule::factory()->create(['day_of_month' => 15]);

        $schedule->refresh();

        $this->assertSame(15, $schedule->day_of_month);
        $this->assertIsInt($schedule->day_of_month);
    }
}
