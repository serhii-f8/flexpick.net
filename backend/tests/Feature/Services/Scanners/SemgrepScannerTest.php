<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SemgrepScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use App\Constants\AuditTier;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class SemgrepScannerTest extends FeatureTest
{
    private function normalize(): array
    {
        $sarif = json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/semgrep.sarif.json')),
            true,
        );

        return app(SemgrepScanner::class)->normalize($sarif);
    }

    public function test_normalizes_every_result(): void
    {
        $this->assertCount(2, $this->normalize());
    }

    public function test_maps_sarif_levels_to_severities(): void
    {
        $findings = $this->normalize();

        $this->assertSame(Severity::HIGH, $findings[0]->severity);    // error
        $this->assertSame(Severity::MEDIUM, $findings[1]->severity);  // warning
    }

    public function test_rule_family_comes_from_the_rule_id_namespace(): void
    {
        $this->assertSame('php.injection', $this->normalize()[0]->ruleFamily);
        $this->assertSame('common.configuration', $this->normalize()[1]->ruleFamily);
    }

    public function test_dimension_is_resolved_from_rule_metadata(): void
    {
        $scanner = app(SemgrepScanner::class);

        $this->assertSame('security_hygiene', $scanner->dimensionFor('flexpick.php.sql-interpolation'));
        $this->assertSame('structure', $scanner->dimensionFor('flexpick.common.debug-in-production'));
    }

    public function test_dimension_is_null_for_an_unknown_rule(): void
    {
        $this->assertNull(app(SemgrepScanner::class)->dimensionFor('flexpick.nonexistent.rule'));
    }

    public function test_findings_carry_the_dimension_declared_by_their_rule(): void
    {
        // This is what ScoreCalculator routes on — a structure-tagged rule
        // must not end up scoring security_hygiene (spec §5.3, §7.1).
        $findings = $this->normalize();

        $this->assertSame('security_hygiene', $findings[0]->dimension);
        $this->assertSame('structure', $findings[1]->dimension);
    }

    public function test_message_is_the_rule_description_not_matched_source(): void
    {
        $this->assertSame('SQL is assembled by string interpolation.', $this->normalize()[0]->message);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.semgrep.bin', '/nonexistent/semgrep');

        $this->assertFalse(app(SemgrepScanner::class)->isAvailable());
    }

    public function test_scan_reads_the_reported_sarif_file_end_to_end(): void
    {
        // Regression: scan() previously called a decode() method that did not
        // exist on this class, a fatal error on every real invocation that no
        // test caught because every other test here calls normalize()
        // directly. Process::fake() simulates the binary writing its SARIF
        // report to the --output path so scan()'s full path executes.
        $sarifFixture = (string) file_get_contents(
            base_path('tests/Feature/Services/Fixtures/Scanners/semgrep.sarif.json'),
        );

        Process::fake(function ($process) use ($sarifFixture) {
            $command = $process->command;
            $outputIndex = array_search('--output', $command, true);
            file_put_contents($command[$outputIndex + 1], $sarifFixture);

            return Process::result();
        });

        $context = new RepoContext(
            path: base_path('tests/Feature/Services/Fixtures/Scanners'),
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );

        $findings = app(SemgrepScanner::class)->scan($context);

        $this->assertCount(2, $findings);
    }
}
