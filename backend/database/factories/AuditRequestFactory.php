<?php

namespace Database\Factories;

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
}
