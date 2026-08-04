<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\SecretFileFilter;
use Tests\Feature\FeatureTest;

class SecretFileFilterTest extends FeatureTest
{
    private function filter(): SecretFileFilter
    {
        return app(SecretFileFilter::class);
    }

    public function test_denylisted_basenames_are_excluded(): void
    {
        foreach (['.env', '.env.production', 'config/app.pem', 'keys/id_rsa', 'deploy/.netrc'] as $path) {
            $this->assertTrue($this->filter()->excludes($path, []), "{$path} should be excluded");
        }
    }

    public function test_ordinary_source_files_are_kept(): void
    {
        foreach (['app/Models/User.php', 'src/env.ts', 'resources/js/app.js'] as $path) {
            $this->assertFalse($this->filter()->excludes($path, []), "{$path} should be kept");
        }
    }

    public function test_gitleaks_flagged_paths_are_excluded(): void
    {
        $this->assertTrue(
            $this->filter()->excludes('app/Services/Legacy.php', ['app/Services/Legacy.php']),
        );
    }

    public function test_the_denylist_still_applies_when_gitleaks_contributed_nothing(): void
    {
        // The degradation case: Gitleaks is the enhancement, the denylist is
        // the guard that actually catches .env files.
        $this->assertTrue($this->filter()->excludes('.env', []));
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->assertTrue($this->filter()->excludes('certs/SERVER.PEM', []));
    }
}
