<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Services\GitProviders\GitRepoAccessResolver;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class RepositoryCloner
{
    public function __construct(private GitRepoAccessResolver $accessResolver) {}

    public function preflight(string $url, ?Tenant $tenant = null): void
    {
        $result = $this->runGit(
            $url,
            $tenant,
            config('audit.preflight_timeout'),
            fn (string $resolvedUrl) => ['git', 'ls-remote', '--exit-code', $resolvedUrl, 'HEAD'],
            'Repository could not be reached: ',
        );

        if (! $result->successful()) {
            throw AuditNotAnalyzableException::accessDenied(
                'Repository is not publicly accessible: '.$this->redactUrl($url)
            );
        }
    }

    public function clone(string $url, string $uuid, ?Tenant $tenant = null, ?string $branch = null): string
    {
        $path = $this->workdirPath($uuid);
        File::ensureDirectoryExists(dirname($path));

        $command = ['git', 'clone', '--depth', (string) config('audit.clone_depth'), '--no-tags', '--single-branch'];
        if ($branch !== null) {
            $command[] = '--branch';
            $command[] = $branch;
        }

        try {
            $result = $this->runGit(
                $url,
                $tenant,
                config('audit.clone_timeout'),
                fn (string $resolvedUrl) => [...$command, $resolvedUrl, $path],
                'Repository could not be cloned: ',
            );
        } catch (AuditNotAnalyzableException $e) {
            $this->cleanup($uuid);

            throw $e;
        }

        if (! $result->successful()) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException('Repository could not be cloned: '.$this->redactUrl($url));
        }

        $sizeMb = $this->directorySizeMb($path);
        if ($sizeMb > config('audit.max_repo_size_mb')) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException(
                sprintf('Repository too large for automated analysis (%d MB)', $sizeMb)
            );
        }

        return $path;
    }

    /**
     * The current SHA of a remote ref, without cloning. `null` on any
     * failure (unreachable host, private repo with no git connection,
     * network error) -- callers (ScheduledAuditChangeChecker) must treat
     * that as "unknown," never as "unchanged."
     */
    public function remoteHeadSha(string $url, ?string $branch = null, ?Tenant $tenant = null): ?string
    {
        $ref = $branch !== null ? 'refs/heads/'.$branch : 'HEAD';

        try {
            $result = $this->runGit(
                $url,
                $tenant,
                config('audit.preflight_timeout'),
                fn (string $resolvedUrl) => ['git', 'ls-remote', $resolvedUrl, $ref],
                'Repository could not be reached: ',
            );
        } catch (AuditNotAnalyzableException) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($result->output()))[0] ?? '');
        $sha = strtok($firstLine, "\t ");

        return $sha !== false && $sha !== '' ? $sha : null;
    }

    /**
     * Resolve the (possibly credentialed) URL and run git against it, converting ANY
     * throwable into an AuditNotAnalyzableException whose message is built from the raw,
     * redacted URL alone.
     *
     * The resolved URL carries the tenant's real token, and a process timeout throws
     * ProcessTimedOutException whose message is the full command line -- token included.
     * Left alone, that message would reach failure_reason, the pipeline log, funnel events,
     * Sentry and the audit timeline every workspace member sees. The caught exception is
     * deliberately neither used for the message nor chained as `previous`. The same guard
     * covers resolution failures (e.g. a DecryptException after an APP_KEY rotation).
     *
     * @param  Closure(string): list<string>  $command  builds the argv from the resolved URL
     */
    private function runGit(string $url, ?Tenant $tenant, mixed $timeout, Closure $command, string $failurePrefix): ProcessResult
    {
        try {
            $resolvedUrl = $this->accessResolver->resolveCloneUrl($url, $tenant);

            return Process::timeout((int) $timeout)
                ->env(['GIT_TERMINAL_PROMPT' => '0'])
                ->run($command($resolvedUrl));
        } catch (Throwable) {
            throw new AuditNotAnalyzableException($failurePrefix.$this->redactUrl($url));
        }
    }

    public function sizeKb(string $path): int
    {
        $result = Process::run(['du', '-sk', $path]);

        return (int) strtok(trim($result->output()), "\t ");
    }

    public function cleanup(string $uuid): void
    {
        File::deleteDirectory($this->workdirPath($uuid));
    }

    private function workdirPath(string $uuid): string
    {
        return rtrim(config('audit.workdir'), '/').'/'.$uuid;
    }

    private function directorySizeMb(string $path): int
    {
        $result = Process::run(['du', '-sm', $path]);

        return (int) strtok(trim($result->output()), "\t ");
    }

    private function redactUrl(string $url): string
    {
        return preg_replace('#//[^/@]+@#', '//', $url) ?? $url;
    }
}
