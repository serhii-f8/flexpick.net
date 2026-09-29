<?php

namespace App\Services\GitProviders;

/**
 * One repository in a connected account's listing. Scalar-only so it can sit in
 * the cache as a plain array; $url is the canonical https page URL, the shape
 * GitRepoAccessResolver accepts for a launch.
 */
final class RepositoryEntry
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $url,
        public readonly bool $private,
        public readonly ?string $defaultBranch,
        public readonly ?string $updatedAt,
    ) {}

    /** @return array{fullName: string, url: string, private: bool, defaultBranch: ?string, updatedAt: ?string} */
    public function toArray(): array
    {
        return [
            'fullName' => $this->fullName,
            'url' => $this->url,
            'private' => $this->private,
            'defaultBranch' => $this->defaultBranch,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['fullName'],
            (string) $row['url'],
            (bool) ($row['private'] ?? false),
            isset($row['defaultBranch']) ? (string) $row['defaultBranch'] : null,
            isset($row['updatedAt']) ? (string) $row['updatedAt'] : null,
        );
    }
}
