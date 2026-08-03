<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\OsvScanner;
use Tests\Feature\FeatureTest;

class OsvScannerTest extends FeatureTest
{
    /**
     * Shape matches DependencyAuditor::audit()'s actual output, confirmed
     * against tests/Feature/Services/DependencyAuditorTest.php: each
     * vulnerable package carries an ecosystem and a list of advisory ids —
     * there is no per-vulnerability severity, summary, or manifest field.
     */
    private function audit(): array
    {
        return [
            'packages_scanned' => 120,
            'vulnerable_count' => 2,
            'vulnerabilities' => [
                ['package' => 'acme/parser', 'version' => '1.2.0', 'ecosystem' => 'Packagist',
                    'vulns' => ['GHSA-aaaa', 'GHSA-bbbb']],
                ['package' => 'left-pad', 'version' => '0.1.0', 'ecosystem' => 'npm',
                    'vulns' => ['GHSA-cccc']],
            ],
        ];
    }

    private function normalize(): array
    {
        return app(OsvScanner::class)->normalize($this->audit());
    }

    public function test_emits_one_finding_per_advisory(): void
    {
        // Two vulnerable packages, three advisories between them.
        $this->assertCount(3, $this->normalize());
    }

    public function test_path_is_the_ecosystems_manifest_and_line_is_null(): void
    {
        // OSV findings are manifest-level; they have no source location.
        // They group under dependencies × the manifest's directory (spec §6.1).
        $findings = $this->normalize();

        $this->assertSame('composer.lock', $findings[0]->path);
        $this->assertNull($findings[0]->line);
        $this->assertSame('package-lock.json', $findings[2]->path);
    }

    public function test_rule_family_is_the_dependency_family(): void
    {
        $this->assertSame('dependencies.vulnerable', $this->normalize()[0]->ruleFamily);
    }

    public function test_message_names_the_package_version_and_advisory(): void
    {
        $message = $this->normalize()[0]->message;

        $this->assertStringContainsString('acme/parser', $message);
        $this->assertStringContainsString('1.2.0', $message);
        $this->assertStringContainsString('GHSA-aaaa', $message);
    }

    public function test_dimension_is_dependencies(): void
    {
        $this->assertSame('dependencies', $this->normalize()[0]->dimension);
    }

    public function test_severity_defaults_to_medium_when_osv_reports_no_cvss(): void
    {
        // The querybatch endpoint returns advisory ids only, no CVSS score —
        // every OSV finding is Medium until a future task adds detail lookup.
        foreach ($this->normalize() as $finding) {
            $this->assertSame(Severity::MEDIUM, $finding->severity);
        }
    }

    public function test_an_errored_audit_yields_no_findings(): void
    {
        // Existing degrade-to-zero behaviour is retained as-is (F5.12.2).
        $this->assertSame([], app(OsvScanner::class)->normalize(['error' => 'osv_unreachable']));
    }

    public function test_is_always_available_because_it_needs_no_binary(): void
    {
        $this->assertTrue(app(OsvScanner::class)->isAvailable());
    }
}
