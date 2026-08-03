<?php

namespace Tests\Feature\Health;

use App\Health\Checks\ScannerDegradationCheck;
use App\Models\AuditRequest;
use Spatie\Health\Enums\Status;
use Tests\Feature\FeatureTest;

class ScannerDegradationCheckTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite has no per-test DB reset (FeatureTest::migrate:fresh runs
        // once); every test here creates AuditRequest rows within the same
        // narrow time window, so an earlier test's rows would otherwise
        // pollute a later test's rate calculation.
        AuditRequest::query()->delete();
    }

    private function runWith(string $outcome, int $count = 1): void
    {
        AuditRequest::factory()->count($count)->create([
            'tier' => 'automated',
            'analysis_started_at' => now()->subMinutes(10),
            'scanner_runs' => [
                ['name' => 'scc', 'version' => '3.5.0', 'wall_ms' => 100, 'finding_count' => 0,
                    'outcome' => 'ok', 'reason' => null],
                ['name' => 'semgrep', 'version' => '1.99.0', 'wall_ms' => 5000, 'finding_count' => 0,
                    'outcome' => $outcome, 'reason' => $outcome === 'ok' ? null : 'timeout'],
            ],
        ]);
    }

    public function test_is_ok_when_every_scanner_completes(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 5);
        $this->runWith('ok', 10);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status->value);
    }

    public function test_fails_when_degradation_exceeds_the_threshold(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 5);
        config()->set('health.flexpick.scanner_degradation.fail_percent', 20);

        $this->runWith('ok', 2);
        $this->runWith('timeout', 8);

        $this->assertSame(Status::failed()->value, app(ScannerDegradationCheck::class)->run()->status->value);
    }

    public function test_stays_ok_below_the_minimum_sample_size(): void
    {
        // At pre-launch volume a couple of degraded runs must not page.
        config()->set('health.flexpick.scanner_degradation.min_samples', 20);
        $this->runWith('timeout', 3);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status->value);
    }

    public function test_ignores_runs_outside_the_window(): void
    {
        config()->set('health.flexpick.scanner_degradation.min_samples', 1);
        config()->set('health.flexpick.scanner_degradation.window_hours', 1);

        AuditRequest::factory()->create([
            'tier' => 'automated',
            'analysis_started_at' => now()->subDays(3),
            'scanner_runs' => [['name' => 'semgrep', 'version' => '1', 'wall_ms' => 1,
                'finding_count' => 0, 'outcome' => 'timeout', 'reason' => 'timeout']],
        ]);

        $this->assertSame(Status::ok()->value, app(ScannerDegradationCheck::class)->run()->status->value);
    }

    public function test_declares_a_severity_band(): void
    {
        $this->assertNotNull(config('health.flexpick.bands.ScannerDegradation'));
    }
}
