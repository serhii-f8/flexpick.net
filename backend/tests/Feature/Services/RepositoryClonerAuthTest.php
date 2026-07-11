<?php

namespace Tests\Feature\Services;

use App\Exceptions\AuditNotAnalyzableException;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class RepositoryClonerAuthTest extends FeatureTest
{
    public function test_preflight_with_token_uses_authenticated_github_url(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private');

        Process::assertRan(fn (PendingProcess $process) => in_array(
            'https://x-access-token:ghp_secret123@github.com/acme/private',
            $process->command,
            true,
        ));
    }

    public function test_preflight_without_use_token_stays_unauthenticated(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private', useToken: false);

        Process::assertRan(fn (PendingProcess $process) => in_array(
            'https://github.com/acme/private',
            $process->command,
            true,
        ));
    }

    public function test_non_github_url_is_never_authenticated(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result()]);

        app(RepositoryCloner::class)->preflight('https://gitlab.com/acme/repo');

        Process::assertRan(fn (PendingProcess $process) => in_array('https://gitlab.com/acme/repo', $process->command, true));
    }

    public function test_token_never_leaks_into_exception_message(): void
    {
        config(['audit.github_token' => 'ghp_secret123']);
        Process::fake(['*' => Process::result(exitCode: 128)]);

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/private');
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('ghp_secret123', $e->getMessage());
        }
    }
}
