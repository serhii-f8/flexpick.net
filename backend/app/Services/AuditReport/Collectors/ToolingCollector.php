<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

class ToolingCollector implements Collector
{
    public function name(): string
    {
        return 'tooling';
    }

    public function collect(RepoContext $context): array
    {
        $repoPath = $context->path;
        $deps = [];

        foreach (['composer.json', 'package.json'] as $manifest) {
            if (! file_exists($repoPath.'/'.$manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($repoPath.'/'.$manifest), true) ?? [];
            $deps = array_merge(
                $deps,
                array_keys($data['require'] ?? []),
                array_keys($data['require-dev'] ?? []),
                array_keys($data['dependencies'] ?? []),
                array_keys($data['devDependencies'] ?? []),
            );
        }

        $has = fn (array $names): bool => array_intersect($names, $deps) !== [];

        $files = $context->inventory?->files ?? [];
        $testFiles = count(array_filter(
            $files,
            fn (array $f): bool => preg_match('#(^|/)(tests?|spec|__tests__)/#i', $f['path']) === 1
                || preg_match('/(Test|\.test|\.spec)\.[a-z]+$/i', $f['path']) === 1,
        ));

        return [
            'error_monitoring' => $has(['sentry/sentry', 'sentry/sentry-laravel', '@sentry/browser', '@sentry/node', '@sentry/react', '@sentry/nextjs', '@sentry/vue', 'bugsnag/bugsnag', 'bugsnag/bugsnag-laravel', '@bugsnag/js', 'rollbar/rollbar', 'rollbar', 'honeybadger-io/honeybadger-php', '@honeybadger-io/js']),
            'linter' => $has(['laravel/pint', 'friendsofphp/php-cs-fixer', 'squizlabs/php_codesniffer', 'eslint', '@biomejs/biome', 'oxlint']),
            'static_analysis' => $has(['phpstan/phpstan', 'larastan/larastan', 'vimeo/psalm', 'typescript']),
            'formatter' => $has(['prettier', 'laravel/pint', '@biomejs/biome']),
            'env_example' => file_exists($repoPath.'/.env.example'),
            'dockerized' => file_exists($repoPath.'/Dockerfile') || file_exists($repoPath.'/docker-compose.yml') || file_exists($repoPath.'/compose.yaml'),
            'has_ci' => is_dir($repoPath.'/.github/workflows')
                || file_exists($repoPath.'/.gitlab-ci.yml')
                || file_exists($repoPath.'/bitbucket-pipelines.yml'),
            'has_readme' => count(glob($repoPath.'/README*') ?: []) > 0,
            'test_files' => $testFiles,
            'test_ratio_pct' => $files === [] ? 0.0 : round($testFiles / count($files) * 100, 1),
        ];
    }
}
