<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\FindingGroup;

/**
 * Deterministic context only (spec D6): metrics, ranked groups, the selection
 * rationale, and the file contents.
 *
 * The tier-1 NARRATIVE is deliberately absent. It is another model's opinion,
 * and models anchor hard on prior framing — feeding it in would buy
 * elaboration of the $49 report and destroy the ability to read agreement
 * between the two sections as corroboration rather than echo.
 */
class DeepReviewPromptComposer
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     */
    public function compose(array $metrics, array $groups, RiskFileSelection $selection): string
    {
        return implode("\n", [
            'Repository metrics (JSON):',
            json_encode($metrics, JSON_PRETTY_PRINT),
            '',
            'Problem groups the static analyzers produced, ranked by severity and count:',
            $this->renderGroups($groups),
            '',
            sprintf(
                'The %d riskiest files, selected deterministically by churn x size, scanner-finding density, and sensitive-domain path heuristics. Rank 1 is riskiest.',
                count($selection->files),
            ),
            $this->renderFiles($selection),
            '',
            'Review these files and report findings bound to them.',
        ]);
    }

    /** @param list<FindingGroup> $groups */
    private function renderGroups(array $groups): string
    {
        if ($groups === []) {
            return "\n[no problem groups were produced for this run]\n";
        }

        $rendered = '';

        foreach ($groups as $group) {
            $rendered .= sprintf(
                "\n- %s in %s — %d finding(s), severity %s\n",
                $group->ruleFamily,
                $group->directory,
                $group->count,
                $group->severity->value,
            );
        }

        return $rendered;
    }

    private function renderFiles(RiskFileSelection $selection): string
    {
        $rendered = '';

        foreach ($selection->files as $file) {
            $signals = [];

            foreach ($file->signals as $name => $signal) {
                if ($signal['normalized'] > 0.0) {
                    $signals[] = $name;
                }
            }

            $rendered .= sprintf(
                "\n===== rank %d: %s (selected for: %s) =====\n%s\n",
                $file->rank,
                $file->path,
                $signals !== [] ? implode(', ', $signals) : 'inventory order',
                $file->content,
            );
        }

        return $rendered;
    }
}
