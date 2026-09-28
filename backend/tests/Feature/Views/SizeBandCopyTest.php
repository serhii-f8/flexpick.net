<?php

namespace Tests\Feature\Views;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Models\Config;
use App\Services\ConfigService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class SizeBandCopyTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    protected function tearDown(): void
    {
        Config::where('key', 'audit.size_bands')->delete();

        parent::tearDown();
    }

    private function useDistinctiveBands(): void
    {
        // Values no other test uses, so a hit can only come from this setting.
        app(ConfigService::class)->set('audit.size_bands', json_encode([
            ['max_loc' => 83917, 'runs' => 1],
            ['max_loc' => 271829, 'runs' => 4],
        ]));
    }

    public function test_the_pricing_page_shows_the_configured_bands(): void
    {
        $this->useDistinctiveBands();

        $this->asReferredGuest()->get(route('pricing'))
            ->assertOk()
            ->assertSee('Up to 83,917 lines of code: 1 run')
            ->assertSee('83,918–271,829 lines of code: 4 runs')
            ->assertSee('Over 271,829 lines of code')
            ->assertDontSee('Up to 100,000 lines of code');
    }

    public function test_the_run_an_audit_page_shows_the_configured_bands(): void
    {
        $this->useDistinctiveBands();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('83,918–271,829 lines of code: 4 runs');
    }
}
