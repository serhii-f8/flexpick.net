<?php

namespace App\Services\AuditReport\Findings\Normalizers;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoRelativePath;

/**
 * Shared normalization for the two SARIF-emitting scanners (Gitleaks, Semgrep).
 *
 * The `snippet` field carried by SARIF regions is deliberately never read.
 * That is the second of the two independent guards on F5.2.6 — the first is
 * `--redact` at invocation. Trusting one flag at one call site is not enough
 * for a guarantee this consequential (spec §5.4).
 */
class SarifNormalizer
{
    /**
     * `$repoPath` is the clone root the scanner was pointed at — both emitters
     * report `artifactLocation.uri` absolutely.
     *
     * @param  callable(array<string, mixed>): Severity  $severityFor
     * @param  callable(array<string, mixed>, string): string  $familyFor
     * @param  callable(string): string  $dimensionFor  rule id → score dimension
     * @return list<Finding>
     */
    public function normalize(
        array $sarif,
        string $tool,
        string $repoPath,
        callable $severityFor,
        callable $familyFor,
        callable $dimensionFor,
    ): array {
        $findings = [];

        foreach ($sarif['runs'] ?? [] as $run) {
            $ruleDescriptions = $this->ruleDescriptions($run);

            foreach ($run['results'] ?? [] as $result) {
                $location = $result['locations'][0]['physicalLocation'] ?? [];
                $uri = $location['artifactLocation']['uri'] ?? null;

                if (! is_string($uri) || $uri === '') {
                    continue;
                }

                // Empty means the hit is outside the clone — skip it rather
                // than report a finding nothing downstream can resolve.
                $path = RepoRelativePath::from($repoPath, $uri);

                if ($path === '') {
                    continue;
                }

                $ruleId = (string) ($result['ruleId'] ?? 'unknown');
                $line = $location['region']['startLine'] ?? null;

                $findings[] = new Finding(
                    tool: $tool,
                    ruleId: $ruleId,
                    ruleFamily: $familyFor($result, $ruleId),
                    severity: $severityFor($result),
                    path: $path,
                    line: is_int($line) ? $line : null,
                    // The rule's own description, never the matched snippet.
                    message: $ruleDescriptions[$ruleId] ?? (string) ($result['message']['text'] ?? $ruleId),
                    dimension: $dimensionFor($ruleId),
                );
            }
        }

        return $findings;
    }

    /** @return array<string, string> */
    private function ruleDescriptions(array $run): array
    {
        $descriptions = [];

        foreach ($run['tool']['driver']['rules'] ?? [] as $rule) {
            if (isset($rule['id'])) {
                $descriptions[(string) $rule['id']] = (string) ($rule['description']['text'] ?? $rule['id']);
            }
        }

        return $descriptions;
    }
}
