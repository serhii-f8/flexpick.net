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
    public function test_seeds_demo_users_with_expected_entitlements_idempotently(): void
    {
        $this->seed(AuditDemoSeeder::class);
        $this->seed(AuditDemoSeeder::class); // idempotent

        $entitlements = app(AuditEntitlementService::class);

        // Active tiers expose their monthly allowance through the tenant subscription
        foreach ([['audit-starter-demo@flexpick.net', 5], ['audit-growth-demo@flexpick.net', 20], ['audit-agency-demo@flexpick.net', 75], ['audit-trial-demo@flexpick.net', 5]] as [$email, $allowance]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame($allowance, $entitlements->allowance($user->tenants()->firstOrFail(), AuditTier::AUTOMATED), $email);
            $this->assertSame(1, $user->subscriptions()->count(), $email);
        }

        // Cancelled and expired subscriptions grant no allowance
        foreach (['audit-cancelled-demo@flexpick.net', 'audit-expired-demo@flexpick.net'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame(0, $entitlements->allowance($user->tenants()->firstOrFail(), AuditTier::AUTOMATED), $email);
        }

        // Free-quota states
        $this->assertTrue($entitlements->hasFreeRun('audit-free-demo@flexpick.net'));
        $this->assertFalse($entitlements->hasFreeRun('audit-exhausted-demo@flexpick.net'));
        $this->assertSame(3, AuditRequest::where('email', 'audit-exhausted-demo@flexpick.net')->where('free_run', true)->count());

        // Idempotency: no duplicate users or subscriptions
        $this->assertSame(1, User::where('email', 'audit-starter-demo@flexpick.net')->count());
        $this->assertSame(1, Subscription::whereHas('user', fn ($q) => $q->where('email', 'audit-cancelled-demo@flexpick.net'))->count());

        // Completed demo audits carry a report
        $sent = AuditRequest::where('email', 'audit-starter-demo@flexpick.net')->where('status', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertNotNull($sent->report);
    }
}
