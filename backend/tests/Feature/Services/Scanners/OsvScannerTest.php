<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\DependencyAuditor;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\OsvScanner;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\ScannerSkipped;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
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

    public function test_an_errored_audit_yields_no_findings_from_normalize(): void
    {
        $this->assertSame([], app(OsvScanner::class)->normalize(['error' => 'osv_unreachable']));
    }

    private function scanWith(array $audit): array
    {
        $auditor = \Mockery::mock(DependencyAuditor::class);
        $auditor->shouldReceive('audit')->andReturn($audit);
        $context = new RepoContext(
            path: sys_get_temp_dir(),
            tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
        );

        return (new OsvScanner($auditor))->scan($context);
    }

    public function test_an_unreachable_osv_is_a_skip(): void
    {
        $this->expectExceptionObject(new ScannerSkipped('osv_unreachable'));
        $this->scanWith(['packages_scanned' => 10, 'error' => 'osv_unreachable']);
    }

    public function test_declared_dependencies_with_no_lockfile_is_a_skip(): void
    {
        $this->expectExceptionObject(new ScannerSkipped('no_lockfile'));
        $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => true, 'unscannable_lockfiles' => []]);
    }

    public function test_only_an_unreadable_lockfile_is_a_skip_naming_it(): void
    {
        $this->expectExceptionObject(new ScannerSkipped('lockfile_unreadable'));
        $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => true, 'unscannable_lockfiles' => ['bun.lockb']]);
    }

    public function test_a_repository_without_dependencies_is_measured_clean(): void
    {
        $this->assertSame([], $this->scanWith(['packages_scanned' => 0, 'has_declared_dependencies' => false]));
    }

    public function test_finding_path_is_the_lockfile_that_pinned_the_package(): void
    {
        $findings = app(OsvScanner::class)->normalize(['vulnerabilities' => [[
            'package' => 'lodash', 'version' => '4.17.0', 'ecosystem' => 'npm', 'vulns' => ['GHSA-1'], 'lockfile' => 'frontend/bun.lock',
        ]]]);

        $this->assertSame('frontend/bun.lock', $findings[0]->path);
    }

    public function test_is_always_available_because_it_needs_no_binary(): void
    {
        $this->assertTrue(app(OsvScanner::class)->isAvailable());
    }

    public function test_a_repository_in_an_unsupported_ecosystem_is_not_measured_clean(): void
    {
        $auditor = \Mockery::mock(DependencyAuditor::class);
        $auditor->shouldReceive('audit')->andReturn(['packages_scanned' => 0, 'has_declared_dependencies' => false]);
        $context = new RepoContext(
            path: sys_get_temp_dir(),
            tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
            inventory: new SccInventory(
                files: [],
                languages: [],
                totalLoc: 0,
                totalComplexity: 0,
                excluded: [['path' => 'svc/poetry.lock', 'loc' => 10, 'reason' => 'lockfile']],
            ),
        );

        $this->expectExceptionObject(new ScannerSkipped('unsupported_ecosystem'));
        (new OsvScanner($auditor))->scan($context);
    }
}
