<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Collectors\Collector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Support\Utf8;

class MetricsCollector
{
    /** @param list<Collector> $collectors */
    public function __construct(private array $collectors) {}

    /** @return array{metrics: array<string, mixed>, excerpts: list<array{path: string, content: string}>} */
    public function collect(RepoContext $context): array
    {
        $metrics = [];
        $excerpts = [];

        foreach ($this->collectors as $collector) {
            $output = $collector->collect($context);

            if ($collector->name() === 'excerpts') {
                $excerpts = $output['excerpts'];

                continue;
            }

            $metrics[$collector->name()] = $output;
        }

        $inventory = $context->inventory;

        $metrics['files_total'] = count($inventory?->files ?? []);
        $metrics['loc_total'] = $inventory?->totalLoc ?? 0;
        $metrics['complexity_total'] = $inventory?->totalComplexity ?? 0;
        $metrics['languages'] = $inventory?->languages ?? [];
        $metrics['largest_files'] = array_map(
            fn (array $file): array => ['path' => $file['path'], 'loc' => $file['loc']],
            array_slice($inventory?->files ?? [], 0, 20),
        );

        // Every collector above reads bytes we did not write -- git branch
        // names and author strings, file names out of `git log --name-only`,
        // manifest contents, scanner inventories. $metrics is persisted
        // through an Eloquent array cast on the very next line of the
        // pipeline, and that cast throws on invalid UTF-8, so scrubbing here
        // covers every collector at once instead of trusting each to remember.
        return ['metrics' => Utf8::scrubDeep($metrics), 'excerpts' => $excerpts];
    }
}
