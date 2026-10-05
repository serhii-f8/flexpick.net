<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Findings\Normalizers\SarifNormalizer;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Paths\PathClass;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Services\AuditReport\Scanners\GitleaksScanner;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTest;

class GitleaksScannerTest extends FeatureTest
{
    /** The clone root the scanner was pointed at — SARIF URIs are absolute. */
    private const ROOT = '/var/www/html/storage/app/audit-workdirs/0199a1f2';

    private function sarif(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/gitleaks.sarif.json')),
            true,
        );
    }

    private function normalize(): array
    {
        return app(GitleaksScanner::class)->normalize($this->sarif(), self::ROOT);
    }

    public function test_normalizes_every_result(): void
    {
        $this->assertCount(2, $this->normalize());
    }

    public function test_a_provider_key_in_source_is_critical_and_a_generic_match_is_high(): void
    {
        [$aws, $generic] = $this->normalize();

        $this->assertSame(Severity::CRITICAL, $aws->severity);
        $this->assertSame('secrets.credential', $aws->ruleFamily);
        $this->assertSame(Severity::HIGH, $generic->severity);
        $this->assertSame('secrets.possible-credential', $generic->ruleFamily);
    }

    /** @return array<string, array{string, PathClass, Severity}> */
    public static function matrix(): array
    {
        return [
            'provider in source' => ['github-pat', PathClass::Source, Severity::CRITICAL],
            'generic in source' => ['generic-api-key', PathClass::Source, Severity::HIGH],
            'provider in generated' => ['stripe-access-token', PathClass::Generated, Severity::CRITICAL],
            'generic in vendored' => ['generic-api-key', PathClass::Vendored, Severity::LOW],
            'provider in test' => ['aws-access-token', PathClass::Test, Severity::HIGH],
            'generic in test' => ['generic-api-key', PathClass::Test, Severity::LOW],
            'jwt in docs' => ['jwt', PathClass::Docs, Severity::LOW],
            'generic in example' => ['generic-api-key', PathClass::Example, Severity::LOW],
            'client id is not provider-secret' => ['discord-client-id', PathClass::Source, Severity::HIGH],
            'unlisted provider in source' => ['cloudflare-api-key', PathClass::Source, Severity::CRITICAL],
            'unlisted provider in test' => ['telegram-bot-api-token', PathClass::Test, Severity::HIGH],
            'curl header is generic' => ['curl-auth-header', PathClass::Source, Severity::HIGH],
            'private key in source' => ['private-key', PathClass::Source, Severity::CRITICAL],
        ];
    }

    #[DataProvider('matrix')]
    public function test_severity_matrix(string $rule, PathClass $class, Severity $expected): void
    {
        $this->assertSame($expected, app(GitleaksScanner::class)->severityFor($rule, $class));
    }

    public function test_repo_attributes_cannot_lower_a_secrets_severity(): void
    {
        $classifier = new PathClassifier([], [['pattern' => 'config/**', 'attribute' => 'linguist-generated', 'set' => true]]);

        $aws = app(GitleaksScanner::class)->normalize($this->sarif(), self::ROOT, $classifier)[0];

        $this->assertSame(Severity::CRITICAL, $aws->severity);
    }

    public function test_the_real_binary_honours_our_allowlists(): void
    {
        if (! app(GitleaksScanner::class)->isAvailable()) {
            $this->markTestSkipped('gitleaks is not installed here.');
        }

        $root = sys_get_temp_dir().'/gitleaks-allow-'.bin2hex(random_bytes(4));
        mkdir($root.'/src', 0755, true);
        mkdir($root.'/proto/storybook-static', 0755, true);
        // Built at run time so no credential-shaped literal is ever committed.
        $value = substr(base64_encode(hash('sha256', 'flexpick-fixture', true)), 0, 32);
        $line = "api_key = \"{$value}\"\n";
        file_put_contents($root.'/src/real.ts', $line);
        file_put_contents($root.'/postxl-lock.json', $line);
        file_put_contents($root.'/proto/storybook-static/runtime.js', $line);
        file_put_contents($root.'/src/app.min.js', $line);
        file_put_contents($root.'/src/sample.ts', 'const token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c";'."\n");

        try {
            $context = new RepoContext(path: $root, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));
            $paths = array_map(fn ($f) => $f->path, app(GitleaksScanner::class)->scan($context));

            $this->assertSame(['src/real.ts'], $paths);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function test_carries_path_and_line(): void
    {
        $finding = $this->normalize()[0];

        $this->assertSame('config/services.php', $finding->path);
        $this->assertSame(17, $finding->line);
    }

    public function test_the_matched_secret_never_survives_normalization(): void
    {
        // The fixture's snippet contains these values. F5.2.6 is counts and
        // paths only — no field on Finding may carry them.
        $serialized = json_encode(array_map(fn ($f) => (array) $f, $this->normalize()));

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $serialized);
        $this->assertStringNotContainsString('sk_live_supersecret', $serialized);
    }

    public function test_tool_is_recorded(): void
    {
        $this->assertSame('gitleaks', $this->normalize()[0]->tool);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.gitleaks.bin', '/nonexistent/gitleaks');

        $this->assertFalse(app(GitleaksScanner::class)->isAvailable());
    }

    public function test_a_sarif_document_with_no_runs_yields_no_findings(): void
    {
        $this->assertSame([], app(SarifNormalizer::class)->normalize(
            ['version' => '2.1.0', 'runs' => []],
            'gitleaks',
            self::ROOT,
            fn () => Severity::CRITICAL,
            fn () => 'secrets.credential',
            fn () => 'security_hygiene',
        ));
    }
}
