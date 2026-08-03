<?php

namespace App\Services\AuditReport\Scanners;

use Illuminate\Contracts\Container\Container;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Runs the tier's scanners in the committed order, absorbing every failure.
 *
 * F5.12.2: a failed scanner contributes no findings, is recorded, and never
 * fails the run. A missing binary takes the identical path — no special case,
 * so a half-provisioned host degrades rather than erroring.
 */
class ScannerRunner
{
    public function __construct(private Container $container) {}

    /**
     * @param  list<string>  $names  scanner names, in execution order
     */
    public function run(array $names, RepoContext $context): ScannerSuiteResult
    {
        $findings = [];
        $runs = [];

        foreach ($names as $name) {
            $scanner = $this->container->make('audit.scanner.'.$name);
            $startedAt = hrtime(true);

            if (! $scanner->isAvailable()) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::UNAVAILABLE,
                    reason: 'unavailable',
                );

                continue;
            }

            try {
                $produced = $scanner->scan($context);

                $findings = array_merge($findings, $produced);
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: count($produced),
                    outcome: ScannerOutcome::OK,
                );
            } catch (ProcessTimedOutException) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::TIMEOUT,
                    reason: 'timeout',
                );
            } catch (Throwable $e) {
                $runs[] = new ScannerRun(
                    name: $name,
                    version: $scanner->version(),
                    wallMs: $this->elapsedMs($startedAt),
                    findingCount: 0,
                    outcome: ScannerOutcome::FAILED,
                    // Classified, never $e->getMessage() — that can carry tool output.
                    reason: $e instanceof \JsonException ? 'parse_failure' : 'nonzero_exit',
                );
            }
        }

        return new ScannerSuiteResult(array_values($findings), $runs);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
