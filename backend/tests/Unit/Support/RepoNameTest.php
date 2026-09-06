<?php

namespace Tests\Unit\Support;

use App\Support\RepoName;
use PHPUnit\Framework\TestCase;

class RepoNameTest extends TestCase
{
    public function test_a_github_url_shortens_to_owner_slash_repo(): void
    {
        $this->assertSame('acme/app', RepoName::short('https://github.com/acme/app'));
    }

    public function test_trailing_slashes_and_git_suffixes_are_dropped(): void
    {
        $this->assertSame('acme/app', RepoName::short('https://github.com/acme/app/'));
        $this->assertSame('acme/app', RepoName::short('https://github.com/acme/app.git'));
    }

    public function test_anything_that_is_not_a_repository_url_is_returned_untouched(): void
    {
        $this->assertSame('not a url', RepoName::short('not a url'));
        $this->assertSame('', RepoName::short(null));
    }
}
