<?php

namespace App\Services\AuditReport\Scanners;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/**
 * Size, language breakdown, and per-file complexity.
 *
 * Always first: its inventory sizes the budgets for everything after
 * (F5.12.2). It produces no findings — it populates RepoContext::$inventory
 * as its output.
 */
class SccScanner implements Scanner
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', 'dist', 'build', '.git', 'storage', '.next', 'coverage'];

    public function name(): string
    {
        return 'scc';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.scc.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.scc.version');
    }

    public function scan(RepoContext $context): array
    {
        $result = Process::timeout((int) config('audit.scanners.scc.timeout'))
            ->run([
                (string) config('audit.scanners.scc.bin'),
                '--format', 'json',
                '--by-file',
                '--exclude-dir', implode(',', self::EXCLUDED_DIRS),
                $context->path,
            ]);

        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        $context->withInventory($this->toInventory(is_array($decoded) ? $decoded : []));

        return $this->normalize($decoded);
    }

    /** scc measures; it never reports a defect. @return list<never> */
    public function normalize(mixed $raw): array
    {
        return [];
    }

    /** @param array<int, array<string, mixed>> $raw */
    public function toInventory(array $raw): SccInventory
    {
        $files = [];
        $languages = [];
        $totalComplexity = 0;

        foreach ($raw as $language) {
            $name = (string) ($language['Name'] ?? 'Unknown');

            foreach ($language['Files'] ?? [] as $file) {
                $loc = (int) ($file['Lines'] ?? 0);
                $complexity = (int) ($file['Complexity'] ?? 0);

                $files[] = [
                    'path' => ltrim((string) $file['Location'], './'),
                    'loc' => $loc,
                    'complexity' => $complexity,
                ];

                $languages[$name]['files'] = ($languages[$name]['files'] ?? 0) + 1;
                $languages[$name]['loc'] = ($languages[$name]['loc'] ?? 0) + $loc;
                $totalComplexity += $complexity;
            }
        }

        // Total order — descending loc, then path — so repeat runs select the
        // same excerpts and cite the same files (spec §6.3).
        usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        ksort($languages);

        return new SccInventory(
            files: $files,
            languages: $languages,
            totalLoc: array_sum(array_column($files, 'loc')),
            totalComplexity: $totalComplexity,
        );
    }

    /**
     * Used when scc failed or is unavailable. Complexity is unavailable from a
     * plain walk, so it is zero — and the dimensions that depend on it are
     * marked not-measured rather than scored (spec §7.2, §10).
     */
    public function fallbackInventory(string $repoPath): SccInventory
    {
        $files = [];
        $languages = [];

        $finder = (new Finder)->files()->in($repoPath)->exclude(self::EXCLUDED_DIRS)->size('< 2M');

        foreach ($finder as $file) {
            $loc = substr_count($file->getContents(), "\n") + 1;
            $extension = strtolower($file->getExtension()) ?: 'unknown';

            $files[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'complexity' => 0];
            $languages[$extension]['files'] = ($languages[$extension]['files'] ?? 0) + 1;
            $languages[$extension]['loc'] = ($languages[$extension]['loc'] ?? 0) + $loc;
        }

        usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        ksort($languages);

        return new SccInventory(
            files: $files,
            languages: $languages,
            totalLoc: array_sum(array_column($files, 'loc')),
            totalComplexity: 0,
        );
    }
}
