<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\MetricsCollector;
use ReflectionClass;
use Tests\Feature\FeatureTest;

class SupersededHeuristicsTest extends FeatureTest
{
    public function test_metrics_collector_no_longer_carries_secret_patterns(): void
    {
        // Gitleaks supersedes the in-house pattern set (F5.12.2). Two secret
        // implementations would double-count and disagree.
        $this->assertArrayNotHasKey(
            'SECRET_PATTERNS',
            (new ReflectionClass(MetricsCollector::class))->getConstants(),
        );
    }

    public function test_metrics_collector_source_is_small(): void
    {
        // It was 220 lines doing eight jobs. As a composer it should be a
        // fraction of that; a regression here means logic crept back in.
        $lines = count(file(app_path('Services/AuditReport/MetricsCollector.php')));

        $this->assertLessThan(80, $lines, 'MetricsCollector has grown back into a grab-bag.');
    }

    public function test_no_md5_line_hashing_remains(): void
    {
        // jscpd supersedes the line-hash duplication heuristic.
        $source = (string) file_get_contents(app_path('Services/AuditReport/MetricsCollector.php'));

        $this->assertStringNotContainsString('md5(', $source);
    }
}
