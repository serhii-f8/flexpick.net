<?php

namespace App\Services\GitProviders;

use InvalidArgumentException;

class GitProviderResolver
{
    public function __construct(
        private GitHubProvider $github,
        private GitLabProvider $gitlab,
        private BitbucketProvider $bitbucket,
    ) {}

    public function forUrl(string $url): ?GitProvider
    {
        return match (parse_url($url, PHP_URL_HOST)) {
            'github.com' => $this->github,
            'gitlab.com' => $this->gitlab,
            'bitbucket.org' => $this->bitbucket,
            default => null,
        };
    }

    public function forProviderName(string $name): GitProvider
    {
        return match ($name) {
            'github' => $this->github,
            'gitlab' => $this->gitlab,
            'bitbucket' => $this->bitbucket,
            default => throw new InvalidArgumentException("Unknown git provider: {$name}"),
        };
    }
}
