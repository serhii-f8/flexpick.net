<?php

namespace App\Services\GitProviders;

/** One page of a connected account's repositories. */
final class RepositoryPage
{
    public const PAGE_SIZE = 20;

    /** @param list<RepositoryEntry> $items */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
    ) {}

    public static function empty(): self
    {
        return new self([], false);
    }

    /**
     * Filter a full listing by a case-insensitive substring of the full name, then
     * cut the requested page. Providers with no server-side search build their
     * page from this.
     *
     * @param  list<RepositoryEntry>  $all
     */
    public static function fromEntries(array $all, ?string $search, int $page): self
    {
        $term = mb_strtolower(trim((string) $search));

        $matching = $term === ''
            ? $all
            : array_values(array_filter($all, fn (RepositoryEntry $e): bool => str_contains(mb_strtolower($e->fullName), $term)));

        $page = max(1, $page);

        return new self(
            array_slice($matching, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            count($matching) > $page * self::PAGE_SIZE,
        );
    }
}
