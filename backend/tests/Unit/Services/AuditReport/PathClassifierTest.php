<?php

namespace Tests\Unit\Services\AuditReport;

use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathClassifierTest extends TestCase
{
    /** @return array<string, array{string, PathClass}> */
    public static function paths(): array
    {
        return [
            'root lockfile' => ['bun.lock', PathClass::Lockfile],
            'binary bun lock' => ['frontend/bun.lockb', PathClass::Lockfile],
            'custom generator lock' => ['postxl-lock.json', PathClass::Lockfile],
            'dotted lock yaml' => ['skaile.lock.yaml', PathClass::Lockfile],
            'composer lock' => ['composer.lock', PathClass::Lockfile],
            'go sum' => ['svc/go.sum', PathClass::Lockfile],
            'vendor php' => ['vendor/laravel/framework/src/Foo.php', PathClass::Vendored],
            'nested node_modules' => ['packages/x/node_modules/y/index.js', PathClass::Vendored],
            'third party' => ['lib/third_party/z.c', PathClass::Vendored],
            'storybook static' => ['_concept/prototype/storybook/storybook-static/sb-manager/runtime.js', PathClass::Generated],
            'minified' => ['public/js/app.min.js', PathClass::Generated],
            'source map' => ['public/js/app.js.map', PathClass::Generated],
            'generated dir' => ['src/__generated__/schema.ts', PathClass::Generated],
            'protobuf go' => ['api/v1/user.pb.go', PathClass::Generated],
            'env example' => ['.env.example', PathClass::Example],
            'nested env example' => ['backend/apps/api/.env.example', PathClass::Example],
            'dist config' => ['phpunit.xml.dist', PathClass::Example],
            'dotted example' => ['config/app.example.json', PathClass::Example],
            'tests dir' => ['tests/Feature/UserTest.php', PathClass::Test],
            'ts test suffix' => ['backend/libs/session/src/session-lifecycle.service.test.ts', PathClass::Test],
            'spec suffix' => ['src/app.spec.ts', PathClass::Test],
            'go test' => ['pkg/user_test.go', PathClass::Test],
            'python test' => ['app/test_views.py', PathClass::Test],
            'fixtures dir' => ['src/fixtures/user.json', PathClass::Test],
            'mocks dir' => ['src/__mocks__/fs.ts', PathClass::Test],
            'php Test class' => ['app/UserTest.php', PathClass::Test],
            'not a test: Latest' => ['app/Latest.php', PathClass::Source],
            'markdown' => ['README.md', PathClass::Docs],
            'docs dir' => ['docs/multi-user-onboarding/guide.ts', PathClass::Docs],
            'devlog' => ['_devlog/reports/2026-09.html', PathClass::Docs],
            'source ts' => ['backend/libs/capabilities/src/gif-search.service.ts', PathClass::Source],
            'jenkinsfile' => ['Jenkinsfile', PathClass::Source],
            'compose' => ['docker-compose.prod-local.yml', PathClass::Source],
            'plain txt is not docs' => ['CMakeLists.txt', PathClass::Source],
        ];
    }

    #[DataProvider('paths')]
    public function test_classifies_by_path_convention(string $path, PathClass $expected): void
    {
        $this->assertSame($expected, (new PathClassifier)->classify($path));
    }

    public function test_lockfile_wins_over_vendored(): void
    {
        $this->assertSame(PathClass::Lockfile, (new PathClassifier)->classify('vendor/x/composer.lock'));
    }

    public function test_scc_detected_generated_files_are_generated(): void
    {
        $classifier = new PathClassifier(['backend/libs/database/src/prisma/models/User.ts']);

        $this->assertSame(PathClass::Generated, $classifier->classify('backend/libs/database/src/prisma/models/User.ts'));
        $this->assertSame(PathClass::Source, $classifier->classify('backend/libs/database/src/index.ts'));
    }

    public function test_gitattributes_mark_generated_and_vendored(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'backend/libs/database/src/prisma/**', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => '*.pb.ts', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => 'lib/forked/**', 'attribute' => 'linguist-vendored', 'set' => true],
        ]);

        $this->assertSame(PathClass::Generated, $classifier->classify('backend/libs/database/src/prisma/client.ts'));
        $this->assertSame(PathClass::Generated, $classifier->classify('api/user.pb.ts'));
        $this->assertSame(PathClass::Vendored, $classifier->classify('lib/forked/x.js'));
    }

    public function test_a_later_unset_attribute_overrides_an_earlier_set(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'src/**', 'attribute' => 'linguist-generated', 'set' => true],
            ['pattern' => 'src/keep.ts', 'attribute' => 'linguist-generated', 'set' => false],
        ]);

        $this->assertSame(PathClass::Source, $classifier->classify('src/keep.ts'));
        $this->assertSame(PathClass::Generated, $classifier->classify('src/other.ts'));
    }

    public function test_repo_attributes_can_be_ignored_for_secret_severity(): void
    {
        $classifier = new PathClassifier([], [
            ['pattern' => 'src/**', 'attribute' => 'linguist-generated', 'set' => true],
        ]);

        $this->assertSame(PathClass::Source, $classifier->classify('src/keys.ts', ignoreRepoAttributes: true));
    }

    public function test_reads_gitattributes_from_the_repository_root(): void
    {
        $repo = sys_get_temp_dir().'/classifier-'.bin2hex(random_bytes(4));
        mkdir($repo);
        file_put_contents($repo.'/.gitattributes', "# comment\n_devlog/DEVLOG.md merge=union\ngen/** linguist-generated\nlib/** linguist-vendored=true\nsrc/x.ts -linguist-generated\n");

        try {
            $classifier = PathClassifier::forRepository($repo);

            $this->assertSame(PathClass::Generated, $classifier->classify('gen/a.ts'));
            $this->assertSame(PathClass::Vendored, $classifier->classify('lib/a.js'));
            $this->assertSame(PathClass::Source, $classifier->classify('src/x.ts'));
        } finally {
            exec('rm -rf '.escapeshellarg($repo));
        }
    }

    public function test_build_output_directory_is_the_prefix_through_the_build_segment(): void
    {
        $classifier = new PathClassifier;

        $this->assertSame(
            '_concept/prototype/storybook/storybook-static',
            $classifier->buildOutputDirectory('_concept/prototype/storybook/storybook-static/sb-manager/runtime.js'),
        );
        $this->assertNull($classifier->buildOutputDirectory('src/app.ts'));
    }

    public function test_is_analyzed(): void
    {
        $this->assertTrue(PathClass::Source->isAnalyzed());
        $this->assertTrue(PathClass::Test->isAnalyzed());
        $this->assertTrue(PathClass::Example->isAnalyzed());
        $this->assertFalse(PathClass::Generated->isAnalyzed());
        $this->assertFalse(PathClass::Docs->isAnalyzed());
    }
}
