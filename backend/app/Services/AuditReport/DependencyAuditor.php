<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Collectors\WorkspaceDiscovery;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class DependencyAuditor
{
    public function __construct(private WorkspaceDiscovery $workspaces) {}

    /**
     * Reads every package root's nearest lockfile, once each, and asks OSV
     * about the pinned versions.
     *
     * `unscannable_lockfiles` lists lockfiles that exist but yielded no
     * packages (binary `bun.lockb`, a corrupt or truncated file), and
     * `has_declared_dependencies` says whether any manifest declares
     * dependencies, so the scanner can tell "nothing to check" from "could
     * not check".
     *
     * @return array<string, mixed>
     */
    public function audit(string $repoPath, ?PathClassifier $classifier = null): array
    {
        $classifier ??= PathClassifier::forRepository($repoPath);
        $lockfiles = [];
        $declared = false;

        foreach ($this->workspaces->roots($repoPath, $classifier) as $dir) {
            foreach (['composer.json' => 'composer', 'package.json' => 'npm'] as $manifest => $ecosystem) {
                $file = $repoPath.'/'.ltrim($dir.'/'.$manifest, '/');

                if (! is_file($file)) {
                    continue;
                }

                $data = json_decode(Utf8::scrub((string) file_get_contents($file)), true);
                $declared = $declared || (is_array($data) && (($data['require'] ?? $data['dependencies'] ?? []) !== [] || ($data['require-dev'] ?? $data['devDependencies'] ?? []) !== []));

                $lock = $this->workspaces->lockfileFor($repoPath, $dir, $ecosystem);

                if ($lock !== null) {
                    $lockfiles[$lock] = true;
                }
            }
        }

        $packages = [];
        $scanned = [];
        $unscannable = [];

        foreach (array_keys($lockfiles) as $lock) {
            if (str_ends_with($lock, '.lockb')) {
                $unscannable[] = $lock;

                continue;
            }

            $found = $this->packagesFromLockfile($repoPath.'/'.$lock);
            $found === [] ? $unscannable[] = $lock : $scanned[] = $lock;

            foreach ($found as $package) {
                $packages[$package['ecosystem'].'|'.$package['name'].'|'.$package['version']] ??= $package + ['lockfile' => $lock];
            }
        }

        sort($scanned);
        sort($unscannable);
        $packages = array_values($packages);
        $base = [
            'packages_scanned' => count($packages),
            'lockfiles_scanned' => $scanned,
            'unscannable_lockfiles' => $unscannable,
            'has_declared_dependencies' => $declared,
        ];

        if ($packages === []) {
            return $base + ['vulnerable_count' => 0, 'vulnerabilities' => []];
        }

        try {
            $vulnerable = [];

            foreach (array_chunk($packages, 500) as $chunk) {
                $response = Http::timeout(15)->connectTimeout(5)->retry(2, 500)
                    ->post((string) config('audit.osv_endpoint'), [
                        'queries' => array_map(fn (array $package) => [
                            'package' => ['name' => $package['name'], 'ecosystem' => $package['ecosystem']],
                            'version' => $package['version'],
                        ], $chunk),
                    ])->throw();

                foreach ($response->json('results', []) as $i => $result) {
                    $vulnIds = array_column($result['vulns'] ?? [], 'id');
                    if ($vulnIds !== []) {
                        $vulnerable[] = [
                            'package' => $chunk[$i]['name'],
                            'version' => $chunk[$i]['version'],
                            'ecosystem' => $chunk[$i]['ecosystem'],
                            'vulns' => $vulnIds,
                            'lockfile' => $chunk[$i]['lockfile'],
                        ];
                    }
                }
            }

            return $base + [
                'vulnerable_count' => count($vulnerable),
                'vulnerabilities' => array_slice($vulnerable, 0, 25),
            ];
        } catch (Throwable) {
            return $base + [
                'vulnerable_count' => 0,
                'vulnerabilities' => [],
                'error' => 'osv_unreachable',
            ];
        }
    }

    /** @return list<array{name: string, version: string, ecosystem: string}> */
    public function packagesFromLockfile(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            return [];
        }

        $content = Utf8::scrub((string) file_get_contents($path));

        try {
            return match (basename($path)) {
                'composer.lock' => $this->composerPackages($content),
                'package-lock.json', 'npm-shrinkwrap.json' => $this->npmPackages($content),
                'yarn.lock' => $this->yarnPackages($content),
                'pnpm-lock.yaml' => $this->pnpmPackages($content),
                'bun.lock' => $this->bunPackages($content),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    private function composerPackages(string $content): array
    {
        $data = json_decode($content, true) ?? [];
        $packages = [];

        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
            if (isset($package['name'], $package['version'])) {
                $packages[] = [
                    'name' => $package['name'],
                    'version' => ltrim((string) $package['version'], 'v'),
                    'ecosystem' => 'Packagist',
                ];
            }
        }

        return $packages;
    }

    private function npmPackages(string $content): array
    {
        $data = json_decode($content, true) ?? [];
        $packages = [];

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $path => $info) {
                if ($path === '' || ! isset($info['version'])) {
                    continue;
                }
                $name = $info['name'] ?? (str_contains($path, 'node_modules/')
                    ? substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'))
                    : null);
                if ($name === null) {
                    continue;
                }
                $packages[] = ['name' => $name, 'version' => $info['version'], 'ecosystem' => 'npm'];
            }

            return $packages;
        }

        foreach ($data['dependencies'] ?? [] as $name => $info) {
            if (isset($info['version'])) {
                $packages[] = ['name' => $name, 'version' => $info['version'], 'ecosystem' => 'npm'];
            }
        }

        return $packages;
    }

    private function yarnPackages(string $content): array
    {
        $packages = [];
        $current = null;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if ($line[0] !== ' ') {
                $spec = trim(explode(',', rtrim($line, ':'))[0], ' "');
                $current = $this->yarnName($spec);

                continue;
            }

            if ($current !== null && preg_match('/^\s+version:?\s+"?([^"\s]+)"?\s*$/', $line, $m) === 1) {
                if (preg_match('/^\d/', $m[1]) === 1) {
                    $packages[] = ['name' => $current, 'version' => $m[1], 'ecosystem' => 'npm'];
                }
                $current = null;
            }
        }

        return $packages;
    }

    private function yarnName(string $spec): ?string
    {
        if ($spec === '__metadata' || preg_match('/@(workspace|patch|link|portal|file):/', $spec) === 1) {
            return null;
        }

        $at = strpos($spec, '@', 1);

        return $at === false ? null : substr($spec, 0, $at);
    }

    private function pnpmPackages(string $content): array
    {
        $packages = [];

        foreach (array_keys((array) (Yaml::parse($content)['packages'] ?? [])) as $key) {
            $key = (string) preg_replace('/\(.*$/', '', ltrim((string) $key, '/'));
            $at = strrpos($key, '@');
            [$name, $version] = $at > 0
                ? [substr($key, 0, $at), substr($key, $at + 1)]
                : [substr($key, 0, (int) strrpos($key, '/')), substr($key, (int) strrpos($key, '/') + 1)];

            if ($name !== '' && preg_match('/^\d/', $version) === 1) {
                $packages[] = ['name' => $name, 'version' => $version, 'ecosystem' => 'npm'];
            }
        }

        return $packages;
    }

    /** bun.lock is JSON with trailing commas. */
    private function bunPackages(string $content): array
    {
        $data = json_decode((string) preg_replace('/,(\s*[}\]])/', '$1', $content), true, flags: JSON_THROW_ON_ERROR);
        $packages = [];

        foreach ((array) ($data['packages'] ?? []) as $entry) {
            $id = is_array($entry) ? ($entry[0] ?? null) : null;
            $at = is_string($id) ? strrpos($id, '@') : false;

            if ($at === false || $at === 0) {
                continue;
            }

            $version = substr($id, $at + 1);

            if (preg_match('/^\d/', $version) === 1) {
                $packages[] = ['name' => substr($id, 0, $at), 'version' => $version, 'ecosystem' => 'npm'];
            }
        }

        return $packages;
    }
}
