<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class PurgeUnverifiedAuditRequestsTest extends FeatureTest
{
    public function test_purges_only_old_unverified_requests(): void
    {
        $oldUnverified = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'created_at' => now()->subDays(8),
        ]);
        $freshUnverified = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'created_at' => now()->subDays(2),
        ]);
        $oldVerified = AuditRequest::factory()->verified()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('app:purge-unverified-audit-requests')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['id' => $oldUnverified->id]);
        $this->assertDatabaseHas('audit_requests', ['id' => $freshUnverified->id]);
        $this->assertDatabaseHas('audit_requests', ['id' => $oldVerified->id]);
    }

    public function test_abandoned_checkouts_are_purged_after_the_window(): void
    {
        $days = (int) config('audit.unverified_purge_days');

        $stale = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
        ]);
        $recent = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
        ]);
        $lateTransition = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
        ]);

        // Force created_at/updated_at directly at the database layer so each
        // row's timestamps are exact and independent of one another. Eloquent
        // re-stamps both columns on insert (and would re-stamp updated_at on
        // any subsequent save()), so a plain factory ->create(['updated_at'
        // => ...]) can't be trusted to stick.
        DB::table('audit_requests')->where('id', $stale->id)->update([
            'created_at' => now()->subDays($days + 1),
            'updated_at' => now()->subDays($days + 1),
        ]);
        DB::table('audit_requests')->where('id', $recent->id)->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        DB::table('audit_requests')->where('id', $lateTransition->id)->update([
            // Created well outside the retention window...
            'created_at' => now()->subDays($days + 3),
            // ...but transitioned into awaiting_payment recently (email
            // verified late, checkout just started). Its purchase-run signed
            // link is still live, so this row must survive the sweep even
            // though it is "old" by created_at.
            'updated_at' => now()->subDay(),
        ]);

        // Confirm the forced timestamps actually persisted as intended
        // before trusting the purge assertions below.
        $this->assertTrue($stale->refresh()->updated_at->lt(now()->subDays($days)));
        $this->assertTrue($recent->refresh()->updated_at->gt(now()->subDays($days)));
        $this->assertTrue($lateTransition->refresh()->created_at->lt(now()->subDays($days)));
        $this->assertTrue($lateTransition->updated_at->gt(now()->subDays($days)));

        $this->artisan('app:purge-unverified-audit-requests')->assertSuccessful();

        $this->assertNull(AuditRequest::find($stale->id));
        $this->assertNotNull(AuditRequest::find($recent->id));
        $this->assertNotNull(AuditRequest::find($lateTransition->id));
    }
}
