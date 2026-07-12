<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditVerifyReminderEmail;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class SendAuditVerificationRemindersTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function pendingRequest(array $attributes = []): AuditRequest
    {
        return AuditRequest::factory()->create(array_merge([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'email_verified_at' => null,
        ], $attributes));
    }

    public function test_reminds_day_old_unverified_requests_exactly_once(): void
    {
        $stale = $this->pendingRequest(['email' => 'remind-me@example.com', 'created_at' => now()->subHours(30)]);

        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();
        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();

        Mail::assertQueued(AuditVerifyReminderEmail::class, 1);
        $this->assertNotNull($stale->refresh()->meta['verification_reminder_sent_at'] ?? null);
    }

    public function test_ignores_fresh_verified_and_expired_requests(): void
    {
        $this->pendingRequest(['email' => 'too-fresh@example.com', 'created_at' => now()->subHours(2)]);
        $this->pendingRequest(['email' => 'too-old@example.com', 'created_at' => now()->subHours(72)]);
        AuditRequest::factory()->verified()->create(['email' => 'already-verified@example.com', 'created_at' => now()->subHours(30)]);

        $this->artisan('app:send-audit-verification-reminders')->assertSuccessful();

        Mail::assertNotQueued(AuditVerifyReminderEmail::class);
    }
}
