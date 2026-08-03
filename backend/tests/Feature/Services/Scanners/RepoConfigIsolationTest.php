<?php

namespace Tests\Feature\Services\Scanners;

use Tests\Feature\FeatureTest;

class RepoConfigIsolationTest extends FeatureTest
{
    public function test_gitleaks_is_invoked_with_an_explicit_config_path(): void
    {
        $config = (string) config('audit.scanners.gitleaks.config');

        $this->assertFileExists($config);
        $this->assertStringStartsWith(resource_path('scanners'), $config);
    }

    public function test_jscpd_is_invoked_with_an_explicit_config_path(): void
    {
        $config = (string) config('audit.scanners.jscpd.config');

        $this->assertFileExists($config);
        $this->assertStringStartsWith(resource_path('scanners'), $config);
    }

    public function test_jscpd_config_disables_repository_gitignore_handling(): void
    {
        $config = json_decode((string) file_get_contents((string) config('audit.scanners.jscpd.config')), true);

        $this->assertFalse($config['gitignore']);
    }

    public function test_scanner_invocations_never_reference_repository_local_config(): void
    {
        // A repository under audit has an obvious motive to suppress its own
        // findings. Every scanner must be pointed at our config, never the
        // clone's (spec §5.4).
        $sources = [
            file_get_contents(app_path('Services/AuditReport/Scanners/GitleaksScanner.php')),
            file_get_contents(app_path('Services/AuditReport/Scanners/JscpdScanner.php')),
            file_get_contents(app_path('Services/AuditReport/Scanners/SemgrepScanner.php')),
        ];

        foreach ($sources as $source) {
            $this->assertStringNotContainsString('$context->path.\'/.gitleaks', $source);
            $this->assertStringNotContainsString('$context->path.\'/.jscpd', $source);
            $this->assertStringNotContainsString('$context->path.\'/.semgrep', $source);
        }

        $this->assertStringContainsString('--no-git-ignore', $sources[2]);
    }
}
