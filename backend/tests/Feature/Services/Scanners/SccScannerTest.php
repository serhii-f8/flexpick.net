<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class SccScannerTest extends FeatureTest
{
    private function inventory(): SccInventory
    {
        $raw = json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/scc.json')),
            true,
        );

        return app(SccScanner::class)->toInventory($raw);
    }

    public function test_produces_no_findings(): void
    {
        // scc measures; it does not find defects. Its output sizes the budgets
        // for every later stage (F5.12.2).
        $context = new RepoContext(
            path: base_path('tests/Feature/Services/Fixtures/Scanners'),
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );

        $this->assertSame([], app(SccScanner::class)->normalize([]));
        $this->assertNull($context->inventory);
    }

    public function test_inventory_lists_files_descending_by_lines(): void
    {
        $files = $this->inventory()->files;

        $this->assertSame('app/Http/Controllers/UserController.php', $files[0]['path']);
        $this->assertSame(420, $files[0]['loc']);
        $this->assertSame(48, $files[0]['complexity']);
        $this->assertSame('resources/js/app.js', $files[2]['path']);
    }

    public function test_inventory_aggregates_languages(): void
    {
        $languages = $this->inventory()->languages;

        $this->assertSame(['files' => 2, 'loc' => 600], $languages['PHP']);
        $this->assertSame(['files' => 1, 'loc' => 90], $languages['JavaScript']);
    }

    public function test_inventory_totals_lines_and_complexity(): void
    {
        $inventory = $this->inventory();

        $this->assertSame(690, $inventory->totalLoc);
        $this->assertSame(64, $inventory->totalComplexity);
    }

    public function test_paths_returns_a_capped_ordered_list(): void
    {
        $this->assertSame(
            ['app/Http/Controllers/UserController.php', 'app/Models/User.php'],
            $this->inventory()->paths(2),
        );
    }

    public function test_fallback_inventory_walks_the_tree_when_scc_is_unavailable(): void
    {
        // Spec §10: scc failing must not leave later stages without a basis.
        $inventory = app(SccScanner::class)->fallbackInventory(base_path('app/Services/AuditReport'));

        $this->assertNotEmpty($inventory->files);
        $this->assertGreaterThan(0, $inventory->totalLoc);
        $this->assertSame(0, $inventory->totalComplexity);
    }

    public function test_reports_unavailable_when_the_binary_is_missing(): void
    {
        config()->set('audit.scanners.scc.bin', '/nonexistent/scc');

        $this->assertFalse(app(SccScanner::class)->isAvailable());
    }
}
