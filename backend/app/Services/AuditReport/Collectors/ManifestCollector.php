<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use Illuminate\Support\Facades\Log;

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

            $raw = (string) file_get_contents($repoPath.'/'.$manifest);
            $data = json_decode($raw, true);
            $parseError = ! is_array($data);

            if ($parseError) {
                Log::warning("ManifestCollector: failed to parse {$manifest}", [
                    'json_error' => json_last_error_msg(),
                ]);
                $data = [];
            }

            $manifests[$manifest] = [
                'dependencies' => count($data['require'] ?? $data['dependencies'] ?? []),
                'dev_dependencies' => count($data['require-dev'] ?? $data['devDependencies'] ?? []),
                'lockfile' => file_exists($repoPath.'/'.$lock),
                'parse_error' => $parseError,
            ];
        }

        return $manifests;
    }
}
