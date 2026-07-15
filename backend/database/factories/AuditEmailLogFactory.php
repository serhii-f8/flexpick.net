<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AuditEmailLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mailable' => 'AuditReportReady',
            'recipient' => $this->faker->safeEmail(),
            'subject' => 'Your codebase health report is ready',
            'body' => '<p>Report body</p>',
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'last_error' => 'Connection refused']);
    }
}
