<?php

namespace Database\Factories;

use App\Models\AuditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_request_id' => AuditRequest::factory(),
            'user_id' => null,
            'payload' => [
                'summary' => 'Fixture summary.',
                'scores' => ['structure' => 60, 'duplication' => 50, 'testing' => 20, 'dependencies' => 70, 'security_hygiene' => 80, 'overall' => 55],
                'risks' => [['title' => 'No tests', 'impact' => 'high', 'evidence' => '0 test files', 'recommendation' => 'Add a smoke suite']],
                'fix_first_plan' => [['step' => 'Add CI', 'why' => 'Catch regressions', 'effort' => 'S']],
            ],
            'pdf_path' => 'audit-reports/fixture.pdf',
        ];
    }

    public function unlocked(): static
    {
        return $this->state(fn () => ['unlocked_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['unlocked_at' => null, 'pdf_path' => null]);
    }
}
