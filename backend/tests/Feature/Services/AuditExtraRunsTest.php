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
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    private function request(Tenant $tenant, AuditFunding $funding, array $attributes = []): AuditRequest
    {
        return AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => $funding->value,
            ...$attributes,
        ]);
    }

    public function test_extras_are_metered_under_a_tenant_row_lock(): void
    {
        // Pins the sequence, not just the SQL: the tenants row lock must be
        // taken before the metered count is read, so two concurrent charges
        // cannot both see the same allowance.
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

    public function test_extras_are_refused_when_the_creation_month_is_exhausted_even_if_this_month_is_free(): void
    {
        // Created on the last day of October, the request's runs count
        // against October. Sized on November 1st, November's untouched
        // allowance must not pay for them.
        $this->travelTo(Carbon::parse('2026-10-31 23:30:00'));
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 2]);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);
        $this->request($tenant, AuditFunding::ALLOWANCE);

        $this->travelTo(Carbon::parse('2026-11-01 00:30:00'));

        $this->assertFalse($this->service->chargeExtraRuns($request, 1));
        $this->assertSame(0, $request->refresh()->extra_metered_runs);
    }

    public function test_extras_are_metered_against_the_creation_month_when_this_month_is_exhausted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 23:30:00'));
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 2]);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);

        $this->travelTo(Carbon::parse('2026-11-01 00:30:00'));
        $this->request($tenant, AuditFunding::ALLOWANCE);
        $this->request($tenant, AuditFunding::ALLOWANCE);

        $this->assertTrue($this->service->chargeExtraRuns($request, 1));

        $this->assertSame(1, $request->refresh()->extra_metered_runs);
        $this->assertSame(0, $request->extra_purchased_runs);
        $this->assertSame(2, $this->service->runsUsedInMonth($tenant, AuditTier::DEEP_AI, Carbon::parse('2026-10-15')));
        $this->assertSame(2, $this->service->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_scheduled_request_never_draws_extras_from_purchased_credits(): void
    {
        // app:run-scheduled-audits runs unattended and promises never to
        // auto-charge: a shortfall the allowance cannot cover closes the
        // request instead of spending a credit the customer bought.
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 1]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE, ['from_schedule' => true]);

        $this->assertFalse($this->service->chargeExtraRuns($request, 1));

        $this->assertSame(0, $request->refresh()->extra_metered_runs);
        $this->assertSame(0, $request->extra_purchased_runs);
        $this->assertSame(5, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_scheduled_request_with_partial_headroom_charges_nothing(): void
    {
        // One allowance slot left, two extras needed: the shortfall may not
        // come from purchased credit, and all-or-nothing means the one slot
        // that is available is not taken either.
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 2]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE, ['from_schedule' => true]);

        $this->assertFalse($this->service->chargeExtraRuns($request, 2));

        $this->assertSame(0, $request->refresh()->extra_metered_runs);
        $this->assertSame(0, $request->extra_purchased_runs);
        $this->assertSame(1, $this->service->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));
        $this->assertSame(5, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_scheduled_request_still_draws_extras_from_the_allowance(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 3]);
        $this->service->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE, ['from_schedule' => true]);

        $this->assertTrue($this->service->chargeExtraRuns($request, 2));

        $this->assertSame(2, $request->refresh()->extra_metered_runs);
        $this->assertSame(5, $this->service->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_consume_reads_the_quota_under_a_tenant_row_lock(): void
    {
        $tenant = $this->tenantWithAllowance(['audit_deep_ai_credits' => 5]);
        $quota = $this->service->quotaFor($tenant, AuditTier::DEEP_AI);

        $queries = [];
        $began = null;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        Event::listen(TransactionBeginning::class, function () use (&$queries, &$began): void {
            $began ??= count($queries);
        });

        $this->assertSame(AuditFunding::ALLOWANCE, $this->service->consume($tenant, AuditTier::DEEP_AI, $quota));

        $lockAt = null;
        $meterAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'for update') && preg_match('/from\s+`?tenants`?\b/', $sql) === 1) {
                $lockAt = $i;
            }
            if ($meterAt === null && str_contains($sql, 'audit_requests') && str_contains($sql, 'count(')) {
                $meterAt = $i;
            }
        }

        $this->assertNotNull($began, 'consume() opened no transaction.');
        $this->assertNotNull($lockAt, 'consume() took no lock on the tenants row.');
        $this->assertNotNull($meterAt);
        $this->assertLessThan($meterAt, $lockAt, 'The quota must only be read under the tenants row lock.');
        $this->assertSame(0, $lockAt - $began, 'The tenant lock must be the first statement of the transaction.');
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
