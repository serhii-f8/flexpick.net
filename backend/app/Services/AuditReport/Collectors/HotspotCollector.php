<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;

class HotspotCollector implements Collector
{
    public function name(): string
    {
        return 'hotspots';
    }

    public function collect(RepoContext $context): array
    {
        $log = Process::path($context->path)->run(['git', 'log', '--name-only', '--format=']);
        $changes = array_count_values(array_filter(explode("\n", trim(Utf8::scrub($log->output())))));

        $context->withChurn(array_map('intval', $changes));

        $locByPath = array_column($context->inventory?->files ?? [], 'loc', 'path');

        $hotspots = [];
        foreach ($changes as $path => $count) {
            if ($count < 2 || ! isset($locByPath[$path])) {
                continue;
            }
            $hotspots[] = ['path' => $path, 'changes' => $count, 'loc' => $locByPath[$path]];
        }

        // Total order — churn×size, then path — so repeat runs list the same
        // hotspots in the same order (spec §6.3).
        usort($hotspots, fn (array $a, array $b): int => [$b['changes'] * $b['loc'], $a['path']]
            <=> [$a['changes'] * $a['loc'], $b['path']]);

        return array_slice($hotspots, 0, 10);
    }
}
