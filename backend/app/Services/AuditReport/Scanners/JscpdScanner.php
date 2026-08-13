<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
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
            Process::timeout((int) config('audit.scanners.jscpd.timeout'))
                ->run([
                    (string) config('audit.scanners.jscpd.bin'),
                    $context->path,
                    '--reporters', 'json',
                    '--output', $outputDir,
                    '--silent',
                    // Repo-supplied .jscpd.json must not steer the scan (spec §5.4).
                    '--config', (string) config('audit.scanners.jscpd.config'),
                    '--max-size', (string) config('audit.scanners.jscpd.max_file_size'),
                ]);

            $report = $outputDir.'/jscpd-report.json';

            if (! file_exists($report)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $decoded = is_array($decoded) ? $decoded : [];

            // Recorded on the per-run context, never on $this — the scanner
            // instance outlives the run inside a Horizon worker.
            $context->record('duplication_pct', $this->duplicationPercentage($decoded));

            return $this->normalize($decoded, $context->path);
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
    public function normalize(array $raw, string $repoPath): array
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
