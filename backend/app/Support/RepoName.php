<?php

namespace App\Support;

/**
 * Turns a repository URL into the `owner/repo` form people actually say out
 * loud. Anything that does not look like a hosted repository URL is returned
 * as given, so callers can always print the result.
 */
final class RepoName
{
    public static function short(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $path = trim($path, '/');

        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path !== '' ? $path : $url;
    }
}
