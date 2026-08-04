<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\SecretFileFilter;

/**
 * Deterministic risk-file selection (F5.12.3).
 *
 * Three signals with incompatible units — churn x size is an unbounded
 * integer, finding density is severity-weighted counts, sensitive domain is
 * binary — are each rank-normalized to 0-1 and combined by configured weights
 * into ONE ranked list. One list rather than per-signal quotas because the
 * token budget truncates from the bottom, and truncating quotas would need a
 * second policy that could silently delete an entire signal.
 */
class RiskFileSelector
{
    public function __construct(
        private SecretFileFilter $secretFiles,
        private SensitivePathMatcher $sensitivePaths,
    ) {}

    /** @param list<DedupedFinding> $dedupedFindings */
    public function select(
        RepoContext $context,
        array $dedupedFindings,
        DeepReviewProfile $profile,
    ): RiskFileSelection {
        $candidates = $this->candidates($context);
        $density = $this->findingDensity($dedupedFindings);

        $raw = [];

        foreach ($candidates as $file) {
            $path = $file['path'];

            $raw[$path] = [
                'churn' => (float) (($context->churn[$path] ?? 0) * $file['loc']),
                'findings' => (float) ($density[$path] ?? 0),
                'sensitive' => $this->sensitivePaths->matches($path) ? 1.0 : 0.0,
            ];
        }

        $normalized = [
            'churn' => $this->normalize($raw, 'churn'),
            'findings' => $this->normalize($raw, 'findings'),
            'sensitive' => $this->normalize($raw, 'sensitive'),
        ];

        $weights = (array) config('audit.deep_review.weights');
        $scored = [];

        foreach ($raw as $path => $values) {
            $score = 0.0;

            foreach (['churn', 'findings', 'sensitive'] as $signal) {
                $score += (float) $weights[$signal] * $normalized[$signal][$path];
            }

            $scored[$path] = $score;
        }

        // Total order: score descending, then path ascending, so repeat runs
        // on the same repository state produce an identical list.
        $paths = array_keys($scored);
        usort($paths, fn (string $a, string $b): int => [$scored[$b], $a] <=> [$scored[$a], $b]);

        $ranked = array_slice($paths, 0, $profile->maxFiles);

        return $this->build($context, $ranked, $raw, $normalized, $scored, $profile, count($candidates));
    }

    /**
     * Files eligible for review: the inventory, minus vendored and generated
     * code, minus anything whose contents must never be transmitted (Q17).
     *
     * @return list<array{path: string, loc: int, complexity: int}>
     */
    private function candidates(RepoContext $context): array
    {
        $exclusions = (array) config('audit.deep_review.path_exclusions');
        $candidates = [];

        foreach ($context->inventory?->files ?? [] as $file) {
            $path = str_replace('\\', '/', $file['path']);

            if ($this->secretFiles->excludes($path, $context->secretPaths)) {
                continue;
            }

            if ($this->isExcludedPath($path, $exclusions)) {
                continue;
            }

            if (! is_file($context->path.'/'.$path)) {
                continue;
            }

            $candidates[] = $file;
        }

        return $candidates;
    }

    /** @param list<string> $exclusions */
    private function isExcludedPath(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            $exclusion = (string) $exclusion;

            if (str_ends_with($exclusion, '/')) {
                if (str_starts_with($path, $exclusion) || str_contains($path, '/'.$exclusion)) {
                    return true;
                }

                continue;
            }

            if (fnmatch($exclusion, basename($path), FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Severity-weighted finding count per path, reusing the ranking weights
     * the scores already use so one critical outranks a pile of info hits.
     *
     * @param  list<DedupedFinding>  $findings
     * @return array<string, int>
     */
    private function findingDensity(array $findings): array
    {
        $density = [];

        foreach ($findings as $deduped) {
            $path = str_replace('\\', '/', $deduped->finding->path);
            $density[$path] = ($density[$path] ?? 0) + $deduped->finding->severity->weight();
        }

        return $density;
    }

    /**
     * Rank-normalize one signal to 0-1.
     *
     * A raw value of zero maps to exactly zero, and only nonzero values are
     * ranked among themselves. Both finding density and sensitive domain are
     * mostly-zero signals, so a naive percentile would hand a file with no
     * findings a substantial score purely because most others have none —
     * making the signal close to pure noise on a clean repository.
     *
     * @param  array<string, array<string, float>>  $raw
     * @return array<string, float>
     */
    private function normalize(array $raw, string $signal): array
    {
        $values = [];

        foreach ($raw as $path => $signals) {
            if ($signals[$signal] > 0.0) {
                $values[$path] = $signals[$signal];
            }
        }

        $normalized = array_map(fn (): float => 0.0, $raw);

        if ($values === []) {
            return $normalized;
        }

        $distinct = array_values(array_unique($values));
        sort($distinct);
        $count = count($distinct);

        foreach ($values as $path => $value) {
            $rank = array_search($value, $distinct, true) + 1;
            $normalized[$path] = $rank / $count;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $ranked
     * @param  array<string, array<string, float>>  $raw
     * @param  array<string, array<string, float>>  $normalized
     * @param  array<string, float>  $scored
     */
    private function build(
        RepoContext $context,
        array $ranked,
        array $raw,
        array $normalized,
        array $scored,
        DeepReviewProfile $profile,
        int $candidatesConsidered,
    ): RiskFileSelection {
        $files = [];
        $estimated = 0;

        foreach ($ranked as $index => $path) {
            $content = (string) file_get_contents($context->path.'/'.$path, length: $profile->fileBytes);
            $tokens = $this->estimateTokens(strlen($content));
            $estimated += $tokens;

            $files[] = new SelectedFile(
                path: $path,
                rank: $index + 1,
                score: $scored[$path],
                signals: [
                    'churn' => ['raw' => $raw[$path]['churn'], 'normalized' => $normalized['churn'][$path]],
                    'findings' => ['raw' => $raw[$path]['findings'], 'normalized' => $normalized['findings'][$path]],
                    'sensitive' => ['raw' => $raw[$path]['sensitive'], 'normalized' => $normalized['sensitive'][$path]],
                ],
                content: $content,
                estimatedTokens: $tokens,
            );
        }

        return new RiskFileSelection(
            files: $files,
            candidatesConsidered: $candidatesConsidered,
            selectedBeforeBudget: count($files),
            truncated: false,
            belowFloor: count($files) < $profile->minFiles,
            estimatedInputTokens: $estimated + (int) config('audit.deep_review.overhead_tokens'),
            fileBytesUsed: $profile->fileBytes,
            selectionVersion: (int) config('audit.deep_review.selection_version'),
        );
    }

    public function estimateTokens(int $bytes): int
    {
        return (int) ceil(
            $bytes / (float) config('audit.deep_review.chars_per_token')
                * (float) config('audit.deep_review.safety_margin')
        );
    }
}
