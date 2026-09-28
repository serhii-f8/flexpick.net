<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Exceptions\AuditAwaitingCreditException;
use App\Models\AuditRequest;
use App\Models\Config;
use App\Models\Tenant;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditRunSizer;
use App\Services\ConfigService;
use Tests\Feature\FeatureTest;

class AuditRunSizerTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ConfigService::class)->set('audit.size_bands', json_encode([
            ['max_loc' => 100000, 'runs' => 1],
            ['max_loc' => 300000, 'runs' => 2],
        ]));
    }

    protected function tearDown(): void
    {
        Config::where('key', 'audit.size_bands')->delete();

        parent::tearDown();
    }

    public function test_a_one_run_repo_charges_nothing_extra(): void
    {
        $request = $this->request($this->createTenant(), AuditFunding::PURCHASE);

        $this->assertSame(1, app(AuditRunSizer::class)->settle($request, 5000));
        $this->assertSame(1, $request->refresh()->run_count);
        $this->assertSame(0, $request->extra_purchased_runs);
    }

    public function test_a_covered_multi_run_repo_charges_the_difference(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, 150000));

        $this->assertSame(2, $request->refresh()->run_count);
        $this->assertSame(1, $request->extra_purchased_runs);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_an_uncovered_multi_run_repo_throws_insufficient(): void
    {
        $request = $this->request($this->createTenant(), AuditFunding::PURCHASE);

        try {
            app(AuditRunSizer::class)->settle($request, 150000);
            $this->fail('Expected AuditAwaitingCreditException');
        } catch (AuditAwaitingCreditException $e) {
            $this->assertFalse($e->tooLarge);
            $this->assertStringContainsString('150,000 lines of code', $e->getMessage());
            $this->assertStringContainsString('2 runs', $e->getMessage());
        }

        $this->assertNull($request->refresh()->run_count);
    }

    public function test_above_the_top_band_throws_too_large_even_with_credit(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 50);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        try {
            app(AuditRunSizer::class)->settle($request, 412000);
            $this->fail('Expected AuditAwaitingCreditException');
        } catch (AuditAwaitingCreditException $e) {
            $this->assertTrue($e->tooLarge);
            $this->assertStringContainsString('300,000-line limit', $e->getMessage());
        }

        $this->assertSame(50, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_an_operator_provisioned_request_is_never_charged_extras(): void
    {
        $request = $this->request($this->createTenant(), null);

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, 150000));
        $this->assertSame(0, $request->refresh()->extra_purchased_runs);
    }

    public function test_settling_twice_charges_once(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 3);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        app(AuditRunSizer::class)->settle($request, 150000);
        // A retry sees a bigger branch; the settled count stands.
        $this->assertSame(2, app(AuditRunSizer::class)->settle($request->refresh(), 250000));

        $this->assertSame(2, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_retry_holding_a_stale_copy_never_charges_twice(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 3);
        $request = $this->request($tenant, AuditFunding::PURCHASE);
        // A queue retry handed its own copy, loaded before the first settle:
        // its in-memory run_count is still null.
        $retry = AuditRequest::find($request->id);

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, 150000));
        // The retry's clone grew past the ceiling; the settled count stands.
        $this->assertSame(2, app(AuditRunSizer::class)->settle($retry, 412000));

        $this->assertSame(2, $request->refresh()->run_count);
        $this->assertSame(1, $request->extra_purchased_runs);
        $this->assertSame(2, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    private function request(Tenant $tenant, ?AuditFunding $funding): AuditRequest
    {
        return AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => $funding?->value,
        ]);
    }
}
