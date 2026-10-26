<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class AuditSubscriptionEntitlementTest extends FeatureTest
{
    public function test_allowance_is_zero_without_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->assertSame(0, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DIAGNOSTIC));
        $this->assertSame(0, app(AuditEntitlementService::class)->remainingRuns($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_allowance_reads_product_metadata_of_active_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->assertSame(5, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_dashboard_runs_this_month_reduce_remaining(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => now()->subMonths(2),
        ]);
        // Guest-funnel run: free-funded, so it never touches plan quota.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'funding' => AuditFunding::FREE->value,
        ]);

        $service = app(AuditEntitlementService::class);
        $this->assertSame(2, $service->runsUsedThisPeriod($tenant, AuditTier::DIAGNOSTIC));
        $this->assertSame(3, $service->remainingRuns($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_subscription_allowance_meters_each_tier_against_its_own_credits(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_diagnostic_credits' => 5, 'audit_deep_ai_credits' => 2]);

        // Three diagnostic dashboard runs consume the diagnostic allowance.
        AuditRequest::factory()->count(3)->create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'source' => 'dashboard',
            'tier' => AuditTier::DIAGNOSTIC->value, 'created_at' => now(),
        ]);

        // A deep_ai run must NOT come out of the diagnostic allowance.
        AuditRequest::factory()->create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'source' => 'dashboard',
            'tier' => AuditTier::DEEP_AI->value, 'created_at' => now(),
        ]);

        $this->assertSame(2, app(AuditEntitlementService::class)->remainingRuns($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_deep_ai_credits_are_metered_separately(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_diagnostic_credits' => 5, 'audit_deep_ai_credits' => 2]);

        AuditRequest::factory()->create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'source' => 'dashboard',
            'tier' => AuditTier::DEEP_AI->value, 'created_at' => now(),
        ]);

        $service = app(AuditEntitlementService::class);

        $this->assertSame(2, $service->allowance($tenant, AuditTier::DEEP_AI));
        $this->assertSame(1, $service->remainingRuns($tenant, AuditTier::DEEP_AI));
        // A deep_ai run does not also consume a diagnostic run.
        $this->assertSame(5, $service->remainingRuns($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_runs_from_a_previous_month_do_not_count(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_diagnostic_credits' => 5, 'audit_deep_ai_credits' => 0]);

        AuditRequest::factory()->count(5)->create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'source' => 'dashboard',
            'tier' => AuditTier::DIAGNOSTIC->value, 'created_at' => now()->subMonth(),
        ]);

        $this->assertSame(5, app(AuditEntitlementService::class)->remainingRuns($tenant, AuditTier::DIAGNOSTIC));
    }

    public function test_a_plan_without_deep_ai_credits_grants_none(): void
    {
        [$user, $tenant] = $this->subscribedTenant(['audit_diagnostic_credits' => 5]);

        $this->assertSame(0, app(AuditEntitlementService::class)->remainingRuns($tenant, AuditTier::DEEP_AI));
    }

    /** @return array{0: User, 1: Tenant} */
    private function subscribedTenant(array $metadata): array
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, $metadata);

        return [$user, $tenant];
    }

    public function test_a_quarterly_plan_meters_its_allowance_per_billing_quarter(): void
    {
        // Fixtures before freezing time (see RunScheduledAuditsTest).
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        // Paid period: 2026-09-01 .. 2026-12-01.
        $subscription = $this->createActiveSubscriptionFor($tenant, $user, ['audit_expert_credits' => 1]);
        $subscription->update(['interval_count' => 3, 'ends_at' => Carbon::parse('2026-12-01 00:00:00')]);

        $run = fn (string $at) => AuditRequest::factory()->create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id,
            'tier' => AuditTier::EXPERT->value, 'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => Carbon::parse($at),
        ]);
        $run('2026-09-10 09:00:00');
        $run('2026-08-25 09:00:00'); // the previous quarter

        $service = app(AuditEntitlementService::class);
        $quota = $service->quotaFor($tenant, AuditTier::EXPERT);

        // A calendar month would have reset on October 1st.
        $this->assertSame(1, $service->runsUsedThisPeriod($tenant, AuditTier::EXPERT));
        $this->assertFalse($quota->hasRuns());
        $this->assertSame('2026-12-01', $quota->resetsAt->toDateString());
        $this->assertSame(
            ['2026-06-01', '2026-09-01'],
            array_map(fn ($date) => $date->toDateString(), $service->allowancePeriod($tenant, AuditTier::EXPERT, Carbon::parse('2026-08-25'))),
        );
        $this->assertSame(1, $service->runsUsedInPeriodOf($tenant, AuditTier::EXPERT, Carbon::parse('2026-08-25')));
        $this->assertSame(3, $service->scheduledRunsPerAllowancePeriod($tenant, AuditTier::EXPERT, 'monthly'));
        $this->assertSame(1, $service->scheduledRunsPerAllowancePeriod($tenant, AuditTier::EXPERT, 'quarterly'));

        $this->travelTo(Carbon::parse('2026-12-02 12:00:00'));
        $subscription->update(['ends_at' => Carbon::parse('2027-03-01 00:00:00')]);

        $this->assertTrue(app(AuditEntitlementService::class)->quotaFor($tenant, AuditTier::EXPERT)->hasRuns());
    }

    public function test_a_monthly_plan_still_resets_with_the_calendar_month(): void
    {
        // Fixtures before freezing time (see RunScheduledAuditsTest).
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 1]);

        $quota = app(AuditEntitlementService::class)->quotaFor($tenant, AuditTier::DIAGNOSTIC);

        $this->assertSame('2026-11-01', $quota->resetsAt->toDateString());
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }

    private function createActiveSubscriptionFor(Tenant $tenant, User $user, array $productMetadata): Subscription
    {
        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }
}
