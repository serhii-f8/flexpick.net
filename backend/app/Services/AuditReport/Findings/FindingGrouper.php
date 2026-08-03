<?php

namespace App\Services\AuditReport\Findings;

class FindingGrouper
{
    /**
     * Collapse deduplicated findings into ranked problem families.
     *
     * Every ordering here is total. Ranking ties break on the group key, and
     * example selection breaks on path then line, so the same repository
     * produces byte-identical groups on every run (spec §6.3) — score deltas
     * and persisted examples both depend on that.
     *
     * @param  list<DedupedFinding>  $findings
     * @return list<FindingGroup>
     */
    public function group(array $findings): array
    {
        $depth = (int) config('audit.findings.directory_depth');
        $maxGroups = (int) config('audit.findings.max_groups');
        $maxExamples = (int) config('audit.findings.max_group_examples');

        /** @var array<string, list<DedupedFinding>> $buckets */
        $buckets = [];

        foreach ($findings as $deduped) {
            $key = $deduped->finding->ruleFamily.'|'.$deduped->finding->directory($depth);
            $buckets[$key][] = $deduped;
        }

        ksort($buckets);

        $groups = [];

        foreach ($buckets as $key => $bucket) {
            [$ruleFamily, $directory] = explode('|', $key, 2);

            $severities = array_map(fn (DedupedFinding $d): Severity => $d->finding->severity, $bucket);
            $score = array_sum(array_map(fn (Severity $s): int => $s->weight(), $severities));

            $tools = array_values(array_unique(array_merge(...array_map(fn (DedupedFinding $d): array => $d->tools, $bucket))));
            sort($tools);

            // Uniform across a rule family by construction; take the lowest
            // sorted value so a malformed ruleset still groups deterministically.
            $dimensions = array_unique(array_map(
                fn (DedupedFinding $d): string => $d->finding->dimension,
                $bucket,
            ));
            sort($dimensions);

            $groups[] = new FindingGroup(
                ruleFamily: $ruleFamily,
                directory: $directory,
                severity: Severity::max(...$severities),
                count: count($bucket),
                score: $score,
                examples: $this->examples($bucket, $maxExamples),
                tools: $tools,
                dimension: $dimensions[0],
            );
        }

        usort($groups, fn (FindingGroup $a, FindingGroup $b): int => [$b->score, $a->ruleFamily, $a->directory]
            <=> [$a->score, $b->ruleFamily, $b->directory]);

        return array_slice($groups, 0, $maxGroups);
    }

    /**
     * Highest severity first, then path, then line — a total order, so the
     * same findings always cite the same examples.
     *
     * @param  list<DedupedFinding>  $bucket
     * @return list<array{path: string, line: int|null}>
     */
    private function examples(array $bucket, int $max): array
    {
        usort($bucket, function (DedupedFinding $a, DedupedFinding $b): int {
            $bySeverity = $b->finding->severity->isAtLeast($a->finding->severity) <=> $a->finding->severity->isAtLeast($b->finding->severity);

            return $bySeverity !== 0
                ? $bySeverity
                : [$a->finding->path, $a->finding->line ?? -1] <=> [$b->finding->path, $b->finding->line ?? -1];
        });

        return array_map(
            fn (DedupedFinding $d): array => ['path' => $d->finding->path, 'line' => $d->finding->line],
            array_slice($bucket, 0, $max),
        );
    }
}
