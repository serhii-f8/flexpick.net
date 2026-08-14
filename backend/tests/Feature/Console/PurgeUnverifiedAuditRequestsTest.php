<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
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
            'created_at' => now()->subDays($days + 1),
        ]);
        $recent = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('app:purge-unverified-audit-requests')->assertSuccessful();

        $this->assertNull(AuditRequest::find($stale->id));
        $this->assertNotNull(AuditRequest::find($recent->id));
    }
}
