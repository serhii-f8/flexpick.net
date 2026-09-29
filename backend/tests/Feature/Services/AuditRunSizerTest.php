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
use App\Services\AuditReport\AuditSize;
use App\Services\ConfigService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditRunSizerTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

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

        $this->assertSame(1, app(AuditRunSizer::class)->settle($request, AuditSize::measured(5000)));
        $this->assertSame(1, $request->refresh()->run_count);
        $this->assertSame(0, $request->extra_purchased_runs);
    }

    public function test_a_covered_multi_run_repo_charges_the_difference(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000)));

        $this->assertSame(2, $request->refresh()->run_count);
        $this->assertSame(1, $request->extra_purchased_runs);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_an_uncovered_multi_run_repo_throws_insufficient(): void
    {
        $request = $this->request($this->createTenant(), AuditFunding::PURCHASE);

        try {
            app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000));
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
            app(AuditRunSizer::class)->settle($request, AuditSize::measured(412000));
            $this->fail('Expected AuditAwaitingCreditException');
        } catch (AuditAwaitingCreditException $e) {
            $this->assertTrue($e->tooLarge);
            $this->assertStringContainsString('300,000-line limit', $e->getMessage());
        }

        $this->assertSame(50, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_size_from_the_fallback_inventory_settles_at_one_run_and_charges_nothing(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 3);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $runs = app(AuditRunSizer::class)->settle($request, AuditSize::unavailable('scc unavailable; used a file walk'));

        $this->assertSame(1, $runs);
        $request->refresh();
        $this->assertSame(1, $request->run_count);
        $this->assertSame(0, $request->extra_purchased_runs);
        $this->assertSame(0, $request->extra_metered_runs);
        $this->assertSame(3, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));

        $entry = collect($request->pipeline_log)->firstWhere('step', 'sizing_skipped');
        $this->assertNotNull($entry, 'No sizing_skipped pipeline-log entry.');
        $this->assertStringContainsString('scc unavailable; used a file walk', $entry['message']);
    }

    public function test_the_too_large_ceiling_does_not_apply_to_an_unavailable_size(): void
    {
        $request = $this->request($this->createTenant(), AuditFunding::PURCHASE);

        $this->assertSame(1, app(AuditRunSizer::class)->settle($request, AuditSize::unavailable('scc failed')));
        $this->assertSame(1, $request->refresh()->run_count);
    }

    public function test_an_operator_provisioned_request_is_never_charged_extras(): void
    {
        $request = $this->request($this->createTenant(), null);

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000)));
        $this->assertSame(0, $request->refresh()->extra_purchased_runs);
    }

    public function test_settling_twice_charges_once(): void
    {
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 3);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000));
        // A retry sees a bigger branch; the settled count stands.
        $this->assertSame(2, app(AuditRunSizer::class)->settle($request->refresh(), AuditSize::measured(250000)));

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

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000)));
        // The retry's clone grew past the ceiling; the settled count stands.
        $this->assertSame(2, app(AuditRunSizer::class)->settle($retry, AuditSize::measured(412000)));

        $this->assertSame(2, $request->refresh()->run_count);
        $this->assertSame(1, $request->extra_purchased_runs);
        $this->assertSame(2, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_the_size_bands_are_read_before_the_transaction_opens(): void
    {
        // Under REPEATABLE READ the first plain SELECT in a transaction fixes
        // its snapshot. A configs read inside settle()'s transaction would
        // fix it before the tenant lock, and the metered counts read after
        // that lock would then miss runs a concurrent sizing just committed.
        $tenant = $this->createTenant();
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 1);
        $request = $this->request($tenant, AuditFunding::PURCHASE);

        $events = $this->recordConfigReadsAndTransactions(fn () => app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000)));

        $this->assertConfigsReadOnlyBeforeTheTransaction($events);
    }

    public function test_the_ceiling_is_read_before_the_transaction_opens(): void
    {
        $request = $this->request($this->createTenant(), AuditFunding::PURCHASE);

        $events = $this->recordConfigReadsAndTransactions(function () use ($request): void {
            try {
                app(AuditRunSizer::class)->settle($request, AuditSize::measured(412000));
                $this->fail('Expected AuditAwaitingCreditException');
            } catch (AuditAwaitingCreditException $e) {
                $this->assertTrue($e->tooLarge);
                $this->assertStringContainsString('300,000-line limit', $e->getMessage());
            }
        });

        $this->assertConfigsReadOnlyBeforeTheTransaction($events);
    }

    public function test_settle_takes_the_tenant_lock_before_any_plain_read(): void
    {
        // Under REPEATABLE READ the first plain SELECT fixes the snapshot, so
        // inside settle()'s transaction only locking reads may precede the
        // tenants FOR UPDATE -- a lazy $auditRequest->tenant load, or reading
        // usage first, would let two sizings measure the same headroom.
        [, $tenant] = $this->userWithAllowance(diagnostic: 0, deepAi: 3);
        app(AuditEntitlementService::class)->grantPurchasedCredit($tenant, AuditTier::DEEP_AI, 5);
        $request = $this->request($tenant, AuditFunding::ALLOWANCE);

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = strtolower($query->sql);
        });
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = 'begin';
        });

        $this->assertSame(2, app(AuditRunSizer::class)->settle($request, AuditSize::measured(150000)));
        $this->assertSame(1, $request->refresh()->extra_metered_runs);

        $begin = array_search('begin', $log, true);
        $lockAt = null;
        foreach ($log as $i => $sql) {
            if (str_contains($sql, 'for update') && preg_match('/from\s+`?tenants`?\b/', $sql) === 1) {
                $lockAt = $i;
                break;
            }
        }

        $this->assertNotFalse($begin, 'settle() opened no transaction.');
        $this->assertNotNull($lockAt, 'settle() took no lock on the tenants row.');
        $this->assertGreaterThan($begin, $lockAt);

        $between = array_slice($log, $begin + 1, $lockAt - $begin - 1);
        $plainReads = array_values(array_filter(
            $between,
            fn (string $sql): bool => str_starts_with(ltrim($sql), 'select') && ! str_contains($sql, 'for update'),
        ));

        $this->assertSame([], $plainReads, 'A plain read ran in the transaction before the tenant lock.');
    }

    /** @return list<string> 'configs' for each configs query, 'begin' for each transaction (or savepoint) opened */
    private function recordConfigReadsAndTransactions(callable $callback): array
    {
        // ConfigService::get() reads the table directly today; flushing the
        // cache keeps this honest should a cache layer ever sit in front.
        cache()->flush();

        $events = [];
        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if (preg_match('/from\s+`?configs`?/i', $query->sql) === 1) {
                $events[] = 'configs';
            }
        });
        Event::listen(TransactionBeginning::class, function () use (&$events): void {
            $events[] = 'begin';
        });

        $callback();

        return $events;
    }

    /** @param  list<string>  $events */
    private function assertConfigsReadOnlyBeforeTheTransaction(array $events): void
    {
        $firstBegin = array_search('begin', $events, true);
        $lastConfigs = array_search('configs', array_reverse($events, true), true);

        $this->assertNotFalse($firstBegin, 'settle() opened no transaction.');
        $this->assertNotFalse($lastConfigs, 'settle() never read the size bands.');
        $this->assertLessThan($firstBegin, $lastConfigs, 'configs was read inside the transaction: '.implode(', ', $events));
    }

    public function test_the_insufficient_message_drops_the_tier_clause_when_the_tier_is_null(): void
    {
        // audit_requests.tier is NOT NULL, so this shape only exists
        // in-memory; exercise the defensive fallback directly.
        $request = new AuditRequest;
        $request->setAttribute('funding', AuditFunding::PURCHASE->value);
        $request->setAttribute('tier', null);

        $method = new ReflectionMethod(AuditRunSizer::class, 'insufficientMessage');
        $message = $method->invokeArgs(app(AuditRunSizer::class), [$request, 2, 150000]);

        $this->assertStringContainsString('150,000 lines of code', $message);
        $this->assertStringContainsString('an audit takes 2 runs', $message);
        $this->assertStringNotContainsString('a  audit', $message);
        $this->assertStringNotContainsString(AuditTier::DEEP_AI->label(), $message);
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
