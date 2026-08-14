<?php

namespace Database\Factories;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'repo_url' => 'https://github.com/example/repo',
            'message' => $this->faker->sentence(),
            'status' => AuditRequestStatus::NEW->value,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['email_verified_at' => now()]);
    }

    public function freeRun(): static
    {
        // Mirrors AuditEntitlementService::consumeFreeRun() -- free_run and
        // funding must move together, or a fixture can build the exact
        // contradictory state (free-run flagged, allowance-funded) this
        // column exists to prevent.
        return $this->state(fn () => ['free_run' => true, 'funding' => AuditFunding::FREE->value]);
    }

    public function dashboardSource(): static
    {
        return $this->state(fn () => ['source' => 'dashboard']);
    }
}
