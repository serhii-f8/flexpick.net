<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\TenantParameter;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsPurchaseTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_an_exhausted_paid_tier_creates_an_intent_and_redirects(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit')
            // The checkout is pinned to the workspace the intent was written
            // on -- see ProductCheckoutController::addToCart().
            ->assertRedirect(route('buy.product', ['productSlug' => 'audit-deep-ai', 'tenant' => $tenant->uuid]));

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertSame(AuditFunding::PURCHASE, $request->funding);
        $this->assertSame('https://github.com/acme/app', $request->repo_url);

        $this->assertSame($tenant->id, $request->tenant_id);
        $this->assertSame(
            $request->uuid,
            TenantParameter::where('tenant_id', $tenant->id)
                ->where('name', HandleAuditTierOrder::INTENT_PARAM)
                ->value('value'),
        );

        // Nothing runs until the order lands.
        Queue::assertNothingPushed();
    }

    /**
     * Spec §A.9: a purchased credit belongs to the workspace the order was
     * placed on, not to the buyer -- so any member can spend it. Alice's cold
     * purchase (no intent, no diagnostic to clone) grants the credit; Bob,
     * a teammate, launches at that tier and it funds his run.
     */
    public function test_a_purchased_credit_is_granted_to_the_orders_workspace_and_spent_by_another_member(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);
        [$alice, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 0);
        $bob = User::factory()->create();
        $tenant->users()->attach($bob);
        $entitlements = app(AuditEntitlementService::class);

        $this->completeOrderFor($alice, 'audit-deep-ai', $tenant->id);

        $this->assertSame(1, $entitlements->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Queue::assertNotPushed(GenerateAuditReport::class);

        $this->actAsTenantUser($bob, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/bobs-turn')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit')
            ->assertNoRedirect();

        $run = AuditRequest::where('repo_url', 'https://github.com/acme/bobs-turn')->firstOrFail();

        $this->assertSame($tenant->id, $run->tenant_id);
        $this->assertSame($bob->id, $run->user_id);
        $this->assertSame(AuditFunding::PURCHASE, $run->funding);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $run->status);
        $this->assertSame(0, $entitlements->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Queue::assertPushed(GenerateAuditReport::class, 1);
    }

    public function test_an_unpaid_intent_does_not_consume_quota(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        // Spend the single Deep AI credit, then try again.
        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/one')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/two')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        // The pending purchase must not push usage past the credit that was
        // actually spent.
        $this->assertSame(
            1,
            app(AuditEntitlementService::class)
                ->runsUsedThisMonth($tenant, AuditTier::DEEP_AI),
        );
    }

    private function completeOrderFor(User $user, string $slug, int $tenantId): void
    {
        $product = OneTimeProduct::where('slug', $slug)->firstOrFail();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 11900,
            'price_per_unit_after_discount' => 11900,
            'discount_per_unit' => 0,
        ]);

        Ordered::dispatch($order);
    }
}
