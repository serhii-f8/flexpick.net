<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Services\GitProviders\GuardsRepositoryListing;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuardsRepositoryListingTest extends TestCase
{
    private function guard(): object
    {
        return new class
        {
            use GuardsRepositoryListing;

            public function check($response): void
            {
                $this->guardListingResponse($response, 'GitHub');
            }

            public function next(?string $header): ?string
            {
                return $this->nextLinkUrl($header);
            }
        };
    }

    public function test_a_successful_response_passes(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->guard()->check(Http::get('https://x.test'));

        $this->addToAssertionCount(1);
    }

    public function test_401_and_an_ordinary_403_mean_the_token_was_rejected(): void
    {
        foreach ([401, 403] as $status) {
            Http::fake(['*' => Http::response([], $status)]);

            try {
                $this->guard()->check(Http::get('https://x.test'));
                $this->fail("{$status} should be rejected");
            } catch (GitRepositoryListingRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_rate_limited_403_is_transient_not_a_rejection(): void
    {
        Http::fake(['*' => Http::response([], 403, ['X-RateLimit-Remaining' => '0'])]);

        $this->expectException(GitAccessTemporarilyUnavailableException::class);

        $this->guard()->check(Http::get('https://x.test'));
    }

    public function test_429_5xx_and_unknown_statuses_are_transient(): void
    {
        foreach ([429, 500, 503, 404, 422] as $status) {
            Http::fake(['*' => Http::response([], $status)]);

            try {
                $this->guard()->check(Http::get('https://x.test'));
                $this->fail("{$status} should be transient");
            } catch (GitAccessTemporarilyUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_next_link_is_parsed_from_a_github_link_header(): void
    {
        $header = '<https://api.github.com/user/repos?page=2>; rel="next", <https://api.github.com/user/repos?page=9>; rel="last"';

        $this->assertSame('https://api.github.com/user/repos?page=2', $this->guard()->next($header));
        $this->assertNull($this->guard()->next('<https://api.github.com/user/repos?page=9>; rel="last"'));
        $this->assertNull($this->guard()->next(null));
    }
}
