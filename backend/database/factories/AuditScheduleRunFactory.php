<?php

namespace Database\Factories;

use App\Models\AuditSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditScheduleRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_schedule_id' => AuditSchedule::factory(),
            'scheduled_for' => now()->toDateString(),
            'status' => 'completed',
            'reason' => null,
            'audit_request_id' => null,
            'commit_sha' => fake()->sha1(),
        ];
    }
}
