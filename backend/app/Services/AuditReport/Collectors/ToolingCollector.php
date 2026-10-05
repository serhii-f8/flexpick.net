<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Support\Utf8;

class ToolingCollector implements Collector
{
    private const CI_MARKERS = [
        'github_actions' => '.github/workflows',
        'gitlab_ci' => '.gitlab-ci.yml',
        'bitbucket_pipelines' => 'bitbucket-pipelines.yml',
        'circleci' => '.circleci/config.yml',
        'azure_pipelines' => 'azure-pipelines.yml',
        'buildkite' => '.buildkite',
        'drone' => '.drone.yml',
        'travis' => '.travis.yml',
    ];

    private const ERROR_MONITORING = [
        'sentry/sentry', 'sentry/sentry-laravel', '@sentry/browser', '@sentry/node', '@sentry/react', '@sentry/nextjs',
        '@sentry/vue', '@sentry/bun', '@sentry/nestjs', '@sentry/sveltekit', '@sentry/astro', 'bugsnag/bugsnag',
        'bugsnag/bugsnag-laravel', '@bugsnag/js', 'rollbar/rollbar', 'rollbar', 'honeybadger-io/honeybadger-php',
        '@honeybadger-io/js', 'dd-trace', 'newrelic', '@opentelemetry/sdk-node', 'posthog-node',
    ];

    public function __construct(private WorkspaceDiscovery $workspaces) {}

    public function name(): string
    {
        return 'tooling';
    }

    public function collect(RepoContext $context): array
    {
        $repoPath = $context->path;
        $deps = [];

        foreach ($this->workspaces->roots($repoPath, $context->classifier) as $dir) {
            foreach (['composer.json', 'package.json'] as $manifest) {
                $file = $repoPath.'/'.ltrim($dir.'/'.$manifest, '/');

                if (! is_file($file)) {
                    continue;
                }

                $data = json_decode(Utf8::scrub((string) file_get_contents($file)), true) ?? [];
                $deps = array_merge(
                    $deps,
                    array_keys($data['require'] ?? []),
                    array_keys($data['require-dev'] ?? []),
                    array_keys($data['dependencies'] ?? []),
                    array_keys($data['devDependencies'] ?? []),
                );
            }
        }

        $has = fn (array $names): bool => array_intersect($names, $deps) !== [];

        $files = $context->inventory?->files ?? [];
        $testFiles = count(array_filter($files, fn (array $f): bool => ($f['class'] ?? null) === PathClass::Test->value));

        $ciSystems = $this->ciSystems($repoPath);

        return [
            'error_monitoring' => $has(self::ERROR_MONITORING),
            'linter' => $has(['laravel/pint', 'friendsofphp/php-cs-fixer', 'squizlabs/php_codesniffer', 'eslint', '@biomejs/biome', 'oxlint']),
            'static_analysis' => $has(['phpstan/phpstan', 'larastan/larastan', 'vimeo/psalm', 'typescript']),
            'formatter' => $has(['prettier', 'laravel/pint', '@biomejs/biome']),
            'env_example' => file_exists($repoPath.'/.env.example') || file_exists($repoPath.'/.env.sample') || file_exists($repoPath.'/.env.template'),
            'dockerized' => (glob($repoPath.'/{Dockerfile,Dockerfile.*,docker-compose.yml,docker-compose.*.yml,compose.yaml,compose.*.yaml}', GLOB_BRACE) ?: []) !== [],
            'has_ci' => $ciSystems !== [],
            'ci_systems' => $ciSystems,
            'has_readme' => count(glob($repoPath.'/README*') ?: []) > 0,
            'test_files' => $testFiles,
            'test_ratio_pct' => $files === [] ? 0.0 : round($testFiles / count($files) * 100, 1),
        ];
    }

    /** @return list<string> */
    private function ciSystems(string $repoPath): array
    {
        $systems = [];

        foreach (self::CI_MARKERS as $system => $marker) {
            if (file_exists($repoPath.'/'.$marker)) {
                $systems[] = $system;
            }
        }

        if ((glob($repoPath.'/Jenkinsfile*') ?: []) !== []) {
            $systems[] = 'jenkins';
        }

        sort($systems);

        return $systems;
    }
}
