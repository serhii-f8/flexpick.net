<?php

namespace Tests\Feature\Services\Collectors;

use App\Services\AuditReport\Collectors\WorkspaceDiscovery;
use App\Services\AuditReport\Paths\PathClassifier;
use Tests\Feature\FeatureTest;

class WorkspaceDiscoveryTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/workspaces-'.bin2hex(random_bytes(6));
        mkdir($this->repo);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
        parent::tearDown();
    }

    private function write(string $path, string $content = '{}'): void
    {
        @mkdir(dirname($this->repo.'/'.$path), 0755, true);
        file_put_contents($this->repo.'/'.$path, $content);
    }

    private function roots(): array
    {
        return app(WorkspaceDiscovery::class)->roots($this->repo, new PathClassifier);
    }

    public function test_bun_workspaces_with_a_root_lockfile(): void
    {
        $this->write('package.json', json_encode(['workspaces' => ['packages/*']]));
        $this->write('bun.lock', '{}');
        $this->write('packages/api/package.json');
        $this->write('packages/web/package.json');

        $this->assertSame(['', 'packages/api', 'packages/web'], $this->roots());
        $this->assertSame('bun.lock', app(WorkspaceDiscovery::class)->lockfileFor($this->repo, 'packages/api', 'npm'));
    }

    public function test_pnpm_workspace_yaml_deeper_than_the_scan_depth(): void
    {
        $this->write('package.json');
        $this->write('pnpm-workspace.yaml', "packages:\n  - 'apps/group/sub/*'\n  - '!apps/ignored'\n");
        $this->write('apps/group/sub/deep/package.json');

        $this->assertContains('apps/group/sub/deep', $this->roots());
    }

    public function test_object_form_workspaces(): void
    {
        $this->write('package.json', json_encode(['workspaces' => ['packages' => ['libs/*']]]));
        $this->write('libs/a/package.json');

        $this->assertContains('libs/a', $this->roots());
    }

    public function test_nested_manifests_without_workspace_declarations_are_found(): void
    {
        $this->write('backend/composer.json');
        $this->write('frontend/package.json');
        $this->write('frontend/node_modules/x/package.json');
        $this->write('vendor/y/composer.json');

        $this->assertSame(['backend', 'frontend'], $this->roots());
    }

    public function test_nearest_lockfile_wins_and_composer_is_separate(): void
    {
        $this->write('package.json');
        $this->write('yarn.lock', '');
        $this->write('frontend/package.json');
        $this->write('frontend/pnpm-lock.yaml', '');
        $this->write('backend/composer.json');

        $discovery = app(WorkspaceDiscovery::class);

        $this->assertSame('frontend/pnpm-lock.yaml', $discovery->lockfileFor($this->repo, 'frontend', 'npm'));
        $this->assertSame('yarn.lock', $discovery->lockfileFor($this->repo, '', 'npm'));
        $this->assertNull($discovery->lockfileFor($this->repo, 'backend', 'composer'));
    }

    public function test_workspace_globs_cannot_escape_the_repository(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(4));
        mkdir($outside.'/pkg', 0755, true);
        file_put_contents($outside.'/pkg/package.json', '{}');

        try {
            $this->write('package.json', json_encode(['workspaces' => ['../*', '../'.basename($outside).'/*']]));
            symlink($outside.'/pkg', $this->repo.'/linked');

            $this->assertSame([''], $this->roots());
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    }

    public function test_roots_are_capped(): void
    {
        $this->write('package.json');
        foreach (range(1, 60) as $i) {
            $this->write("p/{$i}/package.json");
        }

        $this->assertCount(50, $this->roots());
    }
}
