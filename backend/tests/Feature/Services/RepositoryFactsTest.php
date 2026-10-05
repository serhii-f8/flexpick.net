<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\NotMeasuredReason;
use App\Services\AuditReport\RepositoryFacts;
use Tests\Feature\FeatureTest;

class RepositoryFactsTest extends FeatureTest
{
    private function group(string $family, Severity $severity, int $count): FindingGroup
    {
        return new FindingGroup($family, '.', $severity, $count, 0, [], ['gitleaks'], 'security_hygiene');
    }

    public function test_reads_tooling_facts_from_where_the_collector_stores_them(): void
    {
        $facts = RepositoryFacts::from([
            'tooling' => ['has_ci' => true, 'ci_systems' => ['github_actions', 'jenkins'], 'test_ratio_pct' => 21.0],
            'files_total' => 9157,
            'loc_total' => 1678749,
            'duplication_pct' => 3.2,
            'excluded_summary' => ['generated' => 120, 'docs' => 300],
            'dependency_audit' => ['packages_scanned' => 900, 'vulnerable_count' => 4],
        ], [
            $this->group('secrets.credential', Severity::CRITICAL, 1),
            $this->group('secrets.possible-credential', Severity::HIGH, 2),
            $this->group('secrets.likely-fixture', Severity::LOW, 25),
            $this->group('semgrep.sqli', Severity::HIGH, 9),
        ]);

        $this->assertTrue($facts['has_ci']);
        $this->assertSame(['github_actions', 'jenkins'], $facts['ci_systems']);
        $this->assertSame(21.0, $facts['test_ratio_pct']);
        $this->assertSame(3, $facts['secrets_likely']);
        $this->assertSame(25, $facts['secrets_fixtures']);
        $this->assertSame(4, $facts['vulnerable_dependencies']);
        $this->assertSame(420, $facts['excluded_files']);
    }

    public function test_unmeasured_duplication_is_null_not_zero(): void
    {
        $facts = RepositoryFacts::from(['duplication_pct' => 0.0, 'not_measured' => ['duplication']], []);

        $this->assertNull($facts['duplication_pct']);
    }

    public function test_legacy_metrics_still_render_facts(): void
    {
        $facts = RepositoryFacts::from(['has_ci' => true, 'test_ratio_pct' => 12.5, 'secret_findings' => [['count' => 2]]], []);

        $this->assertTrue($facts['has_ci']);
        $this->assertSame(12.5, $facts['test_ratio_pct']);
        $this->assertSame(2, $facts['secrets_likely']);
        $this->assertSame([], $facts['ci_systems']);
        $this->assertNull($facts['vulnerable_dependencies']);
    }

    public function test_every_reason_code_has_customer_copy(): void
    {
        foreach (['no_report', 'empty_output', 'osv_unreachable', 'no_lockfile', 'lockfile_unreadable', 'timeout', 'unavailable', 'nonzero_exit', 'parse_failure', 'not_run', 'something_new'] as $code) {
            $this->assertNotSame('', NotMeasuredReason::describe($code));
            $this->assertStringNotContainsString('_', NotMeasuredReason::describe($code));
        }
    }
}
