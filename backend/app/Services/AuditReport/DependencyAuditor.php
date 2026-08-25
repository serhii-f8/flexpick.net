<?php

namespace App\Services\AuditReport;

use App\Support\Utf8;
use Illuminate\Support\Facades\Http;
use Throwable;

class DependencyAuditor
{
    public function audit(string $repoPath): array
    {
        $packages = array_merge($this->composerPackages($repoPath), $this->npmPackages($repoPath));

        if ($packages === []) {
            return ['packages_scanned' => 0, 'vulnerable_count' => 0, 'vulnerabilities' => []];
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
                        ];
                    }
                }
            }

            return [
                'packages_scanned' => count($packages),
                'vulnerable_count' => count($vulnerable),
                'vulnerabilities' => array_slice($vulnerable, 0, 25),
            ];
        } catch (Throwable) {
            return [
                'packages_scanned' => count($packages),
                'vulnerable_count' => 0,
                'vulnerabilities' => [],
                'error' => 'osv_unreachable',
            ];
        }
    }

    private function composerPackages(string $repoPath): array
    {
        $lock = $repoPath.'/composer.lock';
        if (! is_file($lock)) {
            return [];
        }

        $data = json_decode(Utf8::scrub((string) file_get_contents($lock)), true) ?? [];
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

    private function npmPackages(string $repoPath): array
    {
        $lock = $repoPath.'/package-lock.json';
        if (! is_file($lock)) {
            return [];
        }

        $data = json_decode(Utf8::scrub((string) file_get_contents($lock)), true) ?? [];
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
}
