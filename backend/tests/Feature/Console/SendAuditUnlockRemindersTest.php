<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SendAuditUnlockReminders;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Mail\Audit\AuditUnlockReminder;
use App\Models\AuditReport;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class SendAuditUnlockRemindersTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function abandonedIntent(User $user, AuditReport $report, int $hoursAgo = 30): UserParameter
    {
        $intent = UserParameter::create([
            'user_id' => $user->id,
            'name' => HandleAuditUnlockOrder::INTENT_PARAM,
            'value' => $report->uuid,
        ]);
        $intent->timestamps = false;
        $intent->forceFill(['updated_at' => now()->subHours($hoursAgo)])->save();

        return $intent;
    }

    public function test_reminds_abandoned_unlock_exactly_once(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $this->abandonedIntent($user, $report);

        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();
        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();

        Mail::assertQueued(AuditUnlockReminder::class, 1);
        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'name' => SendAuditUnlockReminders::REMINDER_PARAM,
            'value' => $report->uuid,
        ]);
    }

    public function test_skips_fresh_intents_and_unlocked_reports(): void
    {
        $user = User::factory()->create();
        $fresh = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $this->abandonedIntent($user, $fresh, hoursAgo: 2);

        $other = User::factory()->create();
        $unlocked = AuditReport::factory()->create(['user_id' => $other->id, 'unlocked_at' => now()]);
        $staleIntent = $this->abandonedIntent($other, $unlocked);

        $this->artisan('app:send-audit-unlock-reminders')->assertSuccessful();

        Mail::assertNotQueued(AuditUnlockReminder::class);
        $this->assertDatabaseMissing('user_parameters', ['id' => $staleIntent->id]); // stale intent cleaned up
    }
}
