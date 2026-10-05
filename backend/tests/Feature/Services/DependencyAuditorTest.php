<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\DependencyAuditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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
        File::put($this->repoPath.'/composer.json', json_encode(['require' => ['acme/http' => '^1.0']]));
        File::put($this->repoPath.'/package.json', json_encode(['dependencies' => ['leftpad' => '^9.0']]));
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

        $this->assertSame(0, $result['packages_scanned']);
        $this->assertSame(0, $result['vulnerable_count']);
        $this->assertSame([], $result['vulnerabilities']);
        $this->assertFalse($result['has_declared_dependencies']);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, list<string>}> */
    public static function lockfiles(): array
    {
        return [
            'yarn v1' => ['yarn-v1.lock', ['@babel/core@7.24.0', 'lodash@4.17.21']],
            'yarn berry' => ['yarn-berry.lock', ['@babel/core@7.24.0', 'lodash@4.17.21']],
            'pnpm v6' => ['pnpm-v6.yaml', ['@babel/core@7.24.0', 'react-dom@18.2.0']],
            'pnpm v9' => ['pnpm-v9.yaml', ['@babel/core@7.24.0', 'lodash@4.17.21']],
            'bun text' => ['bun.lock', ['@types/node@20.11.0', 'typescript@5.4.5']],
        ];
    }

    #[DataProvider('lockfiles')]
    public function test_parses_lockfile(string $fixture, array $expected): void
    {
        $name = match (true) {
            str_starts_with($fixture, 'yarn') => 'yarn.lock',
            str_starts_with($fixture, 'pnpm') => 'pnpm-lock.yaml',
            default => 'bun.lock',
        };
        copy(base_path('tests/Feature/Services/Fixtures/Lockfiles/'.$fixture), $this->repoPath.'/'.$name);

        $packages = app(DependencyAuditor::class)->packagesFromLockfile($this->repoPath.'/'.$name);
        $ids = array_map(fn (array $p): string => $p['name'].'@'.$p['version'], $packages);
        sort($ids);

        $this->assertSame($expected, $ids);
        $this->assertSame(['npm'], array_values(array_unique(array_column($packages, 'ecosystem'))));
    }

    public function test_a_corrupt_lockfile_yields_no_packages_without_throwing(): void
    {
        File::put($this->repoPath.'/bun.lock', '{"packages": {"x": [');
        File::put($this->repoPath.'/pnpm-lock.yaml', "packages:\n  - : : [");

        $auditor = app(DependencyAuditor::class);

        $this->assertSame([], $auditor->packagesFromLockfile($this->repoPath.'/bun.lock'));
        $this->assertSame([], $auditor->packagesFromLockfile($this->repoPath.'/pnpm-lock.yaml'));
    }

    public function test_workspace_lockfile_is_scanned_once_and_binary_bun_is_unscannable(): void
    {
        $dir = $this->repoPath;
        File::ensureDirectoryExists($dir.'/packages/api');
        File::put($dir.'/package.json', json_encode(['workspaces' => ['packages/*'], 'dependencies' => ['typescript' => '^5']]));
        File::put($dir.'/packages/api/package.json', json_encode(['dependencies' => ['typescript' => '^5']]));
        copy(base_path('tests/Feature/Services/Fixtures/Lockfiles/bun.lock'), $dir.'/bun.lock');
        File::ensureDirectoryExists($dir.'/legacy');
        File::put($dir.'/legacy/package.json', json_encode(['dependencies' => ['x' => '1']]));
        File::put($dir.'/legacy/bun.lockb', "\x00binary");
        Http::fake(['*' => Http::response(['results' => [['vulns' => []], ['vulns' => []]]])]);

        $audit = app(DependencyAuditor::class)->audit($dir);

        $this->assertSame(2, $audit['packages_scanned']);
        $this->assertSame(['bun.lock'], $audit['lockfiles_scanned']);
        $this->assertSame(['legacy/bun.lockb'], $audit['unscannable_lockfiles']);
        $this->assertTrue($audit['has_declared_dependencies']);
    }
}
