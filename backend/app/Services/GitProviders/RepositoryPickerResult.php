<?php

namespace App\Services\GitProviders;

final class RepositoryPickerResult
{
    public function __construct(
        public readonly RepositoryPickerState $state,
        public readonly RepositoryPage $page,
    ) {}

    public static function ok(RepositoryPage $page): self
    {
        return new self(RepositoryPickerState::Ok, $page);
    }

    public static function of(RepositoryPickerState $state): self
    {
        return new self($state, RepositoryPage::empty());
    }
}
