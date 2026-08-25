<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;

class GitFactsCollector implements Collector
{
    public function name(): string
    {
        return 'git';
    }

    public function collect(RepoContext $context): array
    {
        $path = $context->path;

        $branch = Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $lastCommit = Process::path($path)->run(['git', 'log', '-1', '--format=%cI']);
        $authors = Process::path($path)->run(['git', 'log', '--format=%ae']);

        $emails = array_filter(explode("\n", trim(Utf8::scrub($authors->output()))));
        $byAuthor = $emails === [] ? [] : array_count_values($emails);

        return [
            'default_branch' => trim(Utf8::scrub($branch->output())) ?: null,
            'last_commit_at' => trim(Utf8::scrub($lastCommit->output())) ?: null,
            'commits_analyzed' => count($emails),
            'contributors' => count($byAuthor),
            'top_contributor_pct' => $emails === [] ? null : (int) round(max($byAuthor) / count($emails) * 100),
        ];
    }
}
