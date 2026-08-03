<?php

namespace Tests\Feature\Services\Scanners;

use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\JscpdScanner;
use Tests\Feature\FeatureTest;

class JscpdScannerTest extends FeatureTest
{
    private function raw(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/jscpd.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(JscpdScanner::class)->normalize($this->raw());
    }

    public function test_emits_one_finding_per_occurrence_not_per_pair(): void
    {
        // Two clone pairs, four occurrences. A block duplicated into four
        // directories must group in all four (spec §6.1).
        $this->assertCount(4, $this->normalize());
    }

    public function test_each_occurrence_carries_its_own_file_and_start_line(): void
    {
        $paths = array_map(fn ($f) => $f->path.':'.$f->line, $this->normalize());

        $this->assertContains('app/Http/Controllers/OrderController.php:12', $paths);
        $this->assertContains('app/Http/Controllers/InvoiceController.php:30', $paths);
        $this->assertContains('app/Services/Billing.php:5', $paths);
        $this->assertContains('app/Services/Refunds.php:8', $paths);
    }

    public function test_all_duplication_findings_share_one_rule_family(): void
    {
        foreach ($this->normalize() as $finding) {
            $this->assertSame('duplication.clone', $finding->ruleFamily);
        }
    }

    public function test_severity_is_medium(): void
    {
        $this->assertSame(Severity::MEDIUM, $this->normalize()[0]->severity);
    }

    public function test_message_names_the_duplicated_line_count_and_no_source(): void
    {
        $message = $this->normalize()[0]->message;

        $this->assertStringContainsString('40', $message);
        $this->assertStringNotContainsString('OrderController', $message);
    }

    public function test_extracts_the_duplication_percentage_for_scoring(): void
    {
        $this->assertSame(12.5, app(JscpdScanner::class)->duplicationPercentage($this->raw()));
    }

    public function test_duplication_percentage_defaults_to_zero_when_absent(): void
    {
        $this->assertSame(0.0, app(JscpdScanner::class)->duplicationPercentage([]));
    }

    public function test_the_scanner_holds_no_per_run_state(): void
    {
        // Scanners outlive a run inside a Horizon worker. Any per-run value
        // must travel on RepoContext, never on the scanner instance.
        $properties = (new \ReflectionClass(JscpdScanner::class))->getProperties();

        $this->assertSame(
            [],
            array_map(fn (\ReflectionProperty $p): string => $p->getName(), $properties),
            'JscpdScanner declares instance state; record it on RepoContext instead.',
        );
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.jscpd.bin', '/nonexistent/jscpd');

        $this->assertFalse(app(JscpdScanner::class)->isAvailable());
    }
}
