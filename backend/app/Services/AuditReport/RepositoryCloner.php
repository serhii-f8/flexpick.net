<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RepositoryCloner
{
    public function preflight(string $url, bool $useToken = true): void
    {
        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', '--exit-code', $useToken ? $this->authenticatedUrl($url) : $url, 'HEAD']);

        if (! $result->successful()) {
            throw new AuditNotAnalyzableException(
                'Repository is not publicly accessible: '.$this->redactUrl($url)
            );
        }
    }

    public function clone(string $url, string $uuid): string
    {
        $path = $this->workdirPath($uuid);
        File::ensureDirectoryExists(dirname($path));

        $result = Process::timeout(config('audit.clone_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'clone', '--depth', '1', '--no-tags', '--single-branch', $this->authenticatedUrl($url), $path]);

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

    private function authenticatedUrl(string $url): string
    {
        $token = config('audit.github_token');

        if (! $token || ! str_starts_with($url, 'https://github.com/')) {
            return $url;
        }

        return 'https://x-access-token:'.$token.'@'.substr($url, strlen('https://'));
    }
}
