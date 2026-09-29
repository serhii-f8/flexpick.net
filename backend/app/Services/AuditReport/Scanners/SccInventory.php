<?php

namespace App\Services\AuditReport\Scanners;

/**
 * The repository's file inventory, produced by scc (or by the Finder fallback
 * when scc is unavailable — spec §10). Later scanners cap their file sets
 * against this, and the excerpt collector selects from it.
 */
final readonly class SccInventory
{
    /**
     * @param  list<array{path: string, loc: int, complexity: int}>  $files  descending by loc
     * @param  array<string, array{files: int, loc: int}>  $languages
     * @param  int|null  $billableCode  scc's code lines (no blanks, comments, generated or
     *                                  minified files) -- the only count the audit is billed
     *                                  on. Null when scc did not measure it (fallback walk,
     *                                  failed sizing run).
     */
    public function __construct(
        public array $files,
        public array $languages,
        public int $totalLoc,
        public int $totalComplexity,
        public ?int $billableCode = null,
    ) {}

    /** @return list<string> */
    public function paths(int $limit): array
    {
        return array_map(
            fn (array $file): string => $file['path'],
            array_slice($this->files, 0, $limit),
        );
    }
}
