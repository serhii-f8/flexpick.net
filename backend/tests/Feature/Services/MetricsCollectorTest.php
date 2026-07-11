<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\MetricsCollector;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class MetricsCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = storage_path('framework/testing/metrics-fixture');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/src');
        File::ensureDirectoryExists($this->repo.'/tests');
        File::ensureDirectoryExists($this->repo.'/vendor/lib');
        File::ensureDirectoryExists($this->repo.'/.github/workflows');

        File::put($this->repo.'/README.md', "# Fixture\n");
        File::put($this->repo.'/src/a.php', "<?php\n".str_repeat("function f() { return 1; }\n", 30));
        File::put($this->repo.'/src/b.php', "<?php\n".str_repeat("function f() { return 1; }\n", 30)); // duplicate of a.php
        File::put($this->repo.'/src/c.js', "const key = 'x';\n".str_repeat("console.log(1);\n", 5));
        File::put($this->repo.'/src/leak.php', "<?php\n\$key = 'AKIAIOSFODNN7EXAMPLE';\n");
        File::put($this->repo.'/tests/aTest.php', "<?php\n// test\n");
        File::put($this->repo.'/vendor/lib/ignored.php', "<?php\n// must be skipped\n");
        File::put($this->repo.'/.github/workflows/ci.yml', "on: push\n");
        File::put($this->repo.'/composer.json', json_encode([
            'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ]));
    }

    public function test_collects_expected_metrics(): void
    {
        $result = app(MetricsCollector::class)->collect($this->repo);
        $metrics = $result['metrics'];

        $this->assertArrayHasKey('php', $metrics['languages']);
        $this->assertGreaterThan(0, $metrics['loc_total']);
        $this->assertGreaterThan(20, $metrics['duplication_pct']); // a.php duplicates b.php
        $this->assertSame(1, $metrics['test_files']);
        $this->assertTrue($metrics['has_ci']);
        $this->assertTrue($metrics['has_readme']);
        $this->assertSame(2, $metrics['manifests']['composer.json']['dependencies']);
        $this->assertSame(1, $metrics['manifests']['composer.json']['dev_dependencies']);
        $this->assertGreaterThanOrEqual(1, $metrics['secret_findings']['aws_access_key']['count']);
        $this->assertStringNotContainsString('AKIA', json_encode($metrics)); // never the value itself
    }

    public function test_excerpts_skip_vendor_and_respect_limits(): void
    {
        $result = app(MetricsCollector::class)->collect($this->repo);

        $paths = array_column($result['excerpts'], 'path');
        $this->assertNotEmpty($paths);
        $this->assertLessThanOrEqual(config('audit.max_excerpt_files'), count($paths));
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('vendor/', $path);
        }
        foreach ($result['excerpts'] as $excerpt) {
            $this->assertLessThanOrEqual(config('audit.max_excerpt_bytes'), strlen($excerpt['content']));
        }
    }
}
