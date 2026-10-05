<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Where a repository's package roots are. A monorepo keeps its real
 * dependencies (and its lockfile) below the root, so reading only the root
 * manifest reported "no lockfile" and "no error monitoring" for repositories
 * that had both.
 *
 * Declared workspaces (npm/yarn/bun `workspaces`, pnpm-workspace.yaml,
 * composer path repositories) are unioned with a shallow scan, because a
 * repository can hold independent projects nobody declared. Nothing resolved
 * here may leave the clone: globs with `..` are dropped and every root is
 * realpath-checked, since workspace globs are repository-supplied input.
 */
class WorkspaceDiscovery
{
    public const NPM_LOCKFILES = ['bun.lock', 'bun.lockb', 'pnpm-lock.yaml', 'yarn.lock', 'npm-shrinkwrap.json', 'package-lock.json'];

    public const COMPOSER_LOCKFILES = ['composer.lock'];

    private const MANIFESTS = ['package.json', 'composer.json'];

    private const MAX_ROOTS = 50;

    private const SCAN_DEPTH = 3;

    /** @return list<string> */
    public function roots(string $repoPath, PathClassifier $classifier): array
    {
        $realRepo = realpath($repoPath);

        if ($realRepo === false) {
            return [];
        }

        $roots = [];
        $add = function (string $dir) use (&$roots, $realRepo, $classifier): void {
            $dir = trim($dir, '/');
            $absolute = $dir === '' ? $realRepo : $realRepo.'/'.$dir;
            $real = realpath($absolute);

            if ($real === false || ($real !== $realRepo && ! str_starts_with($real, $realRepo.'/')) || $real !== $absolute) {
                return;
            }

            if ($dir !== '' && in_array($classifier->classify($dir.'/package.json'), [PathClass::Vendored, PathClass::Generated], true)) {
                return;
            }

            foreach (self::MANIFESTS as $manifest) {
                if (is_file($absolute.'/'.$manifest) && ! is_link($absolute.'/'.$manifest)) {
                    $roots[$dir] = true;

                    return;
                }
            }
        };

        $add('');

        foreach ($this->declaredGlobs($realRepo) as $glob) {
            foreach ($this->expand($realRepo, $glob) as $dir) {
                $add($dir);
            }
        }

        $finder = (new Finder)->files()->in($realRepo)->name(self::MANIFESTS)
            ->depth('<= '.self::SCAN_DEPTH)->exclude(['node_modules', 'vendor', '.git'])->ignoreDotFiles(false);

        foreach ($finder as $file) {
            $dir = dirname(str_replace('\\', '/', $file->getRelativePathname()));
            $add($dir === '.' ? '' : $dir);
        }

        $roots = array_keys($roots);
        sort($roots, SORT_STRING);

        return array_slice($roots, 0, self::MAX_ROOTS);
    }

    public function lockfileFor(string $repoPath, string $dir, string $ecosystem): ?string
    {
        $names = $ecosystem === 'composer' ? self::COMPOSER_LOCKFILES : self::NPM_LOCKFILES;
        $dir = trim($dir, '/');

        while (true) {
            foreach ($names as $name) {
                $relative = ltrim($dir.'/'.$name, '/');

                if (is_file($repoPath.'/'.$relative) && ! is_link($repoPath.'/'.$relative)) {
                    return $relative;
                }
            }

            if ($dir === '') {
                return null;
            }

            $parent = dirname($dir);
            $dir = $parent === '.' ? '' : $parent;
        }
    }

    /** @return list<string> */
    private function declaredGlobs(string $repoPath): array
    {
        $globs = [];
        $package = $this->json($repoPath.'/package.json');
        $workspaces = $package['workspaces'] ?? [];
        $workspaces = is_array($workspaces) && array_is_list($workspaces) ? $workspaces : ($workspaces['packages'] ?? []);

        foreach ((array) $workspaces as $glob) {
            $globs[] = (string) $glob;
        }

        if (is_file($repoPath.'/pnpm-workspace.yaml')) {
            try {
                $pnpm = Yaml::parse(Utf8::scrub((string) file_get_contents($repoPath.'/pnpm-workspace.yaml')));

                foreach ((array) ($pnpm['packages'] ?? []) as $glob) {
                    $globs[] = (string) $glob;
                }
            } catch (Throwable) {
                // An unparsable workspace file falls back to the shallow scan.
            }
        }

        foreach ((array) ($this->json($repoPath.'/composer.json')['repositories'] ?? []) as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && isset($repository['url'])) {
                $globs[] = (string) $repository['url'];
            }
        }

        return array_values(array_filter(
            $globs,
            fn (string $glob): bool => $glob !== '' && ! str_starts_with($glob, '!') && ! str_contains($glob, '..') && ! str_starts_with($glob, '/'),
        ));
    }

    /** @return list<string> */
    private function expand(string $repoPath, string $glob): array
    {
        $glob = rtrim($glob, '/');

        if (str_ends_with($glob, '/**')) {
            $base = substr($glob, 0, -3);

            if (! is_dir($repoPath.'/'.$base)) {
                return [];
            }

            $dirs = [$base];

            foreach ((new Finder)->directories()->in($repoPath.'/'.$base)->depth('< 3')->exclude(['node_modules', 'vendor']) as $dir) {
                $dirs[] = $base.'/'.str_replace('\\', '/', $dir->getRelativePathname());
            }

            return $dirs;
        }

        return array_map(
            fn (string $absolute): string => substr($absolute, strlen($repoPath) + 1),
            glob($repoPath.'/'.$glob, GLOB_ONLYDIR) ?: [],
        );
    }

    /** @return array<string, mixed> */
    private function json(string $file): array
    {
        if (! is_file($file) || is_link($file)) {
            return [];
        }

        $data = json_decode(Utf8::scrub((string) file_get_contents($file)), true);

        return is_array($data) ? $data : [];
    }
}
