<?php

namespace App\Services\AuditReport\Paths;

/**
 * What kind of file a repository path is, so generated bundles, lockfile
 * checksums and test fixtures stop being scored as hand-written source.
 *
 * Built once per run by SccScanner (it knows which files scc detected as
 * generated or minified) and read from RepoContext by everything after.
 * First matching rule wins: lockfile, vendored, generated, example, test,
 * docs, source.
 *
 * `.gitattributes` linguist-generated / linguist-vendored is the one piece of
 * repository config honoured, and only for metrics: secret severity calls
 * classify() with $ignoreRepoAttributes so a repository cannot talk its own
 * leaked key down a severity (spec §5.4).
 */
final class PathClassifier
{
    private const LOCKFILES = [
        'composer.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml',
        'bun.lock', 'bun.lockb', 'Cargo.lock', 'Gemfile.lock', 'poetry.lock', 'go.sum',
    ];

    private const LOCKFILE_PATTERNS = ['*-lock.json', '*.lock.json', '*-lock.yaml', '*.lock.yaml', '*-lock.yml', '*.lock.yml'];

    private const VENDORED_SEGMENTS = ['vendor', 'node_modules', 'bower_components', 'third_party'];

    public const BUILD_OUTPUT_SEGMENTS = ['dist', 'build', 'out', '.next', 'coverage', 'storybook-static'];

    private const GENERATED_SEGMENTS = ['__generated__', 'generated'];

    private const GENERATED_BASENAMES = ['*.min.js', '*.min.css', '*.bundle.js', '*.map', '*.pb.go', '*_pb2.py', '*.g.dart'];

    private const EXAMPLE_BASENAMES = ['*.example', '*.sample', '*.dist', '.env.template', '*.example.*'];

    private const TEST_SEGMENTS = ['test', 'tests', 'spec', '__tests__', '__mocks__', 'fixtures', 'testdata', 'e2e'];

    /** Case-sensitive on purpose: `*Test.*` must not match `Latest.php`. */
    private const TEST_BASENAMES = ['*Test.*', '*.test.*', '*.spec.*', '*_test.*', 'test_*.py'];

    private const DOCS_EXTENSIONS = ['md', 'mdx', 'rst', 'adoc'];

    private const DOCS_TOP_SEGMENTS = ['docs', 'doc', '_devlog', '_concept', 'examples'];

    /** @var array<string, true> */
    private array $sccGenerated;

    /**
     * @param  list<string>  $sccGenerated  repo-relative paths scc flagged generated/minified
     * @param  list<array{pattern: string, attribute: string, set: bool}>  $attributes  in file order
     */
    public function __construct(array $sccGenerated = [], private array $attributes = [])
    {
        $this->sccGenerated = array_fill_keys($sccGenerated, true);
    }

    /** @param list<string> $sccGenerated */
    public static function forRepository(string $repoPath, array $sccGenerated = []): self
    {
        return new self($sccGenerated, self::readAttributes($repoPath.'/.gitattributes'));
    }

    public function classify(string $path, bool $ignoreRepoAttributes = false): PathClass
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $basename = basename($path);
        $segments = explode('/', $path);
        $directories = array_slice($segments, 0, -1);
        $attribute = $ignoreRepoAttributes ? null : $this->attributeFor($path);

        if (in_array($basename, self::LOCKFILES, true) || $this->matchesAny($basename, self::LOCKFILE_PATTERNS)) {
            return PathClass::Lockfile;
        }

        if (array_intersect($directories, self::VENDORED_SEGMENTS) !== [] || $attribute === 'linguist-vendored') {
            return PathClass::Vendored;
        }

        if (array_intersect($directories, [...self::BUILD_OUTPUT_SEGMENTS, ...self::GENERATED_SEGMENTS]) !== []
            || $this->matchesAny($basename, self::GENERATED_BASENAMES)
            || isset($this->sccGenerated[$path])
            || $attribute === 'linguist-generated') {
            return PathClass::Generated;
        }

        if ($this->matchesAny($basename, self::EXAMPLE_BASENAMES) || preg_match('/^\.env\.(example|sample)$/', $basename) === 1) {
            return PathClass::Example;
        }

        $lowerDirectories = array_map('strtolower', $directories);

        if (array_intersect($lowerDirectories, self::TEST_SEGMENTS) !== [] || $this->matchesAny($basename, self::TEST_BASENAMES, caseSensitive: true)) {
            return PathClass::Test;
        }

        if (in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), self::DOCS_EXTENSIONS, true)
            || (count($segments) > 1 && in_array($segments[0], self::DOCS_TOP_SEGMENTS, true))) {
            return PathClass::Docs;
        }

        return PathClass::Source;
    }

    public function buildOutputDirectory(string $path): ?string
    {
        $segments = explode('/', ltrim(str_replace('\\', '/', $path), '/'));

        foreach (array_slice($segments, 0, -1) as $i => $segment) {
            if (in_array($segment, self::BUILD_OUTPUT_SEGMENTS, true)) {
                return implode('/', array_slice($segments, 0, $i + 1));
            }
        }

        return null;
    }

    /** Last matching line wins, as in git. */
    private function attributeFor(string $path): ?string
    {
        $state = [];

        foreach ($this->attributes as $line) {
            if ($this->attributeMatches($line['pattern'], $path)) {
                $state[$line['attribute']] = $line['set'];
            }
        }

        foreach (['linguist-vendored', 'linguist-generated'] as $attribute) {
            if (($state[$attribute] ?? false) === true) {
                return $attribute;
            }
        }

        return null;
    }

    private function attributeMatches(string $pattern, string $path): bool
    {
        $pattern = ltrim($pattern, '/');

        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($path, substr($pattern, 0, -2));
        }

        if (str_contains($pattern, '/')) {
            return fnmatch($pattern, $path, FNM_PATHNAME);
        }

        return fnmatch($pattern, basename($path));
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $basename, array $patterns, bool $caseSensitive = false): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $basename, $caseSensitive ? 0 : FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{pattern: string, attribute: string, set: bool}> */
    private static function readAttributes(string $file): array
    {
        if (! is_file($file) || is_link($file)) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file, length: 65536)) ?: [] as $raw) {
            $parts = preg_split('/\s+/', trim($raw)) ?: [];

            if ($parts === [] || $parts[0] === '' || str_starts_with($parts[0], '#')) {
                continue;
            }

            $pattern = array_shift($parts);

            foreach ($parts as $token) {
                if (preg_match('/^(-?)(linguist-(?:generated|vendored))(?:=(true|false))?$/', $token, $m) === 1) {
                    $lines[] = [
                        'pattern' => $pattern,
                        'attribute' => $m[2],
                        'set' => $m[1] !== '-' && ($m[3] ?? 'true') !== 'false',
                    ];
                }
            }
        }

        return $lines;
    }
}
