<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Services\GitProviders\GitRepoAccessResolver;
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
            ['ls-remote', '--exit-code', $url, 'HEAD'],
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
        // A job hard-killed at its timeout skips the pipeline's finally, leaving this
        // target behind; git clone would then refuse the retry.
        $this->cleanup($uuid);
        File::ensureDirectoryExists(dirname($path));

        $command = ['clone', '--depth', (string) config('audit.clone_depth'), '--no-tags', '--single-branch'];
        if ($branch !== null) {
            $command[] = '--branch';
            $command[] = $branch;
        }

        try {
            $result = $this->runGit(
                $url,
                $tenant,
                config('audit.clone_timeout'),
                [...$command, $url, $path],
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
                ['ls-remote', $url, $ref],
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
     * Run git against the plain repo URL, converting ANY throwable into an
     * AuditNotAnalyzableException whose message is built from the raw, redacted URL alone.
     *
     * The tenant's credential reaches git only through the process environment (an
     * http.<origin>/.extraHeader config entry), never argv, so it appears in no command
     * line, no /proc/<pid>/cmdline and no .git/config. Config-source hardening applies to
     * every call: no system config, no terminal prompt, no credential helper that could
     * store or supply credentials.
     *
     * A process timeout throws ProcessTimedOutException whose message is the full command
     * line. Left alone, an exception message could reach failure_reason, the pipeline log,
     * funnel events, Sentry and the audit timeline every workspace member sees. The caught
     * exception is deliberately neither used for the message nor chained as `previous`. The
     * same guard covers resolution failures (e.g. a DecryptException after an APP_KEY
     * rotation).
     *
     * @param  list<string>  $arguments  git arguments after the global options
     */
    private function runGit(string $url, ?Tenant $tenant, mixed $timeout, array $arguments, string $failurePrefix): ProcessResult
    {
        try {
            $credential = $this->accessResolver->resolveCredential($url, $tenant);

            return Process::timeout((int) $timeout)
                ->env([
                    'GIT_CONFIG_NOSYSTEM' => '1',
                    'GIT_TERMINAL_PROMPT' => '0',
                    ...($credential?->gitEnv() ?? []),
                ])
                ->run(['git', '-c', 'credential.helper=', ...$arguments]);
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
