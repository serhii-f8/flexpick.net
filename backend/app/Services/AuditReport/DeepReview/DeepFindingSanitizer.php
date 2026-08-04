<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The hallucination guard.
 *
 * A finding bound to a file the model was never sent is fabricated by
 * definition. Related paths are held to a weaker standard — the model may
 * legitimately reference a file it saw REFERENCED but was not given — so those
 * only have to exist in the repository inventory.
 *
 * This is not part of ReportPayload::validate() because it needs run context
 * the validator deliberately does not have.
 */
class DeepFindingSanitizer
{
    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $reviewedPaths
     * @param  list<string>  $inventoryPaths
     * @return array{findings: list<array<string, mixed>>, dropped: int, strippedRelated: int}
     */
    public function sanitize(array $findings, array $reviewedPaths, array $inventoryPaths): array
    {
        $reviewed = array_flip($reviewedPaths);
        $inventory = array_flip($inventoryPaths);

        $kept = [];
        $dropped = 0;
        $stripped = 0;

        foreach ($findings as $finding) {
            $path = str_replace('\\', '/', (string) ($finding['path'] ?? ''));

            if (! isset($reviewed[$path])) {
                $dropped++;

                continue;
            }

            $related = [];

            foreach ($finding['related_paths'] ?? [] as $candidate) {
                $candidate = str_replace('\\', '/', (string) $candidate);

                if (isset($inventory[$candidate])) {
                    $related[] = $candidate;

                    continue;
                }

                $stripped++;
            }

            $finding['path'] = $path;
            $finding['related_paths'] = $related;
            $kept[] = $finding;
        }

        return ['findings' => $kept, 'dropped' => $dropped, 'strippedRelated' => $stripped];
    }
}
