<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingTest extends FeatureTest
{
    private function finding(array $overrides = []): Finding
    {
        return new Finding(
            tool: $overrides['tool'] ?? 'semgrep',
            ruleId: $overrides['ruleId'] ?? 'flexpick.php.sql-interpolation',
            ruleFamily: $overrides['ruleFamily'] ?? 'php.injection',
            severity: $overrides['severity'] ?? Severity::HIGH,
            path: $overrides['path'] ?? 'app/Http/Controllers/UserController.php',
            line: $overrides['line'] ?? 42,
            message: $overrides['message'] ?? 'SQL built by string interpolation.',
            dimension: $overrides['dimension'] ?? 'security_hygiene',
        );
    }

    public function test_carries_the_score_dimension_it_feeds(): void
    {
        $this->assertSame('security_hygiene', $this->finding()->dimension);
        $this->assertSame('structure', $this->finding(['dimension' => 'structure'])->dimension);
    }

    public function test_dimension_must_be_one_of_the_five_score_dimensions(): void
    {
        $this->assertContains($this->finding()->dimension, Finding::DIMENSIONS);
    }

    public function test_fingerprint_ignores_the_dimension(): void
    {
        // Dimension is routing metadata, not identity — two tools disagreeing
        // about it must still merge rather than double-count the same defect.
        $this->assertSame(
            $this->finding(['dimension' => 'security_hygiene'])->fingerprint(),
            $this->finding(['dimension' => 'structure'])->fingerprint(),
        );
    }

    public function test_fingerprint_ignores_the_reporting_tool(): void
    {
        // Two tools reporting the same defect at the same place must collide,
        // so the deduplicator can merge them.
        $a = $this->finding(['tool' => 'semgrep']);
        $b = $this->finding(['tool' => 'gitleaks']);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_fingerprint_distinguishes_family_path_and_line(): void
    {
        $base = $this->finding();

        $this->assertNotSame($base->fingerprint(), $this->finding(['ruleFamily' => 'php.xss'])->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->finding(['path' => 'app/Other.php'])->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->finding(['line' => 43])->fingerprint());
    }

    public function test_fingerprint_is_stable_for_line_less_findings(): void
    {
        $a = $this->finding(['line' => null]);
        $b = $this->finding(['line' => null]);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_directory_truncates_to_the_configured_depth(): void
    {
        $this->assertSame('app/Http', $this->finding()->directory(2));
        $this->assertSame('app', $this->finding()->directory(1));
    }

    public function test_root_level_files_group_under_a_dot(): void
    {
        $this->assertSame('.', $this->finding(['path' => 'composer.lock'])->directory(2));
    }

    public function test_severity_weights_come_from_configuration(): void
    {
        config()->set('audit.findings.severity_weights.critical', 999);

        $this->assertSame(999, Severity::CRITICAL->weight());
    }

    public function test_max_returns_the_most_severe(): void
    {
        $this->assertSame(
            Severity::CRITICAL,
            Severity::max(Severity::LOW, Severity::CRITICAL, Severity::MEDIUM),
        );
    }
}
