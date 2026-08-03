<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class ScannerConfigTest extends FeatureTest
{
    public function test_every_scanner_named_by_a_tier_is_resolvable(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach (AuditTier::cases() as $tier) {
            foreach ($resolver->for($tier)->scanners as $name) {
                $scanner = app('audit.scanner.'.$name);

                $this->assertSame($name, $scanner->name(), "Binding audit.scanner.{$name} resolved the wrong scanner.");
            }
        }
    }

    public function test_every_binary_backed_scanner_declares_a_pinned_version(): void
    {
        foreach (['scc', 'gitleaks', 'jscpd', 'semgrep'] as $name) {
            $version = config("audit.scanners.{$name}.version");

            $this->assertIsString($version);
            $this->assertNotSame('', $version, "Scanner [{$name}] has no pinned version; a silent upgrade would change findings.");
        }
    }

    public function test_every_binary_backed_scanner_declares_a_timeout(): void
    {
        foreach (['scc', 'gitleaks', 'jscpd', 'semgrep'] as $name) {
            $this->assertGreaterThan(0, (int) config("audit.scanners.{$name}.timeout"));
        }
    }

    public function test_semgrep_is_configured_against_the_in_house_rules_only(): void
    {
        $path = (string) config('audit.scanners.semgrep.rules_path');

        // A registry identifier here would fetch over the network and import
        // rules whose licence forbids commercial use (Q33).
        $this->assertDirectoryExists($path);
        $this->assertStringStartsWith(resource_path('semgrep'), $path);
    }
}
