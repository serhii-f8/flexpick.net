<?php

namespace App\Services\AuditReport;

/**
 * Q17: files whose CONTENTS must never be sent to the model.
 *
 * Two independent sources, because they fail in different directions and
 * neither subsumes the other: Gitleaks is precise but conditional on having
 * run, and catches secrets hardcoded into ordinary source; the denylist is
 * unconditional and catches the .env and key files by name.
 *
 * Exclusion withholds CONTENT only. A Gitleaks hit still reaches the report as
 * a finding — Finding structurally cannot carry the matched value — so the
 * customer still learns they have a leaked credential and where.
 */
class SecretFileFilter
{
    /**
     * @param  string  $path  repository-relative
     * @param  list<string>  $secretPaths  paths Gitleaks flagged; [] when it did not run
     */
    public function excludes(string $path, array $secretPaths): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if (in_array($normalized, $secretPaths, true)) {
            return true;
        }

        $basename = basename($normalized);

        foreach ((array) config('audit.secret_files.denylist') as $pattern) {
            if (fnmatch((string) $pattern, $basename, FNM_CASEFOLD)
                || fnmatch((string) $pattern, $normalized, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
