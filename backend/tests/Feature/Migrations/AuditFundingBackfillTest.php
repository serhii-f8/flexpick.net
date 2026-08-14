<?php

namespace Tests\Feature\Migrations;

use App\Constants\AuditFunding;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class AuditFundingBackfillTest extends FeatureTest
{
    public function test_funding_defaults_to_allowance(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertSame(AuditFunding::ALLOWANCE, $request->fresh()->funding);
    }

    public function test_funding_is_mass_assignable_and_cast(): void
    {
        $request = AuditRequest::factory()->create(['funding' => AuditFunding::PURCHASE->value]);

        $this->assertSame(AuditFunding::PURCHASE, $request->fresh()->funding);
    }

    public function test_backfill_covers_all_precedence_branches(): void
    {
        // Use a transaction with rollback to isolate this test from other tests'
        // rows and prevent the unscoped backfill() UPDATEs from corrupting
        // rows created by other tests in the same process.
        DB::beginTransaction();

        try {
            // Use a distinctive email prefix for test rows
            $testEmailPrefix = 'backfill-test-' . uniqid() . '-';

            // Sentinel value to prove backfill actually writes to these rows
            $sentinel = 'WRONG';

            // Case 1: prepaid=true → expect PURCHASE
            DB::table('audit_requests')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Test Case 1',
                'email' => $testEmailPrefix . 'case1@example.com',
                'status' => 'new',
                'prepaid' => true,
                'free_run' => false,
                'source' => 'web',
                'funding' => $sentinel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Case 2: prepaid=false, free_run=true → expect FREE
            DB::table('audit_requests')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Test Case 2',
                'email' => $testEmailPrefix . 'case2@example.com',
                'status' => 'new',
                'prepaid' => false,
                'free_run' => true,
                'source' => 'web',
                'funding' => $sentinel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Case 3: prepaid=false, free_run=false, source='dashboard' → backfill does NOT update this
            // Seed with sentinel to demonstrate that backfill() has only 3 UPDATE branches,
            // not 4. The "dashboard → allowance" mapping is handled by the column default,
            // not by the backfill UPDATE statements.
            DB::table('audit_requests')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Test Case 3',
                'email' => $testEmailPrefix . 'case3@example.com',
                'status' => 'new',
                'prepaid' => false,
                'free_run' => false,
                'source' => 'dashboard',
                'funding' => $sentinel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Case 4: prepaid=false, free_run=false, source='web' → expect FREE
            DB::table('audit_requests')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Test Case 4',
                'email' => $testEmailPrefix . 'case4@example.com',
                'status' => 'new',
                'prepaid' => false,
                'free_run' => false,
                'source' => 'web',
                'funding' => $sentinel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Load and run the migration's backfill method
            $migration = require database_path('migrations/2026_08_14_000001_add_funding_to_audit_requests_table.php');
            $migration->backfill();

            // Assert each case after backfill
            $case1 = DB::table('audit_requests')
                ->where('email', $testEmailPrefix . 'case1@example.com')
                ->first();
            $this->assertSame(AuditFunding::PURCHASE->value, $case1->funding, 'Case 1 (prepaid=true) should be updated to PURCHASE');

            $case2 = DB::table('audit_requests')
                ->where('email', $testEmailPrefix . 'case2@example.com')
                ->first();
            $this->assertSame(AuditFunding::FREE->value, $case2->funding, 'Case 2 (free_run=true) should be updated to FREE');

            $case3 = DB::table('audit_requests')
                ->where('email', $testEmailPrefix . 'case3@example.com')
                ->first();
            $this->assertSame($sentinel, $case3->funding, 'Case 3 (dashboard source) should keep sentinel - backfill has no UPDATE for it');

            $case4 = DB::table('audit_requests')
                ->where('email', $testEmailPrefix . 'case4@example.com')
                ->first();
            $this->assertSame(AuditFunding::FREE->value, $case4->funding, 'Case 4 (source!=dashboard) should be updated to FREE');

            // Also verify that a row inserted WITHOUT funding gets the schema default
            DB::table('audit_requests')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Test Case Default',
                'email' => $testEmailPrefix . 'case-default@example.com',
                'status' => 'new',
                'prepaid' => false,
                'free_run' => false,
                'source' => 'dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $caseDefault = DB::table('audit_requests')
                ->where('email', $testEmailPrefix . 'case-default@example.com')
                ->first();
            $this->assertSame(AuditFunding::ALLOWANCE->value, $caseDefault->funding, 'Row inserted without funding should get schema default ALLOWANCE');
        } finally {
            DB::rollBack();
        }
    }
}
