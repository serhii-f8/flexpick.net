<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use App\Support\Utf8;
use Illuminate\Support\Facades\Log;

class ManifestCollector implements Collector
{
    public function __construct(private WorkspaceDiscovery $workspaces) {}

    public function name(): string
    {
        return 'manifests';
    }

    public function collect(RepoContext $context): array
    {
        $repoPath = $context->path;
        $manifests = [];

        foreach ($this->workspaces->roots($repoPath, $context->classifier) as $dir) {
            foreach (['composer.json' => 'composer', 'package.json' => 'npm'] as $manifest => $ecosystem) {
                $relative = ltrim($dir.'/'.$manifest, '/');

                if (! is_file($repoPath.'/'.$relative)) {
                    continue;
                }

                $raw = Utf8::scrub((string) file_get_contents($repoPath.'/'.$relative));
                $data = json_decode($raw, true);
                $parseError = ! is_array($data);

                if ($parseError) {
                    Log::warning("ManifestCollector: failed to parse {$relative}", [
                        'json_error' => json_last_error_msg(),
                    ]);
                    $data = [];
                }

                $lock = $this->workspaces->lockfileFor($repoPath, $dir, $ecosystem);

                $manifests[$relative] = [
                    'dependencies' => count($data['require'] ?? $data['dependencies'] ?? []),
                    'dev_dependencies' => count($data['require-dev'] ?? $data['devDependencies'] ?? []),
                    'lockfile' => $lock !== null,
                    'lockfile_kind' => $lock !== null ? basename($lock) : null,
                    'ecosystem' => $ecosystem,
                    'parse_error' => $parseError,
                ];
            }
        }

        return $manifests;
    }
}
