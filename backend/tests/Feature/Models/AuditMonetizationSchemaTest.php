<?php

namespace Tests\Feature\Models;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Tests\Feature\FeatureTest;

class AuditMonetizationSchemaTest extends FeatureTest
{
    public function test_audit_request_verification_and_quota_columns(): void
    {
        $user = User::factory()->create();

        $request = AuditRequest::factory()->verified()->freeRun()->create([
            'marketing_consent' => true,
            'consented_at' => now(),
            'user_id' => $user->id,
        ]);

        $request->refresh();

        $this->assertNotNull($request->email_verified_at);
        $this->assertTrue($request->free_run);
        $this->assertTrue($request->marketing_consent);
        $this->assertNotNull($request->consented_at);
        $this->assertSame('web', $request->source);
        $this->assertTrue($request->user->is($user));
    }

    public function test_dashboard_source_factory_state(): void
    {
        $request = AuditRequest::factory()->dashboardSource()->create();

        $this->assertSame('dashboard', $request->refresh()->source);
    }

    public function test_audit_report_lock_columns(): void
    {
        $locked = AuditReport::factory()->locked()->create();
        $unlocked = AuditReport::factory()->unlocked()->create();

        $this->assertNull($locked->refresh()->unlocked_at);
        $this->assertNull($locked->pdf_path);
        $this->assertNotNull($unlocked->refresh()->unlocked_at);
    }

    public function test_new_statuses_exist(): void
    {
        $this->assertSame('pending_verification', AuditRequestStatus::PENDING_VERIFICATION->value);
        $this->assertSame('awaiting_access', AuditRequestStatus::AWAITING_ACCESS->value);
        $this->assertSame('awaiting_payment', AuditRequestStatus::AWAITING_PAYMENT->value);
    }
}
