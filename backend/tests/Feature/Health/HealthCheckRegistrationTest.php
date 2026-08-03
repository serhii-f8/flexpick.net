<?php

namespace Tests\Feature\Health;

use Spatie\Health\Facades\Health;
use Tests\Feature\FeatureTest;

class HealthCheckRegistrationTest extends FeatureTest
{
    public function test_every_specified_check_is_registered(): void
    {
        $registered = collect(Health::registeredChecks())
            ->map(fn ($check) => $check->getName())
            ->sort()
            ->values()
            ->all();

        $expected = [
            'AuditPipelineFailureRate',
            'Cache',
            'Database',
            'Horizon',
            'MailFailureRate',
            'OldestPendingAudit',
            'Queue',
            'Redis',
            'ScannerDegradation',
            'Schedule',
            'UsedDiskSpace',
        ];

        sort($expected);

        $this->assertSame($expected, $registered);
    }

    public function test_every_registered_check_has_a_severity_band(): void
    {
        $bands = config('health.flexpick.bands');

        foreach (Health::registeredChecks() as $check) {
            $this->assertArrayHasKey(
                $check->getName(),
                $bands,
                "Check {$check->getName()} has no band in config('health.flexpick.bands')."
            );
        }
    }

    public function test_paging_bands_exclude_medium(): void
    {
        $this->assertSame(['critical', 'high'], config('health.flexpick.paging_bands'));
        $this->assertNotContains('medium', config('health.flexpick.paging_bands'));
    }
}
