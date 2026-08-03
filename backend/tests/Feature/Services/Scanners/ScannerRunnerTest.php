<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\Scanner;
use App\Services\AuditReport\Scanners\ScannerOutcome;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\FeatureTest;

class ScannerRunnerTest extends FeatureTest
{
    private function context(): RepoContext
    {
        return new RepoContext(
            path: '/tmp/does-not-matter',
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );
    }

    private function fakeScanner(string $name, callable $scan, bool $available = true): Scanner
    {
        return new class($name, $scan, $available) implements Scanner
        {
            public function __construct(
                private string $scannerName,
                private $scanCallback,
                private bool $available,
            ) {}

            public function name(): string
            {
                return $this->scannerName;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function scan(RepoContext $ctx): array
            {
                return ($this->scanCallback)($ctx);
            }
        };
    }

    private function finding(string $tool): Finding
    {
        return new Finding(
            tool: $tool,
            ruleId: $tool.'.rule',
            ruleFamily: 'php.injection',
            severity: Severity::HIGH,
            path: 'app/A.php',
            line: 1,
            message: 'Description.',
            dimension: 'security_hygiene',
        );
    }

    private function runnerWith(Scanner ...$scanners): ScannerRunner
    {
        foreach ($scanners as $scanner) {
            $this->app->bind('audit.scanner.'.$scanner->name(), fn () => $scanner);
        }

        return app(ScannerRunner::class);
    }

    public function test_collects_findings_from_every_scanner(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha')]),
            $this->fakeScanner('beta', fn () => [$this->finding('beta')]),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        $this->assertCount(2, $result->findings);
    }

    public function test_runs_scanners_in_the_order_given(): void
    {
        $order = [];

        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', function () use (&$order) {
                $order[] = 'alpha';

                return [];
            }),
            $this->fakeScanner('beta', function () use (&$order) {
                $order[] = 'beta';

                return [];
            }),
        );

        $runner->run(['alpha', 'beta'], $this->context());

        $this->assertSame(['alpha', 'beta'], $order);
    }

    public function test_a_throwing_scanner_does_not_fail_the_run(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new RuntimeException('boom')),
            $this->fakeScanner('beta', fn () => [$this->finding('beta')]),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        // beta still ran and contributed.
        $this->assertCount(1, $result->findings);
        $this->assertSame(ScannerOutcome::FAILED, $result->runs[0]->outcome);
    }

    public function test_a_timeout_is_classified_separately_from_a_failure(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new ProcessTimedOutException(
                new Process(['true']), ProcessTimedOutException::TYPE_GENERAL,
            )),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame(ScannerOutcome::TIMEOUT, $result->runs[0]->outcome);
    }

    public function test_an_unavailable_binary_takes_the_same_degrade_path(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha')], available: false),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame([], $result->findings);
        $this->assertSame(ScannerOutcome::UNAVAILABLE, $result->runs[0]->outcome);
    }

    public function test_failure_reason_is_classified_and_never_contains_tool_output(): void
    {
        // Semgrep's stderr can echo matched source lines; those must never
        // reach the pipeline log or Bugsink (spec §5.4).
        $secret = 'AKIAIOSFODNN7EXAMPLE';

        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => throw new RuntimeException("scan failed near {$secret}")),
        );

        $result = $runner->run(['alpha'], $this->context());

        $this->assertSame('nonzero_exit', $result->runs[0]->reason);
        $this->assertStringNotContainsString($secret, json_encode($result->runsAsArray()));
    }

    public function test_records_provenance_for_every_scanner(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => [$this->finding('alpha'), $this->finding('alpha')]),
        );

        $run = $runner->run(['alpha'], $this->context())->runs[0];

        $this->assertSame('alpha', $run->name);
        $this->assertSame('1.0.0', $run->version);
        $this->assertSame(2, $run->findingCount);
        $this->assertSame(ScannerOutcome::OK, $run->outcome);
        $this->assertGreaterThanOrEqual(0, $run->wallMs);
    }

    public function test_reports_whether_a_named_scanner_succeeded(): void
    {
        $runner = $this->runnerWith(
            $this->fakeScanner('alpha', fn () => []),
            $this->fakeScanner('beta', fn () => throw new RuntimeException('boom')),
        );

        $result = $runner->run(['alpha', 'beta'], $this->context());

        $this->assertTrue($result->ranSuccessfully('alpha'));
        $this->assertFalse($result->ranSuccessfully('beta'));
        // A scanner that was never asked to run also did not succeed.
        $this->assertFalse($result->ranSuccessfully('semgrep'));
    }
}
