<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\DependencyAuditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class DependencyAuditorTest extends FeatureTest
{
    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->repoPath = storage_path('framework/testing/dep-audit-'.uniqid());
        File::ensureDirectoryExists($this->repoPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    private function writeLockfiles(): void
    {
        File::put($this->repoPath.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/http', 'version' => 'v1.2.3']],
            'packages-dev' => [['name' => 'acme/testkit', 'version' => '2.0.0']],
        ]));
        File::put($this->repoPath.'/package-lock.json', json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'root'],
                'node_modules/leftpad' => ['version' => '9.9.9'],
            ],
        ]));
    }

    public function test_flags_vulnerable_packages_from_osv(): void
    {
        $this->writeLockfiles();
        Http::fake([
            'api.osv.dev/*' => Http::response(['results' => [
                ['vulns' => [['id' => 'GHSA-xxxx-yyyy-zzzz']]],
                [],
                [],
            ]]),
        ]);

        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame(3, $result['packages_scanned']);
        $this->assertSame(1, $result['vulnerable_count']);
        $this->assertSame('acme/http', $result['vulnerabilities'][0]['package']);
        $this->assertSame('1.2.3', $result['vulnerabilities'][0]['version']); // leading "v" stripped
        $this->assertSame(['GHSA-xxxx-yyyy-zzzz'], $result['vulnerabilities'][0]['vulns']);
    }

    public function test_returns_error_marker_when_osv_is_unreachable(): void
    {
        $this->writeLockfiles();
        Http::fake(['api.osv.dev/*' => Http::response(null, 500)]);

        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame('osv_unreachable', $result['error']);
        $this->assertSame(3, $result['packages_scanned']);
        $this->assertSame(0, $result['vulnerable_count']);
    }

    public function test_repo_without_lockfiles_makes_no_http_calls(): void
    {
        $result = app(DependencyAuditor::class)->audit($this->repoPath);

        $this->assertSame(['packages_scanned' => 0, 'vulnerable_count' => 0, 'vulnerabilities' => []], $result);
        Http::assertNothingSent();
    }
}
