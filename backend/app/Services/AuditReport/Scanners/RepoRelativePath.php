<?php

namespace App\Services\AuditReport\Scanners;

/**
 * Normalizes a scanner-reported path to a repository-relative one.
 *
 * Every scanner is invoked with the absolute clone path, and three of the four
 * echo that prefix back: scc's `Location`, and the SARIF `artifactLocation.uri`
 * from Gitleaks and Semgrep, are all absolute. Only jscpd reports relative to
 * the directory it was pointed at.
 *
 * Repository-relative is the contract every consumer downstream assumes:
 * excerpt collection, hotspot LOC joins, risk-file selection and the deep
 * finding sanitizer all resolve a path by joining it back onto the clone root,
 * and finding grouping buckets by leading directory. An absolute path silently
 * satisfies none of them — hence one shared normalization rather than a
 * per-scanner `ltrim()`.
 */
final class RepoRelativePath
{
    /**
     * Returns '' when the path is not inside the repository.
     *
     * Callers skip those rather than keeping the absolute path: it would fail
     * every join downstream *and* leak the temp workdir into customer-facing
     * report output.
     */
    public static function from(string $repoPath, string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, 'file://')) {
            $path = rawurldecode(substr($path, 7));
        }

        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        if (! str_starts_with($path, '/')) {
            return $path;
        }

        // The separator is part of the prefix, so a sibling clone directory
        // ("…/{uuid}-old/x.php") is correctly read as outside this repository
        // rather than yielding "-old/x.php".
        $root = rtrim($repoPath, '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : '';
    }
}
