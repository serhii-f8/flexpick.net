<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

class ManifestCollector implements Collector
{
    public function name(): string
    {
        return 'manifests';
    }

    public function collect(RepoContext $context): array
    {
        $repoPath = $context->path;
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
}
