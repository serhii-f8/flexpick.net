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
}
