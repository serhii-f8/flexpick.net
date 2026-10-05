<?php

namespace App\Services\AuditReport;

/** Customer-facing copy for a not-measured dimension. Codes come from ScoreSet::$notMeasuredReasons. */
final class NotMeasuredReason
{
    public static function describe(string $reason): string
    {
        return match ($reason) {
            'osv_unreachable' => __('The vulnerability database could not be reached during this run.'),
            'no_lockfile' => __('No lockfile was found, so installed dependency versions could not be checked.'),
            'lockfile_unreadable' => __('This repository\'s lockfile format (for example bun.lockb) can\'t be read, so dependency versions could not be checked.'),
            'unsupported_ecosystem' => __('This repository uses a package manager the dependency check does not read yet, so its dependencies were not checked.'),
            'timeout' => __('The scan was stopped because it ran too long on a repository this size.'),
            'unavailable', 'not_run' => __('The scanner this score depends on did not run for this report.'),
            default => __('The scanner did not complete, so this score is left out rather than guessed.'),
        };
    }
}
