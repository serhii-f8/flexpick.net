<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\DependencyAuditor;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;

/**
 * Adapts the existing DependencyAuditor to the Scanner interface so dependency
 * vulnerabilities flow into the same findings model as everything else.
 *
 * An unreachable OSV endpoint, or declared dependencies with no readable
 * lockfile, is a ScannerSkipped — never a clean result. Its querybatch response
 * carries advisory ids only — no CVSS score, summary, or manifest field — so
 * normalize() derives the manifest from the package ecosystem and always
 * reports Medium severity until a future task adds a per-advisory detail
 * lookup.
 */
class OsvScanner implements Scanner
{
    public function __construct(private DependencyAuditor $auditor) {}

    public function name(): string
    {
        return 'osv';
    }

    /** No binary to provision — the auditor speaks HTTP. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function version(): string
    {
        return 'querybatch';
    }

    public function scan(RepoContext $context): array
    {
        $audit = $this->auditor->audit($context->path, $context->classifier);

        // Scanners stay stateless — anything the pipeline needs later goes on
        // the per-run context (Task 8), never on the instance.
        $context->record('packages_scanned', (int) ($audit['packages_scanned'] ?? 0));
        $context->record('vulnerable_count', (int) ($audit['vulnerable_count'] ?? 0));

        // Zero findings from a check that did not happen is not a clean bill.
        if (isset($audit['error'])) {
            throw new ScannerSkipped('osv_unreachable');
        }

        // A Go, Python, Rust or Ruby repository has nothing this auditor can read,
        // which is not the same as nothing to check.
        if (($audit['packages_scanned'] ?? 0) === 0 && ! ($audit['has_declared_dependencies'] ?? false) && $this->hasUnsupportedManifests($context)) {
            throw new ScannerSkipped('unsupported_ecosystem');
        }

        if (($audit['packages_scanned'] ?? 0) === 0 && ($audit['has_declared_dependencies'] ?? false)) {
            throw new ScannerSkipped(($audit['unscannable_lockfiles'] ?? []) !== [] ? 'lockfile_unreadable' : 'no_lockfile');
        }

        return $this->normalize($audit);
    }

    /** @return list<Finding> */
    public function normalize(array $audit): array
    {
        if (isset($audit['error'])) {
            return [];
        }

        $findings = [];

        foreach ($audit['vulnerabilities'] ?? [] as $vulnerable) {
            $package = (string) ($vulnerable['package'] ?? 'unknown');
            $version = (string) ($vulnerable['version'] ?? '0.0.0');
            $manifest = (string) ($vulnerable['lockfile'] ?? $this->manifestFor((string) ($vulnerable['ecosystem'] ?? '')));

            foreach ($vulnerable['vulns'] ?? [] as $id) {
                $id = (string) $id;

                $findings[] = new Finding(
                    tool: $this->name(),
                    ruleId: 'osv.'.$id,
                    ruleFamily: 'dependencies.vulnerable',
                    // No CVSS score is available from the querybatch response.
                    severity: Severity::MEDIUM,
                    path: $manifest,
                    line: null,
                    message: "{$package} {$version} is affected by {$id}.",
                    dimension: 'dependencies',
                );
            }
        }

        return $findings;
    }

    private function manifestFor(string $ecosystem): string
    {
        return match ($ecosystem) {
            'npm' => 'package-lock.json',
            default => 'composer.lock',
        };
    }

    private const UNSUPPORTED_MANIFESTS = [
        'go.mod', 'go.sum', 'Cargo.toml', 'Cargo.lock', 'pyproject.toml', 'poetry.lock', 'Pipfile', 'Pipfile.lock',
        'Gemfile', 'Gemfile.lock', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'mix.exs',
    ];

    private function hasUnsupportedManifests(RepoContext $context): bool
    {
        foreach ($context->inventory?->allPaths() ?? [] as $path) {
            $name = basename($path);

            if (in_array($name, self::UNSUPPORTED_MANIFESTS, true) || preg_match('/^requirements.*\.txt$/', $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
