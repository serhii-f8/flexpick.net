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
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class AuditExtraRunsTest extends FeatureTest
{
    private AuditEntitlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditEntitlementService::class);
    }

    public function test_extras_come_from_the_allowance_first_then_purchased_credits(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 2]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);

        // 2 allowance slots: this request holds one, so one is left.
        $this->assertTrue($this->service->chargeExtraRuns($request, 3));

        $request->refresh();
        $this->assertSame(1, $request->extra_metered_runs);
        $this->assertSame(2, $request->extra_purchased_runs);
        $this->assertSame(2, $this->service->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));
        $this->assertSame(3, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_purchase_funded_request_draws_extras_only_from_purchased_credits(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 9]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $this->assertTrue($this->service->chargeExtraRuns($request, 1));

        $this->assertSame(0, $request->refresh()->extra_metered_runs);
        $this->assertSame(1, $request->extra_purchased_runs);
        $this->assertSame(0, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_an_uncoverable_charge_changes_nothing(): void
    {
        $tenant = $this->createTenant();
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $this->assertFalse($this->service->chargeExtraRuns($request, 2));

        $this->assertSame(0, $request->refresh()->extra_purchased_runs);
        $this->assertSame(1, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_tenantless_request_cannot_cover_extras(): void
    {
        $request = AuditRequest::factory()->freeRun()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->assertFalse($this->service->chargeExtraRuns($request, 1));
        $this->assertTrue($this->service->chargeExtraRuns($request, 0));
    }

    public function test_free_quota_extras_are_metered_against_the_free_quota(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->freeRun()->create(['tenant_id' => $tenant->id, 'email' => 'extras-free@example.test', 'tier' => AuditTier::DIAGNOSTIC->value]);

        $this->assertTrue($this->service->chargeExtraRuns($request, 2));

        $this->assertSame(3, $this->service->freeRunsUsed($tenant));
        $this->assertSame(3, $this->service->freeRunsUsedForEmail('extras-free@example.test'));
        $this->assertFalse($this->service->hasFreeRun($tenant));
    }

    public function test_refunding_a_multi_run_allowance_request_frees_every_slot(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 3]);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);
        $this->service->chargeExtraRuns($request, 2);
        $this->assertSame(3, $this->service->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));

        $this->service->refund($request->refresh());

        $this->assertSame(0, $this->service->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));
    }

    public function test_refund_returns_purchased_extras_once(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 1]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 2);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);
        $this->service->chargeExtraRuns($request, 2);
        $this->assertSame(0, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));

        $this->assertTrue($this->service->refund($request->refresh()));
        $this->assertFalse($this->service->refund($request->refresh()));

        $this->assertSame(2, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_refunding_a_purchase_funded_multi_run_request_returns_every_credit(): void
    {
        $tenant = $this->createTenant();
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);
        $this->service->chargeExtraRuns($request, 1);

        $this->service->refund($request->refresh());

        // The primary credit plus the extra one.
        $this->assertSame(2, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    private function request(Tenant $tenant, AuditFunding $funding): AuditRequest
    {
        return AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => $funding->value,
        ]);
    }

    public function test_extras_are_metered_under_a_tenant_row_lock(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 5]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->assertTrue($this->service->chargeExtraRuns($request, 2));

        $lockAt = null;
        $meterAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null
                && str_contains($sql, 'for update')
                && preg_match('/from\s+`?tenants`?\b/', $sql) === 1) {
                $lockAt = $i;
            }
            if ($meterAt === null
                && str_contains($sql, 'audit_requests')
                && (str_contains($sql, 'count(') || str_contains($sql, 'sum('))) {
                $meterAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'chargeExtraRuns took no lock on the tenants row.');
        $this->assertNotNull($meterAt);
        $this->assertLessThan($meterAt, $lockAt, 'The metered audit_requests count must only be read under the tenants row lock.');
    }

    private function tenantWithAllowance(array $productMetadata): Tenant
    {
        $user = $this->createUser();
        $tenant = $this->createTenant();
        $tenant->users()->attach($user);

        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }
}
