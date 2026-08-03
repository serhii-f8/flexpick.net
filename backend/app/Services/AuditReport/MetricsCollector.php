<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Collectors\Collector;
use App\Services\AuditReport\Scanners\RepoContext;

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

        return ['metrics' => $metrics, 'excerpts' => $excerpts];
    }
}
