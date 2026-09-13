<?php

namespace Tests\Feature\Listeners;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    public function test_a_completed_tier_order_runs_the_repository_at_that_tier(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $upgraded = AuditRequest::where('user_id', $user->id)
            ->where('tier', AuditTier::DEEP_AI->value)
            ->first();

        $this->assertNotNull($upgraded, 'A tier purchase must produce a run at the purchased tier.');
        $this->assertSame('https://github.com/acme/app', $upgraded->repo_url);

        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_cloned_run_keeps_the_source_diagnostics_branch(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'branch' => 'feature/foo',
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $upgraded = AuditRequest::where('user_id', $user->id)
            ->where('tier', AuditTier::DEEP_AI->value)
            ->firstOrFail();

        $this->assertSame('feature/foo', $upgraded->branch, 'A tier purchase must audit the same branch as the diagnostic it was upgraded from.');
    }

    public function test_the_original_diagnostic_run_is_left_intact(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        $diagnostic = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $this->assertSame(AuditTier::DIAGNOSTIC, $diagnostic->fresh()->tier);
    }

    public function test_an_unrelated_product_order_is_ignored(): void
    {
        Queue::fake();

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->completeOrderFor($user, 'some-other-product');

        // Not assertNothingPushed(): the Ordered event also has queued
        // listeners of its own (referral processing, order notification)
        // that push regardless of which product was purchased.
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    /**
     * The bug this guards against: a customer with zero prior audit
     * requests bought a tier product cold (via /pricing, not the dashboard
     * "Run an audit" flow), so there was no intent to match and no
     * diagnostic to clone. Before this fix, that silently logged an error
     * and delivered nothing -- the order was paid and the customer got
     * nothing for it, with no trace visible to them at all.
     */
    public function test_an_order_with_no_intent_or_prior_diagnostic_grants_a_purchased_credit_instead_of_failing_silently(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $tenant = $this->tenantFor($user);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count(), 'Nothing can run yet -- no repo was ever named.');
        $this->assertSame(
            1,
            app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI),
            'The purchase must still be worth something: a spendable credit for that tier.',
        );
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    public function test_the_purchased_run_is_not_charged_against_the_free_quota(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $upgraded = AuditRequest::where('user_id', $user->id)
            ->where('tier', AuditTier::DEEP_AI->value)
            ->firstOrFail();

        $this->assertFalse((bool) $upgraded->free_run);
    }

    public function test_a_purchased_run_is_prepaid_and_not_metered(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai', $tenant);
        app(HandleAuditTierOrder::class)->handle(new Ordered($order));

        $run = AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue($run->prepaid);
        $this->assertSame(AuditFunding::PURCHASE, $run->funding);
        $this->assertSame(0, app(AuditEntitlementService::class)
            ->runsUsedThisMonth($tenant, AuditTier::DEEP_AI));
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_intent_run_is_used_instead_of_cloning_a_diagnostic(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        // A diagnostic must exist so a wrongful clone via the fallback path
        // is actually possible — otherwise the "still only one deep_ai row"
        // assertion below would pass even if the intent guard were removed.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);
        $intended = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/intended',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        TenantParameter::create([
            'tenant_id' => $tenant->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => $intended->uuid,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai', $tenant);
        app(HandleAuditTierOrder::class)->handle(new Ordered($order));

        $intended->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $intended->status);
        $this->assertTrue($intended->prepaid);
        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->count());
        $this->assertNull(TenantParameter::where('tenant_id', $tenant->id)->where('name', HandleAuditTierOrder::INTENT_PARAM)->first());
    }

    /**
     * The stored intent uuid can miss even though the customer really did
     * start a dashboard checkout for this tier -- an overlapping checkout
     * overwrote the single per-user intent row, or the purge job removed the
     * stale awaiting_payment row it pointed at. Either way, the buyer's most
     * recent awaiting_payment request at the ordered tier is still the
     * request they meant to pay for, and it must be found and run rather
     * than silently falling through.
     */
    public function test_a_stale_intent_uuid_falls_back_to_the_latest_awaiting_payment_request_at_that_tier(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();
        $tenant = $this->tenantFor($user);

        // The intent parameter points at a uuid that no longer resolves to
        // any request -- e.g. the row it named was purged or overwritten.
        TenantParameter::create([
            'tenant_id' => $tenant->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => (string) Str::uuid(),
        ]);

        $target = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/fallback-target',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai', $tenant);
        app(HandleAuditTierOrder::class)->handle(new Ordered($order));

        $target->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $target->status);
        $this->assertTrue($target->prepaid);
        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->count());
        Queue::assertPushed(GenerateAuditReport::class);
    }

    /**
     * The bug this guards against: Alice belongs to workspaces A (her first)
     * and B, starts a Deep AI purchase for a repo from B's dashboard, and the
     * checkout puts the order on A anyway (ProductTenantPicker's default is
     * the FIRST orderable workspace). The intent row lives on B, so A's lookup
     * misses, and the fallback would clone A's latest diagnostic -- a run of
     * the wrong repository, charged to the wrong workspace -- while B's
     * awaiting_payment row sits until purged. The buyer's own awaiting_payment
     * request is still what they paid for, whichever workspace it sits on.
     */
    public function test_an_order_on_another_of_the_buyers_workspaces_still_runs_the_request_they_paid_for(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $alice = $this->createUser();
        $first = $this->tenantFor($alice);
        $second = $this->tenantFor($alice);
        // A diagnostic on the ordered workspace, so a wrongful clone via the
        // fallback path is actually possible.
        AuditRequest::factory()->create([
            'user_id' => $alice->id,
            'tenant_id' => $first->id,
            'email' => $alice->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/first-workspace-repo',
        ]);
        $intended = AuditRequest::factory()->create([
            'user_id' => $alice->id,
            'tenant_id' => $second->id,
            'email' => $alice->email,
            'repo_url' => 'https://github.com/acme/second-workspace-repo',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        TenantParameter::create([
            'tenant_id' => $second->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => $intended->uuid,
        ]);

        $order = $this->orderFor($alice, 'audit-deep-ai', $first);
        app(HandleAuditTierOrder::class)->handle(new Ordered($order));

        $intended->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $intended->status);
        $this->assertTrue($intended->prepaid);
        $this->assertSame($second->id, $intended->tenant_id, 'The request stays on the workspace it was asked for.');
        $this->assertSame(
            0,
            AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('repo_url', 'https://github.com/acme/first-workspace-repo')->count(),
            'The ordered workspace\'s diagnostic must not be cloned.',
        );
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($first, AuditTier::DEEP_AI));
        $this->assertNull(TenantParameter::where('tenant_id', $second->id)->where('name', HandleAuditTierOrder::INTENT_PARAM)->first());
        Queue::assertPushed(GenerateAuditReport::class, 1);
    }

    /**
     * The user_id fallback is the buyer's own request only: a teammate's
     * awaiting_payment row on the same workspace is not what this buyer
     * asked for, and must not be run on their card.
     */
    public function test_the_buyer_fallback_does_not_run_a_teammates_awaiting_payment_request_on_another_workspace(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $alice = $this->createUser();
        $bob = $this->createUser();
        $ordered = $this->tenantFor($alice);
        $other = $this->tenantFor($bob);
        $other->users()->attach($alice);
        $bobs = AuditRequest::factory()->create([
            'user_id' => $bob->id,
            'tenant_id' => $other->id,
            'email' => $bob->email,
            'repo_url' => 'https://github.com/acme/bobs-repo',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);

        $order = $this->orderFor($alice, 'audit-deep-ai', $ordered);
        app(HandleAuditTierOrder::class)->handle(new Ordered($order));

        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $bobs->fresh()->status);
        $this->assertSame(1, app(AuditEntitlementService::class)->purchasedCreditBalance($ordered, AuditTier::DEEP_AI));
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    public function test_the_purchased_run_is_owned_by_the_orders_workspace(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/example/owned',
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        // Scoped by repo_url, not just tier -- FeatureTest does not roll back
        // between tests, so an unscoped ->where('tier', ...)->first() would
        // pick up an earlier test's deep_ai row instead of this one's.
        $run = AuditRequest::where('tier', AuditTier::DEEP_AI->value)
            ->where('repo_url', 'https://github.com/example/owned')
            ->firstOrFail();
        $this->assertSame($tenant->id, $run->tenant_id);
    }

    public function test_a_teammates_diagnostic_is_a_valid_source_for_the_order(): void
    {
        Queue::fake();
        $alice = $this->createUser();
        $tenant = $this->tenantFor($alice);
        $bob = $this->createUser();
        $tenant->users()->attach($bob);
        AuditRequest::factory()->create([
            'user_id' => $alice->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/example/team',
        ]);

        $this->completeOrderFor($bob, 'audit-deep-ai', $tenant);

        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('repo_url', 'https://github.com/example/team')->count());
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create(['created_by' => $user->id]);
        $tenant->users()->attach($user);

        return $tenant;
    }

    private function orderFor(User $user, string $slug, ?Tenant $tenant = null): Order
    {
        $product = OneTimeProduct::where('slug', $slug)->firstOrFail();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => ($tenant ?? $this->tenantFor($user))->id,
        ]);

        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 24900,
            'price_per_unit_after_discount' => 24900,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    /**
     * Mirrors orderFor() but for products that aren't seeded by
     * AuditMonetizationSeeder (e.g. an unrelated product slug), and
     * dispatches the real Ordered event rather than calling the listener
     * directly — some assertions here depend on the event's other
     * registered listeners actually running.
     */
    private function completeOrderFor(User $user, string $slug, ?Tenant $tenant = null): void
    {
        $product = OneTimeProduct::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'description' => $slug, 'features' => []],
        );

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => ($tenant ?? $this->tenantFor($user))->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 100,
            'price_per_unit_after_discount' => 100,
            'discount_per_unit' => 0,
        ]);

        Ordered::dispatch($order);
    }
}
