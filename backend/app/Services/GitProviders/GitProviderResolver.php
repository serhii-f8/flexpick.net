<?php

namespace App\Services\GitProviders;

use InvalidArgumentException;

class GitProviderResolver
{
    /**
     * The provider names {@see forProviderName()} can resolve. Used by callers
     * (e.g. the git-connection OAuth flow) to reject an unknown provider name
     * with a clean 404 before calling forProviderName(), instead of letting its
     * InvalidArgumentException surface as an uncaught 500.
     */
    public const KNOWN_PROVIDER_NAMES = ['github', 'gitlab', 'bitbucket'];

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
