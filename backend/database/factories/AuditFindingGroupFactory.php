<?php

namespace Database\Factories;

use App\Models\AuditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFindingGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audit_request_id' => AuditRequest::factory(),
            'rule_family' => 'php.injection',
            'directory' => 'app/Http',
            'severity' => 'high',
            'dimension' => 'security_hygiene',
            'count' => 5,
            'score' => 200,
            'examples' => [['path' => 'app/Http/Controller.php', 'line' => 10]],
            'tools' => ['semgrep'],
        ];
    }
}
