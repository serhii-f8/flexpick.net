<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Tiers\TierProfile;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class MetricsCollector
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', 'dist', 'build', '.git', 'storage', 'public/build', '.next', 'coverage'];

    private const SOURCE_EXTENSIONS = ['php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'go', 'java', 'cs', 'vue', 'astro', 'blade.php', 'css', 'scss', 'html', 'sql', 'sh', 'yml', 'yaml', 'json'];

    private const SECRET_PATTERNS = [
        'aws_access_key' => '/AKIA[0-9A-Z]{16}/',
        'private_key_block' => '/-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'generic_api_key' => '/(api[_-]?key|secret[_-]?key|access[_-]?token)["\']?\s*[:=>]+\s*["\'][A-Za-z0-9_\-]{16,}["\']/i',
        'github_token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36,}\b/',
        'github_fine_grained_pat' => '/\bgithub_pat_[A-Za-z0-9_]{60,}\b/',
        'gitlab_pat' => '/\bglpat-[A-Za-z0-9\-_]{20,}\b/',
        'slack_token' => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
        'stripe_live_key' => '/\b(?:sk|rk)_live_[A-Za-z0-9]{20,}\b/',
        'sendgrid_key' => '/\bSG\.[A-Za-z0-9_\-]{22}\.[A-Za-z0-9_\-]{43}\b/',
        'google_api_key' => '/\bAIza[0-9A-Za-z\-_]{35}\b/',
        'openai_key' => '/\bsk-[A-Za-z0-9]{20}T3BlbkFJ[A-Za-z0-9]{20}\b/',
        'anthropic_key' => '/\bsk-ant-[A-Za-z0-9\-_]{32,}\b/',
        'npm_token' => '/\bnpm_[A-Za-z0-9]{36}\b/',
        'twilio_api_key' => '/\bSK[0-9a-f]{32}\b/',
        'credentialed_url' => '#\b[a-z][a-z0-9+.\-]*://[^/\s:@"\']{1,64}:[^/\s:@"\']{1,64}@#i',
    ];

    public function __construct(
        private DependencyAuditor $dependencyAuditor,
    ) {}

    public function collect(string $repoPath, TierProfile $profile): array
    {
        $files = iterator_to_array($this->sourceFiles($repoPath), false);

        $languages = [];
        $fileStats = [];
        $lineHashes = [];
        $duplicateLines = 0;
        $totalHashedLines = 0;
        $secretFindings = [];

        foreach ($files as $file) {
            $content = $file->getContents();
            $loc = substr_count($content, "\n") + 1;
            $ext = strtolower($file->getExtension());
            $relative = $file->getRelativePathname();

            $languages[$ext]['files'] = ($languages[$ext]['files'] ?? 0) + 1;
            $languages[$ext]['loc'] = ($languages[$ext]['loc'] ?? 0) + $loc;
            $fileStats[] = ['path' => $relative, 'loc' => $loc, 'bytes' => $file->getSize()];

            foreach (explode("\n", $content) as $line) {
                $normalized = trim($line);
                if (strlen($normalized) < 12) {
                    continue;
                }
                $hash = md5($normalized);
                $totalHashedLines++;
                if (isset($lineHashes[$hash]) && $lineHashes[$hash] !== $relative) {
                    $duplicateLines++;
                } else {
                    $lineHashes[$hash] = $relative;
                }
            }

            foreach (self::SECRET_PATTERNS as $name => $pattern) {
                $count = preg_match_all($pattern, $content);
                if ($count > 0) {
                    $secretFindings[$name]['count'] = ($secretFindings[$name]['count'] ?? 0) + $count;
                    $secretFindings[$name]['files'][] = $relative;
                }
            }
        }

        usort($fileStats, fn ($a, $b) => $b['loc'] <=> $a['loc']);

        $testFiles = count(array_filter($fileStats, fn ($f) => preg_match('#(^|/)(tests?|spec|__tests__)/#i', $f['path']) || preg_match('/(Test|\.test|\.spec)\.[a-z]+$/i', $f['path'])));

        $metrics = [
            'files_total' => count($fileStats),
            'loc_total' => array_sum(array_column($fileStats, 'loc')),
            'languages' => $languages,
            'largest_files' => array_map(fn ($f) => ['path' => $f['path'], 'loc' => $f['loc']], array_slice($fileStats, 0, 20)),
            'duplication_pct' => $totalHashedLines > 0 ? round($duplicateLines / $totalHashedLines * 100, 1) : 0.0,
            'test_files' => $testFiles,
            'test_ratio_pct' => count($fileStats) > 0 ? round($testFiles / count($fileStats) * 100, 1) : 0.0,
            'has_ci' => is_dir($repoPath.'/.github/workflows') || file_exists($repoPath.'/.gitlab-ci.yml') || file_exists($repoPath.'/bitbucket-pipelines.yml'),
            'has_readme' => count(glob($repoPath.'/README*') ?: []) > 0,
            'manifests' => $this->manifests($repoPath),
            'tooling' => $this->tooling($repoPath),
            'dependency_audit' => $this->dependencyAuditor->audit($repoPath),
            'secret_findings' => array_map(fn ($f) => ['count' => $f['count'], 'files' => array_values(array_unique($f['files']))], $secretFindings),
            'git' => $this->gitInfo($repoPath),
            'hotspots' => $this->hotspots($repoPath, $fileStats),
        ];

        return [
            'metrics' => $metrics,
            'excerpts' => $this->excerpts($repoPath, $fileStats, $profile),
        ];
    }

    /** @return iterable<SplFileInfo> */
    private function sourceFiles(string $repoPath): iterable
    {
        return (new Finder)
            ->files()
            ->in($repoPath)
            ->exclude(self::EXCLUDED_DIRS)
            ->ignoreDotFiles(false)
            ->size('< 2M')
            ->name(array_map(fn ($ext) => '*.'.$ext, self::SOURCE_EXTENSIONS));
    }

    private function manifests(string $repoPath): array
    {
        $manifests = [];

        foreach (['composer.json' => 'composer.lock', 'package.json' => 'package-lock.json'] as $manifest => $lock) {
            if (! file_exists($repoPath.'/'.$manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($repoPath.'/'.$manifest), true) ?? [];
            $manifests[$manifest] = [
                'dependencies' => count($data['require'] ?? $data['dependencies'] ?? []),
                'dev_dependencies' => count($data['require-dev'] ?? $data['devDependencies'] ?? []),
                'lockfile' => file_exists($repoPath.'/'.$lock),
            ];
        }

        return $manifests;
    }

    private function tooling(string $repoPath): array
    {
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

        return [
            'error_monitoring' => $has(['sentry/sentry', 'sentry/sentry-laravel', '@sentry/browser', '@sentry/node', '@sentry/react', '@sentry/nextjs', '@sentry/vue', 'bugsnag/bugsnag', 'bugsnag/bugsnag-laravel', '@bugsnag/js', 'rollbar/rollbar', 'rollbar', 'honeybadger-io/honeybadger-php', '@honeybadger-io/js']),
            'linter' => $has(['laravel/pint', 'friendsofphp/php-cs-fixer', 'squizlabs/php_codesniffer', 'eslint', '@biomejs/biome', 'oxlint']),
            'static_analysis' => $has(['phpstan/phpstan', 'larastan/larastan', 'vimeo/psalm', 'typescript']),
            'formatter' => $has(['prettier', 'laravel/pint', '@biomejs/biome']),
            'env_example' => file_exists($repoPath.'/.env.example'),
            'dockerized' => file_exists($repoPath.'/Dockerfile') || file_exists($repoPath.'/docker-compose.yml') || file_exists($repoPath.'/compose.yaml'),
        ];
    }

    private function gitInfo(string $repoPath): array
    {
        $branch = Process::path($repoPath)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $lastCommit = Process::path($repoPath)->run(['git', 'log', '-1', '--format=%cI']);
        $authors = Process::path($repoPath)->run(['git', 'log', '--format=%ae']);

        $emails = array_filter(explode("\n", trim($authors->output())));
        $byAuthor = $emails === [] ? [] : array_count_values($emails);

        return [
            'default_branch' => trim($branch->output()) ?: null,
            'last_commit_at' => trim($lastCommit->output()) ?: null,
            'commits_analyzed' => count($emails),
            'contributors' => count($byAuthor),
            'top_contributor_pct' => $emails === [] ? null : (int) round(max($byAuthor) / count($emails) * 100),
        ];
    }

    private function hotspots(string $repoPath, array $fileStats): array
    {
        $log = Process::path($repoPath)->run(['git', 'log', '--name-only', '--format=']);
        $changes = array_count_values(array_filter(explode("\n", trim($log->output()))));
        $locByPath = array_column($fileStats, 'loc', 'path');

        $hotspots = [];
        foreach ($changes as $path => $count) {
            if ($count < 2 || ! isset($locByPath[$path])) {
                continue;
            }
            $hotspots[] = ['path' => $path, 'changes' => $count, 'loc' => $locByPath[$path]];
        }

        usort($hotspots, fn (array $a, array $b) => ($b['changes'] * $b['loc']) <=> ($a['changes'] * $a['loc']));

        return array_slice($hotspots, 0, 10);
    }

    private function excerpts(string $repoPath, array $fileStats, TierProfile $profile): array
    {
        $excerpts = [];

        foreach (array_slice($fileStats, 0, $profile->excerptFiles) as $file) {
            $content = (string) file_get_contents($repoPath.'/'.$file['path'], length: $profile->excerptBytes);
            $excerpts[] = ['path' => $file['path'], 'content' => $content];
        }

        return $excerpts;
    }
}
