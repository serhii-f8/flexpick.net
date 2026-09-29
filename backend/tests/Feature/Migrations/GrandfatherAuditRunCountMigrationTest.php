<?php

namespace Tests\Feature\Migrations;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditRunSizer;
use App\Services\AuditReport\AuditSize;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class GrandfatherAuditRunCountMigrationTest extends FeatureTest
{
    private const MIGRATION = 'migrations/2026_09_28_000003_grandfather_audit_requests_at_one_run.php';

    public function test_up_sets_null_run_count_to_one_and_leaves_sized_rows_alone(): void
    {
        // The UPDATE is unscoped by design, so run it inside a transaction that
        // is rolled back: other tests' NULL rows are never touched.
        DB::beginTransaction();

        try {
            $legacy = AuditRequest::factory()->create(['run_count' => null, 'status' => 'delivered']);
            $queued = AuditRequest::factory()->create(['run_count' => null, 'status' => 'pending']);
            $single = AuditRequest::factory()->create(['run_count' => 1]);
            $multi = AuditRequest::factory()->create(['run_count' => 3, 'extra_purchased_runs' => 2]);

            (require database_path(self::MIGRATION))->up();

            $this->assertSame(1, $legacy->fresh()->run_count);
            $this->assertSame(1, $queued->fresh()->run_count);
            $this->assertSame(1, $single->fresh()->run_count);
            $this->assertSame(3, $multi->fresh()->run_count);
            $this->assertSame(2, $multi->fresh()->extra_purchased_runs);
        } finally {
            DB::rollBack();
        }
    }

    public function test_settle_on_a_grandfathered_row_charges_nothing_and_returns_one(): void
    {
        DB::beginTransaction();

        try {
            $tenant = $this->createTenant();
            $request = AuditRequest::factory()->create([
                'tenant_id' => $tenant->id,
                'tier' => AuditTier::DEEP_AI->value,
                'funding' => AuditFunding::PURCHASE->value,
                'run_count' => null,
            ]);

            (require database_path(self::MIGRATION))->up();

            // A repo far above any band: a NULL row would be sized or closed.
            $this->assertSame(1, app(AuditRunSizer::class)->settle($request->fresh(), AuditSize::measured(5_000_000)));

            $fresh = $request->fresh();
            $this->assertSame(1, $fresh->run_count);
            $this->assertSame(0, $fresh->extra_metered_runs);
            $this->assertSame(0, $fresh->extra_purchased_runs);
        } finally {
            DB::rollBack();
        }
    }
}
