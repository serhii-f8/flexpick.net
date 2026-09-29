<?php

namespace Tests\Feature\Services\Scanners;

use App\Constants\AuditTier;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class SccScannerTest extends FeatureTest
{
    /** The clone root scc was pointed at — it reports Location absolutely. */
    private const ROOT = '/var/www/html/storage/app/audit-workdirs/0199a1f2';

    private function inventory(): SccInventory
    {
        $raw = json_decode(
            (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/scc.json')),
            true,
        );

        return app(SccScanner::class)->toInventory($raw, self::ROOT);
    }

    public function test_produces_no_findings(): void
    {
        // scc measures; it does not find defects. Its output sizes the budgets
        // for every later stage (F5.12.2).
        $context = new RepoContext(
            path: base_path('tests/Feature/Services/Fixtures/Scanners'),
            tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
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

    public function test_the_real_scc_run_leaves_out_dependency_build_and_cache_directories_at_any_depth(): void
    {
        if (! app(SccScanner::class)->isAvailable()) {
            $this->markTestSkipped('scc is not installed here.');
        }

        $root = sys_get_temp_dir().'/scc-billable-'.bin2hex(random_bytes(4));

        // 1,000 code lines in every directory; only src and lib are real code.
        foreach (['src', 'lib', 'vendor/a', 'node_modules/b', 'dist', 'build', '.git', 'storage', '.next', 'coverage',
            'packages/x/node_modules/y', 'packages/x/vendor', 'packages/x/build'] as $dir) {
            mkdir("{$root}/{$dir}", 0777, true);
            file_put_contents("{$root}/{$dir}/f.js", implode("\n", array_map(fn (int $i): string => "x{$i} = {$i};", range(1, 1000)))."\n");
        }

        try {
            $context = new RepoContext(path: $root, tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC));
            app(SccScanner::class)->scan($context);

            $this->assertSame(2000, $context->inventory->billableCode);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    /** @param  callable(list<string>): ProcessResult|FakeProcessResult  $respond */
    private function scanWithFakedScc(callable $respond): RepoContext
    {
        Process::fake(fn ($process) => $respond((array) $process->command));

        $context = new RepoContext(
            path: self::ROOT,
            tier: app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
        );
        app(SccScanner::class)->scan($context);

        return $context;
    }

    private function sccOutput(array $command): string
    {
        $fixture = in_array('--by-file', $command, true) ? 'scc.json' : 'scc-sizing.json';

        return (string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/'.$fixture));
    }

    public function test_billable_code_is_scc_code_not_lines_and_excludes_generated_and_minified(): void
    {
        // The sizing fixture has 120,000 Lines but only 61,500 Code: blanks and
        // comments are not billable, and neither is anything scc flags generated
        // or minified.
        $context = $this->scanWithFakedScc(fn (array $command) => Process::result($this->sccOutput($command)));

        $this->assertSame(61500, $context->inventory->billableCode);
        Process::assertRan(fn ($process) => in_array('--no-gen', (array) $process->command, true)
            && in_array('--no-min-gen', (array) $process->command, true)
            && ! in_array('--by-file', (array) $process->command, true));
    }

    public function test_the_inventory_run_keeps_generated_files_so_other_metrics_do_not_change_meaning(): void
    {
        $this->scanWithFakedScc(fn (array $command) => Process::result($this->sccOutput($command)));

        Process::assertRan(fn ($process) => in_array('--by-file', (array) $process->command, true)
            && ! in_array('--no-gen', (array) $process->command, true)
            && ! in_array('--no-min-gen', (array) $process->command, true));
        $this->assertSame(690, app(SccScanner::class)->toInventory(
            json_decode((string) file_get_contents(base_path('tests/Feature/Services/Fixtures/Scanners/scc.json')), true),
            self::ROOT,
        )->totalLoc);
    }

    public function test_a_failed_sizing_run_leaves_billable_code_unknown(): void
    {
        $context = $this->scanWithFakedScc(fn (array $command) => in_array('--by-file', $command, true)
            ? Process::result($this->sccOutput($command))
            : Process::result('', 'boom', 1));

        $this->assertNotNull($context->inventory);
        $this->assertNull($context->inventory->billableCode);
    }

    public function test_the_fallback_inventory_has_no_billable_code(): void
    {
        $inventory = app(SccScanner::class)->fallbackInventory(base_path('app/Services/AuditReport'));

        $this->assertNull($inventory->billableCode);
    }
}
