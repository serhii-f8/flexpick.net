<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => Tenant::factory(),
            'repo_url' => 'https://github.com/acme/'.fake()->slug(2),
            'frequency' => 'weekly',
            'last_run_at' => null,
        ];
    }
}
