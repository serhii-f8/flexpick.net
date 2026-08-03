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
     */
    public function __construct(
        public array $files,
        public array $languages,
        public int $totalLoc,
        public int $totalComplexity,
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
