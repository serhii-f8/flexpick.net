<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;
use JsonException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Quality and security SAST. The most expensive scanner, so it runs last —
 * an earlier failure loses the least (F5.12.2).
 *
 * Only in-house rules ship (Q33). The Semgrep Registry's rule licence forbids
 * use in a competing commercial product; the LGPL-2.1 engine is merely invoked.
 */
class SemgrepScanner implements Scanner
{
    /** @var array<string, array{family: string, dimension: string}>|null */
    private ?array $ruleMetadata = null;

    public function __construct(private SarifNormalizer $normalizer) {}

    public function name(): string
    {
        return 'semgrep';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.semgrep.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.semgrep.version');
    }

    public function scan(RepoContext $context): array
    {
        $report = tempnam(sys_get_temp_dir(), 'semgrep-').'.sarif';

        try {
            Process::timeout((int) config('audit.scanners.semgrep.timeout'))
                ->run([
                    (string) config('audit.scanners.semgrep.bin'),
                    'scan',
                    // Only our rules. Never a registry identifier — that would
                    // both fetch over the network and import licensed rules.
                    '--config', (string) config('audit.scanners.semgrep.rules_path'),
                    '--sarif',
                    '--output', $report,
                    '--metrics=off',
                    '--disable-version-check',
                    // Repo-supplied .semgrepignore must not steer the scan (spec §5.4).
                    '--no-git-ignore',
                    $context->path,
                ]);

            return $this->normalize($this->decode($report), $context->path);
        } finally {
            @unlink($report);
        }
    }

    /** @return list<Finding> */
    public function normalize(array $sarif, string $repoPath): array
    {
        return $this->normalizer->normalize(
            $sarif,
            $this->name(),
            $repoPath,
            fn (array $result): Severity => match ($result['level'] ?? 'warning') {
                'error' => Severity::HIGH,
                'note' => Severity::LOW,
                default => Severity::MEDIUM,
            },
            fn (array $result, string $ruleId): string => $this->familyFor($ruleId),
            fn (string $ruleId): string => $this->dimensionFor($ruleId) ?? 'security_hygiene',
        );
    }

    /**
     * The score dimension a rule feeds, from its metadata.dimension (spec §5.3).
     * This is the ONLY rule-to-dimension mapping in the system: ScoreCalculator
     * routes on the dimension the finding carries, never on which tool found it.
     */
    public function dimensionFor(string $ruleId): ?string
    {
        return $this->matchedMetadata($ruleId)['dimension'] ?? null;
    }

    private function familyFor(string $ruleId): string
    {
        return $this->matchedMetadata($ruleId)['family']
            // Fall back to the id's namespace: flexpick.php.sql-interpolation → php.injection
            // is unavailable, so use the middle segments verbatim.
            ?? implode('.', array_slice(explode('.', $ruleId), 1, 1)) ?: 'semgrep.other';
    }

    /**
     * Real semgrep prefixes the SARIF ruleId with the rule's path relative to
     * --config (dot-joined), which duplicates our own id segment whenever a
     * rule file's directory mirrors its id's namespace — e.g. our declared
     * id `flexpick.php.missing-authorization` comes back as
     * `resources.semgrep.flexpick.php.flexpick.php.missing-authorization`.
     * An exact-key lookup never matches that; matching on suffix does, and
     * still matches the plain id verbatim (a string is always its own
     * suffix), which is what the hand-built SARIF fixtures in tests use.
     *
     * @return array{family: string, dimension: string}|null
     */
    private function matchedMetadata(string $ruleId): ?array
    {
        foreach ($this->metadata() as $ownId => $meta) {
            if (str_ends_with($ruleId, $ownId)) {
                return $meta;
            }
        }

        return null;
    }

    /** @return array<string, array{family: string, dimension: string}> */
    private function metadata(): array
    {
        if ($this->ruleMetadata !== null) {
            return $this->ruleMetadata;
        }

        $metadata = [];
        $rulesPath = (string) config('audit.scanners.semgrep.rules_path');

        if (is_dir($rulesPath)) {
            foreach ((new Finder)->files()->in($rulesPath)->name('*.yaml') as $file) {
                foreach (Yaml::parseFile($file->getRealPath())['rules'] ?? [] as $rule) {
                    $metadata[(string) $rule['id']] = [
                        'family' => (string) ($rule['metadata']['family'] ?? 'semgrep.other'),
                        'dimension' => (string) ($rule['metadata']['dimension'] ?? 'structure'),
                    ];
                }
            }
        }

        return $this->ruleMetadata = $metadata;
    }

    private function decode(string $path): array
    {
        $decoded = json_decode(Utf8::scrub((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('semgrep report is not an object');
        }

        return $decoded;
    }
}
