<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;

/**
 * Cross-language duplication. Supersedes the md5 line-hash heuristic that
 * MetricsCollector carried (F5.12.2).
 */
class JscpdScanner implements Scanner
{
    public function name(): string
    {
        return 'jscpd';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.jscpd.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.jscpd.version');
    }

    public function scan(RepoContext $context): array
    {
        $outputDir = sys_get_temp_dir().'/jscpd-'.bin2hex(random_bytes(8));
        mkdir($outputDir, 0755, true);

        try {
            $runConfig = $outputDir.'/jscpd.json';
            $base = json_decode((string) file_get_contents((string) config('audit.scanners.jscpd.config')), true, flags: JSON_THROW_ON_ERROR);
            $base['ignore'] = array_values(array_unique([
                ...($base['ignore'] ?? []),
                ...($context->inventory !== null ? $this->ignoreGlobs($context->inventory, $context->classifier) : []),
            ]));
            file_put_contents($runConfig, json_encode($base, JSON_THROW_ON_ERROR));

            Process::timeout((int) config('audit.scanners.jscpd.timeout'))
                ->run([
                    (string) config('audit.scanners.jscpd.bin'),
                    $context->path,
                    '--reporters', 'json',
                    '--output', $outputDir,
                    '--silent',
                    // Repo-supplied .jscpd.json must not steer the scan (spec §5.4).
                    '--config', $runConfig,
                    '--max-size', (string) config('audit.scanners.jscpd.max_file_size'),
                ]);

            $report = $outputDir.'/jscpd-report.json';

            if (! file_exists($report)) {
                // jscpd writes no report when nothing reaches its minimum block
                // size, so a repository that small is legitimately duplication-free.
                if ($this->tooSmallToHoldAClone($context)) {
                    $context->record('duplication_pct', 0.0);

                    return [];
                }

                // Otherwise it is a crashed or killed jscpd, not a clean repository.
                throw new ScannerSkipped('no_report');
            }

            $decoded = json_decode(Utf8::scrub((string) file_get_contents($report)), true, flags: JSON_THROW_ON_ERROR);
            $decoded = is_array($decoded) ? $decoded : [];

            // Recorded on the per-run context, never on $this — the scanner
            // instance outlives the run inside a Horizon worker.
            $context->record('duplication_pct', $this->duplicationPercentage($decoded));

            return $this->normalize($decoded, $context->path, $context->classifier);
        } finally {
            $this->deleteDirectory($outputDir);
        }
    }

    /**
     * One Finding per occurrence, not per pair — a block copied into four
     * directories must produce findings in all four so it groups where a
     * reader would look for it (spec §6.1).
     *
     * @return list<Finding>
     */
    public function normalize(array $raw, string $repoPath, ?PathClassifier $classifier = null): array
    {
        $findings = [];

        foreach ($raw['duplicates'] ?? [] as $duplicate) {
            $lines = (int) ($duplicate['lines'] ?? 0);

            foreach (['firstFile', 'secondFile'] as $side) {
                $file = $duplicate[$side] ?? null;

                if (! is_array($file) || ! isset($file['name'])) {
                    continue;
                }

                // jscpd already reports relative to the directory it was given;
                // normalized anyway so one contract holds across all scanners.
                $path = RepoRelativePath::from($repoPath, (string) $file['name']);

                if ($path === '') {
                    continue;
                }

                if ($classifier !== null && ! $classifier->classify($path)->isAnalyzed()) {
                    continue;
                }

                $findings[] = new Finding(
                    tool: $this->name(),
                    ruleId: 'jscpd.clone',
                    ruleFamily: 'duplication.clone',
                    severity: Severity::MEDIUM,
                    path: $path,
                    line: (int) ($file['start'] ?? 0) ?: null,
                    message: "A block of {$lines} lines is duplicated elsewhere in the repository.",
                    dimension: 'duplication',
                );
            }
        }

        return $findings;
    }

    private const MAX_IGNORE_GLOBS = 500;

    /** Known only from scc's inventory; without one the absence of a report stays a failure. */
    private function tooSmallToHoldAClone(RepoContext $context): bool
    {
        if ($context->inventory === null) {
            return false;
        }

        $minLines = (int) (json_decode((string) file_get_contents((string) config('audit.scanners.jscpd.config')), true)['minLines'] ?? 10);

        foreach ($context->inventory->files as $file) {
            if ($file['loc'] >= $minLines) {
                return false;
            }
        }

        return true;
    }

    /**
     * jscpd globs for everything scc's inventory excluded as generated,
     * vendored or a lockfile. Build-output directories collapse to `dir/**` so a
     * 94-file Storybook export is one glob, not 94.
     *
     * @return list<string>
     */
    public function ignoreGlobs(SccInventory $inventory, PathClassifier $classifier): array
    {
        $globs = [];

        foreach ($inventory->excluded as $file) {
            if (! in_array($file['reason'], ['generated', 'vendored', 'lockfile'], true)) {
                continue;
            }

            $directory = $classifier->buildOutputDirectory($file['path']);
            $globs[$directory !== null ? $directory.'/**' : $file['path']] = true;
        }

        $globs = array_keys($globs);
        sort($globs);

        return array_slice($globs, 0, self::MAX_IGNORE_GLOBS);
    }

    /** The repository-wide duplication percentage, for the duplication score. */
    public function duplicationPercentage(array $raw): float
    {
        return round((float) ($raw['statistics']['total']['percentage'] ?? 0.0), 1);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : @unlink($file);
        }

        @rmdir($directory);
    }
}
