<?php

namespace Database\Seeders\Demo;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * One subscribed demo account for live walkthroughs: Partner (Unlimited) --
 * billed manually outside the system, so no tier shows a monthly cap or a
 * per-run purchase prompt -- with a full month of unused allowance, plus a
 * single already-finished report so there's something to open immediately.
 * The old roster of edge-case accounts (trial/cancelled/expired/exhausted)
 * was a QA fixture, not something to hand to an investor.
 */
class AuditDemoSeeder extends Seeder
{
    public const EMAIL = 'demo@flexpick.net';

    private const PLAN_SLUG = 'audit-partner-monthly';

    public function __construct(
        private TenantPermissionService $tenantPermissionService,
    ) {}

    public function run(): void
    {
        $this->call(AuditMonetizationSeeder::class); // catalog must exist (idempotent)

        $user = User::where('email', self::EMAIL)->first()
            ?? User::factory()->create([
                'email' => self::EMAIL,
                'name' => 'Demo User',
                'password' => bcrypt(config('demo.user_password')),
            ]);

        $tenant = $this->tenantFor($user);

        $this->subscribeToPartnerPlan($user, $tenant);
        $this->resetToOneFinishedReport($user);
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = $user->tenants()->first();

        if ($tenant === null) {
            $tenant = Tenant::factory()->create(['created_by' => $user->id]);
            $tenant->users()->attach($user);
            $this->tenantPermissionService->assignTenantUserRole($tenant, $user, TenancyPermissionConstants::ROLE_ADMIN);
        }

        return $tenant;
    }

    private function subscribeToPartnerPlan(User $user, Tenant $tenant): void
    {
        $plan = Plan::where('slug', self::PLAN_SLUG)->firstOrFail();
        $price = $plan->prices()->firstOrFail();
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();

        // A prior seed run (before this account moved to Partner) may have
        // left an active subscription on a different plan -- allowance()
        // sums every active subscription for the tenant, so a stale one left
        // active would double-count on top of Partner's allowance instead of
        // being replaced by it.
        $user->subscriptions()
            ->where('plan_id', '!=', $plan->id)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->update(['status' => SubscriptionStatus::CANCELED->value]);

        $subscription = $user->subscriptions()->where('plan_id', $plan->id)->first();

        $attributes = [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ];

        if ($subscription === null) {
            $user->subscriptions()->create($attributes + [
                'uuid' => Str::uuid(),
                'plan_id' => $plan->id,
                'price' => $price->price,
                'currency_id' => $price->currency_id,
                'payment_provider_id' => $stripe->id,
                'interval_id' => $plan->interval_id,
                'interval_count' => $plan->interval_count,
                'tenant_id' => $tenant->id,
            ]);
        } else {
            $subscription->update($attributes);
        }
    }

    /**
     * "Reset the limits": clear any previously-seeded requests so a re-seed
     * never leaves stray runs behind, then seed exactly one finished report
     * dated last month so it can't count against this calendar month's
     * allowance (AuditEntitlementService::runsUsedThisMonth() only counts
     * `created_at >= now()->startOfMonth()`) -- the demo account walks in
     * with a full, untouched monthly quota.
     */
    private function resetToOneFinishedReport(User $user): void
    {
        AuditRequest::where('user_id', $user->id)->delete();

        $request = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => 'https://github.com/flexpick-demo/showcase-repo',
            'message' => 'Demo report seeded for live walkthroughs.',
            'status' => AuditRequestStatus::SENT->value,
            'email_verified_at' => now(),
            'free_run' => false,
            'funding' => AuditFunding::ALLOWANCE->value,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'source' => 'dashboard',
            'user_id' => $user->id,
        ]);

        $request->forceFill(['created_at' => now()->subMonth()])->save();

        AuditReport::factory()->unlocked()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
        ]);
    }
}
