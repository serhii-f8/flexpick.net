<?php

namespace Tests\Feature\Seeders;

use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\Demo\AuditDemoSeeder;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class AuditDemoSeederTest extends FeatureTest
{
    public function test_seeds_one_demo_user_subscribed_to_the_partner_plan_with_a_full_quota_idempotently(): void
    {
        $this->seed(AuditDemoSeeder::class);
        $this->seed(AuditDemoSeeder::class); // idempotent

        $user = User::where('email', AuditDemoSeeder::EMAIL)->firstOrFail();
        $tenant = $user->tenants()->firstOrFail();

        $entitlements = app(AuditEntitlementService::class);

        // Partner is billed manually, outside the system -- every metered
        // tier gets its own monthly allowance, including Diagnostic (normally
        // the per-email lifetime free-run count, not a monthly allowance) and
        // Expert, which no other plan grants any allowance for.
        $this->assertSame(100, $entitlements->allowance($tenant, AuditTier::DIAGNOSTIC));
        $this->assertSame(50, $entitlements->allowance($tenant, AuditTier::DEEP_AI));
        $this->assertSame(10, $entitlements->allowance($tenant, AuditTier::EXPERT));

        $diagnosticQuota = $entitlements->quotaFor($user, $tenant, AuditTier::DIAGNOSTIC);
        $this->assertFalse($diagnosticQuota->isLifetime);
        $this->assertSame(100, $diagnosticQuota->limit);
        $this->assertSame(1, $user->subscriptions()->count());
        $this->assertSame(1, Subscription::whereHas('user', fn ($q) => $q->where('email', AuditDemoSeeder::EMAIL))->count());

        // "Reset the limits": the one seeded report is dated last month, so
        // this month's allowance starts fully unused.
        $this->assertSame(0, $entitlements->runsUsedThisMonth($user, AuditTier::DIAGNOSTIC));

        // Idempotency: no duplicate users or requests.
        $this->assertSame(1, User::where('email', AuditDemoSeeder::EMAIL)->count());
        $this->assertSame(1, AuditRequest::where('email', AuditDemoSeeder::EMAIL)->count());

        // There's a finished, unlocked report ready to open immediately.
        $sent = AuditRequest::where('email', AuditDemoSeeder::EMAIL)->where('status', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertNotNull($sent->report);
        $this->assertNotNull($sent->report->unlocked_at);
    }

    /**
     * An account seeded before this account moved from Growth to Partner
     * must not end up with two active subscriptions stacking allowances --
     * the stale one should be cancelled, not left active alongside Partner.
     */
    public function test_a_prior_active_subscription_on_a_different_plan_is_cancelled(): void
    {
        $this->seed(AuditDemoSeeder::class);
        $user = User::where('email', AuditDemoSeeder::EMAIL)->firstOrFail();
        $tenant = $user->tenants()->firstOrFail();

        $growthPlan = Plan::where('slug', 'audit-growth-monthly')->firstOrFail();
        $price = $growthPlan->prices()->firstOrFail();
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();

        $user->subscriptions()->create([
            'uuid' => (string) Str::uuid(),
            'plan_id' => $growthPlan->id,
            'price' => $price->price,
            'currency_id' => $price->currency_id,
            'payment_provider_id' => $stripe->id,
            'interval_id' => $growthPlan->interval_id,
            'interval_count' => $growthPlan->interval_count,
            'tenant_id' => $tenant->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
        ]);

        $this->seed(AuditDemoSeeder::class); // re-seed: must clean up the stray subscription

        $entitlements = app(AuditEntitlementService::class);
        $this->assertSame(100, $entitlements->allowance($tenant->fresh(), AuditTier::DIAGNOSTIC));
        $this->assertSame(
            SubscriptionStatus::CANCELED->value,
            $user->subscriptions()->where('plan_id', $growthPlan->id)->firstOrFail()->status,
        );
    }
}
