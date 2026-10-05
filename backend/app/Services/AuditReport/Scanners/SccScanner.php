<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/**
 * Size, language breakdown, and per-file complexity.
 *
 * Always first: its inventory sizes the budgets for everything after
 * (F5.12.2). Its only findings are committed build-output directories; its
 * main output is RepoContext::$inventory.
 */
class SccScanner implements Scanner
{
    public const NON_CODE_LANGUAGES = [
        'Markdown', 'JSON', 'JSONL', 'YAML', 'Plain Text', 'License', 'ReStructuredText', 'AsciiDoc', 'CSV', 'SVG',
    ];

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

        $decoded = json_decode(Utf8::scrub($result->output()), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new ScannerSkipped('empty_output');
        }

        [$billableCode, $keptPaths] = $this->filteredPass($context->path);

        $generated = $keptPaths === null ? [] : array_values(array_diff($this->paths($decoded, $context->path), $keptPaths));
        $context->withClassifier(PathClassifier::forRepository($context->path, $generated));

        $context->withInventory($this->toInventory($decoded, $context->path, $billableCode, $context->classifier));

        return $this->buildOutputFindings($context);
    }

    /**
     * The --no-gen --no-min-gen pass: what the audit is billed on, and — diffed
     * against the full pass — which files scc detected as generated or minified.
     * A separate run, because those flags would silently drop files the report
     * should list as excluded. [null, null] when it fails: billing then charges
     * nothing extra and classification falls back to path rules.
     *
     * @return array{0: ?int, 1: ?list<string>}
     */
    private function filteredPass(string $path): array
    {
        try {
            $result = Process::timeout((int) config('audit.scanners.scc.timeout'))
                ->run([
                    (string) config('audit.scanners.scc.bin'),
                    '--format', 'json',
                    '--by-file',
                    '--no-gen',
                    '--no-min-gen',
                    '--exclude-dir', implode(',', self::EXCLUDED_DIRS),
                    $path,
                ]);

            if (! $result->successful()) {
                return [null, null];
            }

            $decoded = json_decode(Utf8::scrub($result->output()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [null, null];
        }

        return is_array($decoded) ? [$this->sumCode($decoded), $this->paths($decoded, $path)] : [null, null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $raw
     * @return list<string>
     */
    private function paths(array $raw, string $repoPath): array
    {
        $paths = [];

        foreach ($raw as $language) {
            foreach ($language['Files'] ?? [] as $file) {
                $path = RepoRelativePath::from($repoPath, (string) ($file['Location'] ?? ''));

                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Build output (a Storybook export, a dist/ bundle) committed to version
     * control is worth one line in the report — not one finding per minified
     * file, and never a structure penalty per file.
     *
     * @return list<Finding>
     */
    private function buildOutputFindings(RepoContext $context): array
    {
        $byDirectory = [];

        foreach ($context->inventory?->excluded ?? [] as $file) {
            $directory = $context->classifier->buildOutputDirectory($file['path']);

            if ($directory !== null) {
                $byDirectory[$directory][] = $file['path'];
            }
        }

        ksort($byDirectory);
        $findings = [];

        foreach ($byDirectory as $directory => $paths) {
            sort($paths);
            $count = count($paths);

            $findings[] = new Finding(
                tool: $this->name(),
                ruleId: 'structure.committed-build-output',
                ruleFamily: 'structure.committed-build-output',
                severity: Severity::LOW,
                path: $paths[0],
                line: null,
                message: "Build output is committed to version control: {$directory}/ ({$count} files). Generate it in the build instead.",
                dimension: 'structure',
            );
        }

        return $findings;
    }

    /** @param  array<int, array<string, mixed>>  $raw */
    public function sumCode(array $raw): int
    {
        return (int) array_sum(array_map(fn (array $language): int => (int) ($language['Code'] ?? 0), $raw));
    }

    /** scc measures; it never reports a defect. @return list<never> */
    public function normalize(mixed $raw): array
    {
        return [];
    }

    /**
     * `$repoPath` is the clone root scc was pointed at: it reports `Location`
     * absolutely, and every consumer of the inventory joins the path back onto
     * that root.
     *
     * @param  array<int, array<string, mixed>>  $raw
     */
    public function toInventory(array $raw, string $repoPath, ?int $billableCode = null, ?PathClassifier $classifier = null): SccInventory
    {
        $classifier ??= new PathClassifier;
        $files = [];
        $excluded = [];
        $languages = [];
        $totalComplexity = 0;

        foreach ($raw as $language) {
            $name = (string) ($language['Name'] ?? 'Unknown');

            foreach ($language['Files'] ?? [] as $file) {
                $path = RepoRelativePath::from($repoPath, (string) ($file['Location'] ?? ''));

                if ($path === '') {
                    continue;
                }

                $loc = (int) ($file['Lines'] ?? 0);
                $class = $classifier->classify($path);

                if (! $class->isAnalyzed()) {
                    $excluded[] = ['path' => $path, 'loc' => $loc, 'reason' => $class->value];

                    continue;
                }

                if (in_array($name, self::NON_CODE_LANGUAGES, true)) {
                    $excluded[] = ['path' => $path, 'loc' => $loc, 'reason' => 'non_code'];

                    continue;
                }

                $complexity = (int) ($file['Complexity'] ?? 0);
                $files[] = ['path' => $path, 'loc' => $loc, 'complexity' => $complexity, 'class' => $class->value];
                $languages[$name]['files'] = ($languages[$name]['files'] ?? 0) + 1;
                $languages[$name]['loc'] = ($languages[$name]['loc'] ?? 0) + $loc;
                $totalComplexity += $complexity;
            }
        }

        // Total order — descending loc, then path — so repeat runs select the
        // same excerpts and cite the same files (spec §6.3).
        usort($files, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        usort($excluded, fn (array $a, array $b): int => [$b['loc'], $a['path']] <=> [$a['loc'], $b['path']]);
        ksort($languages);

        return new SccInventory(
            files: $files,
            languages: $languages,
            totalLoc: array_sum(array_column($files, 'loc')),
            totalComplexity: $totalComplexity,
            billableCode: $billableCode,
            excluded: $excluded,
        );
    }

    /**
     * Used when scc failed or is unavailable. Complexity is unavailable from a
     * plain walk, so it is zero — and the dimensions that depend on it are
     * marked not-measured rather than scored (spec §7.2, §10).
     */
    public function fallbackInventory(string $repoPath, ?PathClassifier $classifier = null): SccInventory
    {
        $classifier ??= new PathClassifier;
        $files = [];
        $excluded = [];
        $languages = [];

        $finder = (new Finder)->files()->in($repoPath)->exclude(self::EXCLUDED_DIRS)->size('< 2M');

        foreach ($finder as $file) {
            $loc = substr_count($file->getContents(), "\n") + 1;
            $extension = strtolower($file->getExtension()) ?: 'unknown';

            $class = $classifier->classify($file->getRelativePathname());

            if (! $class->isAnalyzed() || in_array($extension, ['md', 'json', 'yaml', 'yml', 'txt', 'csv', 'svg'], true)) {
                $excluded[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'reason' => $class->isAnalyzed() ? 'non_code' : $class->value];

                continue;
            }

            $files[] = ['path' => $file->getRelativePathname(), 'loc' => $loc, 'complexity' => 0, 'class' => $class->value];
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
            excluded: $excluded,
        );
    }
}
