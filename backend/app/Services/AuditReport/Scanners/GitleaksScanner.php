<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class GitleaksScanner implements Scanner
{
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

            return $this->normalize($this->decode($report));
        } finally {
            @unlink($report);
        }
    }

    /** @return list<\App\Services\AuditReport\Findings\Finding> */
    public function normalize(array $sarif): array
    {
        return $this->normalizer->normalize(
            $sarif,
            $this->name(),
            // Gitleaks emits no severity; every leak is critical (spec §5.6).
            fn (): Severity => Severity::CRITICAL,
            fn (): string => 'secrets.credential',
            fn (): string => 'security_hygiene',
        );
    }

    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('gitleaks report is not an object');
        }

        return $decoded;
    }
}
