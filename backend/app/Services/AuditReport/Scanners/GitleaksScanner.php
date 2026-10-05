<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Support\Utf8;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class GitleaksScanner implements Scanner
{
    /**
     * Default-ruleset ids that match a shape, not one provider's credential
     * format (and client ids, which are public). Every other rule id names a
     * provider and counts as a credential whatever file it sits in.
     */
    private const GENERIC_RULES = [
        'generic-api-key', 'jwt', 'jwt-base64', 'curl-auth-header', 'curl-auth-user',
        'kubernetes-secret-yaml', 'sidekiq-sensitive-url',
    ];

    private const FAMILIES = [
        'critical' => 'secrets.credential',
        'high' => 'secrets.possible-credential',
        'low' => 'secrets.likely-fixture',
    ];

    public function __construct(private SarifNormalizer $normalizer) {}

    public function name(): string
    {
        return 'gitleaks';
    }

    public function isAvailable(): bool
    {
        return is_executable((string) config('audit.scanners.gitleaks.bin'));
    }

    public function version(): string
    {
        return (string) config('audit.scanners.gitleaks.version');
    }

    public function scan(RepoContext $context): array
    {
        $report = tempnam(sys_get_temp_dir(), 'gitleaks-').'.sarif';

        try {
            Process::timeout((int) config('audit.scanners.gitleaks.timeout'))
                ->run([
                    (string) config('audit.scanners.gitleaks.bin'),
                    'dir', $context->path,
                    '--report-format', 'sarif',
                    '--report-path', $report,
                    // First of two guards on F5.2.6 — the normalizer is the second.
                    '--redact',
                    '--no-banner',
                    // Repo-supplied config must never steer the analyzer (spec §5.4).
                    '--config', config('audit.scanners.gitleaks.config'),
                ]);

            // Gitleaks exits non-zero when it finds leaks, so the exit code is
            // not an error signal here — a missing report file is.
            if (! file_exists($report)) {
                throw new RuntimeException('gitleaks produced no report');
            }

            return $this->normalize($this->decode($report), $context->path, $context->classifier);
        } finally {
            @unlink($report);
        }
    }

    /** @return list<Finding> */
    public function normalize(array $sarif, string $repoPath, ?PathClassifier $classifier = null): array
    {
        $classifier ??= new PathClassifier;

        $raw = $this->normalizer->normalize(
            $sarif,
            $this->name(),
            $repoPath,
            fn (): Severity => Severity::CRITICAL,
            fn (): string => self::FAMILIES['critical'],
            fn (): string => 'security_hygiene',
        );

        return array_map(function (Finding $finding) use ($classifier): Finding {
            // Repository attributes are ignored here: a repo must not be able to
            // mark its own leaked key "generated" and lower its severity.
            $severity = $this->severityFor($finding->ruleId, $classifier->classify($finding->path, ignoreRepoAttributes: true));

            return new Finding(
                tool: $finding->tool,
                ruleId: $finding->ruleId,
                ruleFamily: self::FAMILIES[$severity->value],
                severity: $severity,
                path: $finding->path,
                line: $finding->line,
                message: $finding->message,
                dimension: $finding->dimension,
            );
        }, $raw);
    }

    public function severityFor(string $ruleId, PathClass $class): Severity
    {
        $provider = ! in_array($ruleId, self::GENERIC_RULES, true) && ! str_ends_with($ruleId, '-client-id');

        return match ($class) {
            PathClass::Source => $provider ? Severity::CRITICAL : Severity::HIGH,
            PathClass::Generated, PathClass::Vendored, PathClass::Lockfile => $provider ? Severity::CRITICAL : Severity::LOW,
            PathClass::Test, PathClass::Docs, PathClass::Example => $provider ? Severity::HIGH : Severity::LOW,
        };
    }

    private function decode(string $path): array
    {
        $decoded = json_decode(Utf8::scrub((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('gitleaks report is not an object');
        }

        return $decoded;
    }
}
