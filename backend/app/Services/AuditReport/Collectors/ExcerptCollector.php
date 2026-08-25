<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\RepoFileReader;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\SecretFileFilter;

class ExcerptCollector implements Collector
{
    public function __construct(
        private SecretFileFilter $secretFiles,
        private RepoFileReader $files,
    ) {}

    public function name(): string
    {
        return 'excerpts';
    }

    public function collect(RepoContext $context): array
    {
        $excerpts = [];

        foreach ($context->inventory?->files ?? [] as $file) {
            if (count($excerpts) >= $context->tier->excerptFiles) {
                break;
            }

            // Q17: filter BEFORE reading, so a secret-bearing file is never
            // loaded into memory, let alone sent.
            if ($this->secretFiles->excludes($file['path'], $context->secretPaths)) {
                continue;
            }

            $absolute = $context->path.'/'.$file['path'];

            if (! is_file($absolute)) {
                continue;
            }

            $excerpts[] = [
                'path' => $file['path'],
                'content' => $this->files->read($absolute, $context->tier->excerptBytes),
            ];
        }

        return ['excerpts' => $excerpts];
    }
}
