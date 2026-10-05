<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Services\AuditReport\Scanners\JscpdScanner;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\ScannerSkipped;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class JscpdScannerTest extends FeatureTest
{
    /** jscpd reports relative to this root already; normalization is a no-op. */
    private const ROOT = '/var/www/html/storage/app/audit-workdirs/0199a1f2';

    private function raw(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/jscpd.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(JscpdScanner::class)->normalize($this->raw(), self::ROOT);
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

    public function test_a_missing_report_is_a_skip_not_zero_duplication(): void
    {
        config(['audit.scanners.jscpd.bin' => '/bin/true']);
        $context = new RepoContext(path: sys_get_temp_dir(), tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));

        $this->expectException(ScannerSkipped::class);

        app(JscpdScanner::class)->scan($context);
    }

    public function test_occurrences_in_generated_files_are_dropped(): void
    {
        $raw = ['duplicates' => [[
            'lines' => 40,
            'firstFile' => ['name' => 'src/a.ts', 'start' => 1],
            'secondFile' => ['name' => 'src/__generated__/b.ts', 'start' => 1],
        ]]];

        $findings = app(JscpdScanner::class)->normalize($raw, self::ROOT, new PathClassifier);

        $this->assertSame(['src/a.ts'], array_map(fn ($f) => $f->path, $findings));
    }

    public function test_ignore_globs_collapse_excluded_directories(): void
    {
        $inventory = new SccInventory(
            files: [],
            languages: [],
            totalLoc: 0,
            totalComplexity: 0,
            excluded: [
                ['path' => 'proto/storybook-static/a.js', 'loc' => 1, 'reason' => 'generated'],
                ['path' => 'proto/storybook-static/b.js', 'loc' => 1, 'reason' => 'generated'],
                ['path' => 'libs/db/prisma/User.ts', 'loc' => 1, 'reason' => 'generated'],
                ['path' => 'README.md', 'loc' => 1, 'reason' => 'docs'],
            ],
        );

        $globs = app(JscpdScanner::class)->ignoreGlobs($inventory, new PathClassifier);

        $this->assertSame(['libs/db/prisma/User.ts', 'proto/storybook-static/**'], $globs);
    }

    public function test_no_report_for_a_repository_too_small_to_hold_a_clone_is_measured_clean(): void
    {
        // jscpd writes no report when nothing reaches its minimum block size.
        config(['audit.scanners.jscpd.bin' => '/bin/true']);
        $context = new RepoContext(
            path: sys_get_temp_dir(),
            tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
            inventory: new SccInventory(
                files: [['path' => 'index.php', 'loc' => 3, 'complexity' => 0, 'class' => 'source']],
                languages: [],
                totalLoc: 3,
                totalComplexity: 0,
            ),
        );

        $this->assertSame([], app(JscpdScanner::class)->scan($context));
        $this->assertSame(0.0, $context->measurement('duplication_pct', -1.0));
    }
}
