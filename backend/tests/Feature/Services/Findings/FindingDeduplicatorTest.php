<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingDeduplicatorTest extends FeatureTest
{
    private function finding(string $tool, Severity $severity, string $path = 'app/A.php', ?int $line = 10): Finding
    {
        return new Finding(
            tool: $tool,
            ruleId: $tool.'.rule',
            ruleFamily: 'secrets.credential',
            severity: $severity,
            path: $path,
            line: $line,
            message: 'A credential appears to be committed.',
            dimension: 'security_hygiene',
        );
    }

    public function test_merges_findings_sharing_a_fingerprint(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('gitleaks', Severity::CRITICAL),
            $this->finding('semgrep', Severity::MEDIUM),
        ]);

        $this->assertCount(1, $result);
    }

    public function test_merged_finding_keeps_the_highest_severity(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::MEDIUM),
            $this->finding('gitleaks', Severity::CRITICAL),
        ]);

        $this->assertSame(Severity::CRITICAL, $result[0]->finding->severity);
    }

    public function test_merged_finding_records_every_contributing_tool_sorted(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::MEDIUM),
            $this->finding('gitleaks', Severity::CRITICAL),
        ]);

        $this->assertSame(['gitleaks', 'semgrep'], $result[0]->tools);
    }

    public function test_keeps_distinct_findings_apart(): void
    {
        $result = app(FindingDeduplicator::class)->dedupe([
            $this->finding('semgrep', Severity::HIGH, 'app/A.php', 10),
            $this->finding('semgrep', Severity::HIGH, 'app/B.php', 10),
            $this->finding('semgrep', Severity::HIGH, 'app/A.php', 99),
        ]);

        $this->assertCount(3, $result);
    }

    public function test_output_order_does_not_depend_on_input_order(): void
    {
        $a = $this->finding('semgrep', Severity::HIGH, 'app/A.php', 1);
        $b = $this->finding('semgrep', Severity::HIGH, 'app/B.php', 2);
        $c = $this->finding('semgrep', Severity::HIGH, 'app/C.php', 3);

        $deduplicator = app(FindingDeduplicator::class);

        $forward = array_map(fn ($d) => $d->finding->fingerprint(), $deduplicator->dedupe([$a, $b, $c]));
        $reverse = array_map(fn ($d) => $d->finding->fingerprint(), $deduplicator->dedupe([$c, $b, $a]));

        $this->assertSame($forward, $reverse);
    }

    public function test_handles_an_empty_finding_list(): void
    {
        $this->assertSame([], app(FindingDeduplicator::class)->dedupe([]));
    }
}
