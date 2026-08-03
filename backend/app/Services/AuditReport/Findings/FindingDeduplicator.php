<?php

namespace App\Services\AuditReport\Findings;

class FindingDeduplicator
{
    /**
     * Collapse findings sharing a fingerprint into one, keeping the highest
     * severity and recording every tool that reported it.
     *
     * Output is sorted by fingerprint so the result is independent of input
     * order — grouping downstream must be deterministic (spec §6.3).
     *
     * @param  list<Finding>  $findings
     * @return list<DedupedFinding>
     */
    public function dedupe(array $findings): array
    {
        /** @var array<string, array{finding: Finding, tools: array<string, true>}> $merged */
        $merged = [];

        foreach ($findings as $finding) {
            $key = $finding->fingerprint();

            if (! isset($merged[$key])) {
                $merged[$key] = ['finding' => $finding, 'tools' => []];
            } elseif ($finding->severity->isAtLeast($merged[$key]['finding']->severity)) {
                $merged[$key]['finding'] = $finding;
            }

            $merged[$key]['tools'][$finding->tool] = true;
        }

        ksort($merged);

        return array_values(array_map(function (array $entry): DedupedFinding {
            $tools = array_keys($entry['tools']);
            sort($tools);

            return new DedupedFinding($entry['finding'], $tools);
        }, $merged));
    }
}
