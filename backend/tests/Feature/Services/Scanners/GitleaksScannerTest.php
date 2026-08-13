<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\GitleaksScanner;
use Tests\Feature\FeatureTest;

class GitleaksScannerTest extends FeatureTest
{
    /** The clone root the scanner was pointed at — SARIF URIs are absolute. */
    private const ROOT = '/var/www/html/storage/app/audit-workdirs/0199a1f2';

    private function sarif(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/gitleaks.sarif.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(GitleaksScanner::class)->normalize($this->sarif(), self::ROOT);
    }

    public function test_normalizes_every_result(): void
    {
        $this->assertCount(2, $this->normalize());
    }

    public function test_every_gitleaks_finding_is_critical(): void
    {
        // Gitleaks emits no severity of its own; a live credential is the
        // highest-consequence finding the pipeline can produce (spec §5.6).
        foreach ($this->normalize() as $finding) {
            $this->assertSame(Severity::CRITICAL, $finding->severity);
        }
    }

    public function test_carries_path_and_line(): void
    {
        $finding = $this->normalize()[0];

        $this->assertSame('config/services.php', $finding->path);
        $this->assertSame(17, $finding->line);
    }

    public function test_the_matched_secret_never_survives_normalization(): void
    {
        // The fixture's snippet contains these values. F5.2.6 is counts and
        // paths only — no field on Finding may carry them.
        $serialized = json_encode(array_map(fn ($f) => (array) $f, $this->normalize()));

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $serialized);
        $this->assertStringNotContainsString('sk_live_supersecret', $serialized);
    }

    public function test_rule_family_is_the_secrets_family(): void
    {
        $this->assertSame('secrets.credential', $this->normalize()[0]->ruleFamily);
    }

    public function test_tool_is_recorded(): void
    {
        $this->assertSame('gitleaks', $this->normalize()[0]->tool);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.gitleaks.bin', '/nonexistent/gitleaks');

        $this->assertFalse(app(GitleaksScanner::class)->isAvailable());
    }

    public function test_a_sarif_document_with_no_runs_yields_no_findings(): void
    {
        $this->assertSame([], app(SarifNormalizer::class)->normalize(
            ['version' => '2.1.0', 'runs' => []],
            'gitleaks',
            self::ROOT,
            fn () => Severity::CRITICAL,
            fn () => 'secrets.credential',
            fn () => 'security_hygiene',
        ));
    }
}
