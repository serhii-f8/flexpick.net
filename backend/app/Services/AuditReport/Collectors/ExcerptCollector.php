<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

class ExcerptCollector implements Collector
{
    public function name(): string
    {
        return 'excerpts';
    }

    public function collect(RepoContext $context): array
    {
        $excerpts = [];
        $files = $context->inventory?->files ?? [];

        foreach (array_slice($files, 0, $context->tier->excerptFiles) as $file) {
            $absolute = $context->path.'/'.$file['path'];

            if (! is_file($absolute)) {
                continue;
            }

            $excerpts[] = [
                'path' => $file['path'],
                'content' => (string) file_get_contents($absolute, length: $context->tier->excerptBytes),
            ];
        }

        return ['excerpts' => $excerpts];
    }
}
