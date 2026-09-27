<?php

namespace Tests\Feature\Services\GitProviders;

use App\Services\GitProviders\BitbucketProvider;
use App\Services\GitProviders\GitHubProvider;
use App\Services\GitProviders\GitLabProvider;
use App\Services\GitProviders\GitProviderResolver;
use Tests\Feature\FeatureTest;

class GitProviderResolverTest extends FeatureTest
{
    public function test_resolves_by_host(): void
    {
        $resolver = app(GitProviderResolver::class);

        $this->assertInstanceOf(GitHubProvider::class, $resolver->forUrl('https://github.com/acme/app'));
        $this->assertInstanceOf(GitLabProvider::class, $resolver->forUrl('https://gitlab.com/acme/app'));
        $this->assertInstanceOf(BitbucketProvider::class, $resolver->forUrl('https://bitbucket.org/acme/app'));
    }

    public function test_returns_null_for_an_unrecognized_host(): void
    {
        $this->assertNull(app(GitProviderResolver::class)->forUrl('https://example.com/acme/app'));
        $this->assertNull(app(GitProviderResolver::class)->forUrl('file:///tmp/fixture-repo'));
    }

    public function test_resolves_by_provider_name(): void
    {
        $resolver = app(GitProviderResolver::class);

        $this->assertInstanceOf(GitHubProvider::class, $resolver->forProviderName('github'));
        $this->assertInstanceOf(GitLabProvider::class, $resolver->forProviderName('gitlab'));
        $this->assertInstanceOf(BitbucketProvider::class, $resolver->forProviderName('bitbucket'));
    }

    public function test_throws_for_an_unknown_provider_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(GitProviderResolver::class)->forProviderName('sourceforge');
    }
}
