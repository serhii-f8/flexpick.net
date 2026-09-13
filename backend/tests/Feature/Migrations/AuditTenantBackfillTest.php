<?php

namespace Tests\Feature\Migrations;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

/**
 * Re-runs the backfill migration's data step against seeded legacy rows.
 * The migration is idempotent (it only touches rows with a NULL tenant_id),
 * so calling it again on an already-migrated database is safe.
 */
class AuditTenantBackfillTest extends FeatureTest
{
    private const MIGRATION = 'database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php';

    private function runBackfill(): void
    {
        Artisan::call('migrate:refresh', ['--path' => self::MIGRATION, '--force' => true]);
    }

    public function test_a_row_is_assigned_to_the_tenant_its_user_created(): void
    {
        $user = User::factory()->create();
        $joined = Tenant::factory()->create();
        $joined->users()->attach($user);
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($created->id, $request->fresh()->tenant_id);
    }

    public function test_a_row_matched_by_email_falls_back_to_the_earliest_membership(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $second->id, 'user_id' => $user->id, 'created_at' => now()->subDay(), 'updated_at' => now()],
            ['tenant_id' => $first->id, 'user_id' => $user->id, 'created_at' => now()->subDays(2), 'updated_at' => now()],
        ]);
        $request = AuditRequest::factory()->create(['user_id' => null, 'email' => 'legacy@example.com', 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($first->id, $request->fresh()->tenant_id);
    }

    public function test_a_default_membership_beats_an_earlier_one(): void
    {
        $user = User::factory()->create();
        $earlier = Tenant::factory()->create();
        $default = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $earlier->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()],
            ['tenant_id' => $default->id, 'user_id' => $user->id, 'is_default' => true, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($default->id, $request->fresh()->tenant_id);
    }

    public function test_a_row_with_no_resolvable_tenant_stays_unclaimed(): void
    {
        $orphan = AuditRequest::factory()->create(['user_id' => null, 'email' => 'nobody@example.com', 'tenant_id' => null]);
        $tenantless = AuditRequest::factory()->create(['user_id' => User::factory()->create()->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertNull($orphan->fresh()->tenant_id);
        $this->assertNull($tenantless->fresh()->tenant_id);
    }

    public function test_an_already_claimed_row_is_not_rehomed(): void
    {
        $user = User::factory()->create();
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);
        $elsewhere = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => $elsewhere->id]);

        $this->runBackfill();

        $this->assertSame($elsewhere->id, $request->fresh()->tenant_id);
    }

    public function test_user_credits_and_bonus_are_summed_onto_the_tenant_and_removed_from_the_user(): void
    {
        $tenant = Tenant::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $tenant->users()->attach([$a->id, $b->id]);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_purchased_credits_deep_ai', 'value' => '2']);
        UserParameter::create(['user_id' => $b->id, 'name' => 'audit_purchased_credits_deep_ai', 'value' => '3']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_bonus_free_runs', 'value' => '1']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_tier_intent', 'value' => 'some-uuid']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'unrelated', 'value' => 'keep']);

        $this->runBackfill();

        $this->assertSame('5', TenantParameter::where('tenant_id', $tenant->id)->where('name', 'audit_purchased_credits_deep_ai')->value('value'));
        $this->assertSame('1', TenantParameter::where('tenant_id', $tenant->id)->where('name', 'audit_bonus_free_runs')->value('value'));
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_purchased_credits_deep_ai']);
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_bonus_free_runs']);
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_tier_intent']);
        $this->assertDatabaseHas('user_parameters', ['user_id' => $a->id, 'name' => 'unrelated']);
    }

    public function test_a_credit_whose_user_has_no_tenant_is_left_in_place(): void
    {
        $loner = User::factory()->create();
        UserParameter::create(['user_id' => $loner->id, 'name' => 'audit_purchased_credits_expert', 'value' => '1']);

        // Baseline rather than an absolute 0: FeatureTest migrates the DB once
        // per process (its "has run once" flag is a shared static, inherited
        // by every FeatureTest subclass), so earlier tests in this same class
        // -- and, in a full suite run, earlier feature tests altogether --
        // may have already left tenant_parameters rows behind. What this test
        // actually asserts is that the loner's own credit created none.
        $countBefore = TenantParameter::count();

        $this->runBackfill();

        $this->assertDatabaseHas('user_parameters', ['user_id' => $loner->id, 'name' => 'audit_purchased_credits_expert']);
        $this->assertSame($countBefore, TenantParameter::count());
    }
}
