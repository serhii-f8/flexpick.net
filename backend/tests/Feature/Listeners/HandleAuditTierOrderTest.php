<?php

namespace Tests\Feature\Listeners;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    /**
     * Mirrors HandleAuditUnlockOrderTest::unlockOrderFor() — an order for one
     * tier product, tied to a user whose email matches the diagnostic request
     * (AuditRequest::scopeForUser resolves either by user_id or by email).
     */
    private function completeOrderFor(string $slug, AuditRequest $request): void
    {
        $user = User::firstOrCreate(
            ['email' => $request->email],
            ['name' => $request->name ?? 'Buyer', 'password' => bcrypt('password')],
        );

        $product = OneTimeProduct::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'description' => $slug, 'features' => []],
        );

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
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

    public function test_a_completed_tier_order_runs_the_repository_at_that_tier(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'email' => 'buyer@example.com',
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $upgraded = AuditRequest::where('email', 'buyer@example.com')
            ->where('tier', AuditTier::AUTOMATED->value)
            ->first();

        $this->assertNotNull($upgraded, 'A tier purchase must produce a run at the purchased tier.');
        $this->assertSame('https://github.com/acme/app', $upgraded->repo_url);

        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_original_diagnostic_run_is_left_intact(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $this->assertSame(AuditTier::DIAGNOSTIC, $diagnostic->fresh()->tier);
    }

    public function test_a_deep_ai_order_produces_a_deep_ai_run(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-deep-ai', $diagnostic);

        $this->assertDatabaseHas('audit_requests', [
            'email' => 'buyer@example.com',
            'tier' => AuditTier::DEEP_AI->value,
        ]);
    }

    public function test_an_unrelated_product_order_is_ignored(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->completeOrderFor('some-other-product', $diagnostic);

        // Not assertNothingPushed(): the Ordered event also has queued
        // listeners of its own (referral processing, order notification)
        // that push regardless of which product was purchased.
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    public function test_the_purchased_run_is_not_charged_against_the_free_quota(): void
    {
        Queue::fake();

        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'email' => 'buyer@example.com',
        ]);

        $this->completeOrderFor('audit-automated', $diagnostic);

        $upgraded = AuditRequest::where('tier', AuditTier::AUTOMATED->value)->firstOrFail();

        $this->assertFalse((bool) $upgraded->free_run);
    }
}
