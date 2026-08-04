<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The sensitive-domain selection signal: authentication, authorization,
 * payments, uploads, and secrets handling.
 *
 * Binary rather than graded. Weighting categories against each other would be
 * invention without data, and the signal already carries the lowest weight of
 * the three because a path heuristic is the crudest of them.
 */
class SensitivePathMatcher
{
    public function matches(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));

        foreach ((array) config('audit.deep_review.sensitive_patterns') as $pattern) {
            if (fnmatch(strtolower((string) $pattern), $normalized)) {
                return true;
            }
        }

        return false;
    }
}
