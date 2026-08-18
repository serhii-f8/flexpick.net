<?php

namespace Tests\Feature\Seeders;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\Demo\AuditDemoSeeder;
use Tests\Feature\FeatureTest;

class AuditDemoSeederTest extends FeatureTest
{
    public function test_seeds_one_demo_user_subscribed_to_the_mid_range_plan_with_a_full_quota_idempotently(): void
    {
        $this->seed(AuditDemoSeeder::class);
        $this->seed(AuditDemoSeeder::class); // idempotent

        $user = User::where('email', AuditDemoSeeder::EMAIL)->firstOrFail();
        $tenant = $user->tenants()->firstOrFail();

        $entitlements = app(AuditEntitlementService::class);

        // Growth is the mid-range, "popular" plan (config/pricing.php).
        $this->assertSame(20, $entitlements->allowance($tenant, AuditTier::AUTOMATED));
        $this->assertSame(1, $user->subscriptions()->count());
        $this->assertSame(1, Subscription::whereHas('user', fn ($q) => $q->where('email', AuditDemoSeeder::EMAIL))->count());

        // "Reset the limits": the one seeded report is dated last month, so
        // this month's allowance starts fully unused.
        $this->assertSame(0, $entitlements->runsUsedThisMonth($user, AuditTier::AUTOMATED));

        // Idempotency: no duplicate users or requests.
        $this->assertSame(1, User::where('email', AuditDemoSeeder::EMAIL)->count());
        $this->assertSame(1, AuditRequest::where('email', AuditDemoSeeder::EMAIL)->count());

        // There's a finished, unlocked report ready to open immediately.
        $sent = AuditRequest::where('email', AuditDemoSeeder::EMAIL)->where('status', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertNotNull($sent->report);
        $this->assertNotNull($sent->report->unlocked_at);
    }
}
