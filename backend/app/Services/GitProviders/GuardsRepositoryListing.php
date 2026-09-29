<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use Illuminate\Http\Client\Response;

/**
 * Shared status handling for the providers' repository-listing calls. Messages are
 * fixed text: nothing from the request or response (never the token) is echoed.
 */
trait GuardsRepositoryListing
{
    /**
     * 401 and an ordinary 403 mean the token (or its scope) was rejected. A 403 that
     * is a rate limit, 429, 5xx and anything unexpected are transient: try again.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    protected function guardListingResponse(Response $response, string $label): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $rateLimited = $response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== '';

        if ($status === 401 || ($status === 403 && ! $rateLimited)) {
            throw new GitRepositoryListingRejectedException("{$label} rejected the repository listing");
        }

        throw $this->listingUnavailable($label);
    }

    protected function listingUnavailable(string $label): GitAccessTemporarilyUnavailableException
    {
        return new GitAccessTemporarilyUnavailableException("{$label} repository listing is temporarily unavailable");
    }

    /** The `rel="next"` URL of a GitHub-style Link header, or null on the last page. */
    protected function nextLinkUrl(?string $linkHeader): ?string
    {
        if ($linkHeader !== null && preg_match('/<([^>]+)>\s*;\s*rel="next"/', $linkHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
