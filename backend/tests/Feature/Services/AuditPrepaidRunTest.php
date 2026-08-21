<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\ScoreCalculator;
use App\Services\AuditRequestService;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditPrepaidRunTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function unlockOrderFor(User $user): Order
    {
        $product = OneTimeProduct::firstOrCreate(
            ['slug' => config('audit.unlock_product_slug')],
            ['name' => 'Audit Report Unlock', 'description' => 'Unlock full audit report', 'features' => []],
        );
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 500,
            'price_per_unit_after_discount' => 500,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    /** Builds an order for a catalog tier product seeded by AuditMonetizationSeeder. */
    private function tierOrderFor(User $user, string $slug): Order
    {
        $product = OneTimeProduct::where('slug', $slug)->firstOrFail();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 500,
            'price_per_unit_after_discount' => 500,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    /**
     * The "run this audit now" link from the quota-exhausted email is the
     * standard next step for a first-time lead, so its checkout target has to
     * be a product that can actually be bought. It previously pointed at
     * config('audit.unlock_product_slug'), which the seeder retires
     * (is_active = false) — and checkout resolves active products only, so
     * every one of those links 404'd at the till.
     */
    public function test_purchase_run_link_checks_out_a_live_product_for_the_requested_tier(): void
    {
        $this->seed(AuditMonetizationSeeder::class);

        $request = AuditRequest::factory()->verified()->create([
            'email' => 'prepaid-run@example.com',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $response = $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));

        $user = User::where('email', 'prepaid-run@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        // The tier listener owns this intent now, not the retired unlock one.
        $this->assertSame($request->uuid, UserParameter::where('user_id', $user->id)
            ->where('name', HandleAuditTierOrder::INTENT_PARAM)->value('value'));

        $response->assertRedirect(route('buy.product', ['productSlug' => 'audit-diagnostic']));

        $slug = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $product = OneTimeProduct::where('slug', $slug)->first();

        $this->assertNotNull($product, "Checkout target [{$slug}] is not a product at all.");
        $this->assertTrue((bool) $product->is_active, "Checkout target [{$slug}] is a retired product and cannot be bought.");
    }

    /** The link's whole point: paying it runs this exact request. */
    public function test_paying_a_purchase_run_link_queues_that_exact_request(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $request = AuditRequest::factory()->verified()->create([
            'email' => 'prepaid-tier-run@example.com',
            'repo_url' => 'https://github.com/acme/paid-run',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));
        $user = User::where('email', 'prepaid-tier-run@example.com')->firstOrFail();

        Ordered::dispatch($this->tierOrderFor($user, 'audit-diagnostic'));

        $request->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertTrue($request->prepaid);
        $this->assertSame(AuditFunding::PURCHASE, $request->funding);
        // The intent was honoured rather than a second run being cloned.
        $this->assertSame(1, AuditRequest::where('email', 'prepaid-tier-run@example.com')->count());
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_paid_run_intent_queues_the_audit_as_prepaid(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $request = AuditRequest::factory()->verified()->create([
            'email' => $user->email,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM, 'value' => $request->uuid]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $request->refresh();
        $this->assertTrue($request->prepaid);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM]);
    }

    public function test_stale_run_intent_falls_back_to_unlocking_latest_locked_report(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $queuedRequest = AuditRequest::factory()->verified()->create([
            'email' => $user->email,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM, 'value' => $queuedRequest->uuid]);
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $this->assertNotNull($report->refresh()->unlocked_at);
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM]);
        $queuedRequest->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $queuedRequest->status);
        $this->assertFalse($queuedRequest->prepaid);
    }

    public function test_prepaid_request_report_is_born_unlocked_with_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->create(['prepaid' => true]);

        $report = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
    }

    public function test_redelivered_unlock_order_does_not_unlock_a_second_report(): void
    {
        $user = User::factory()->create();
        $reportA = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $reportB = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM, 'value' => $reportA->uuid]);

        $order = $this->unlockOrderFor($user);

        Ordered::dispatch($order);
        Ordered::dispatch($order);

        $this->assertNotNull($reportA->refresh()->unlocked_at);
        $this->assertNull($reportB->refresh()->unlocked_at);
    }

    public function test_redelivered_run_order_does_not_touch_other_reports(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $request = AuditRequest::factory()->verified()->create([
            'email' => $user->email,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM, 'value' => $request->uuid]);
        $otherReport = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        $order = $this->unlockOrderFor($user);

        Ordered::dispatch($order);
        Ordered::dispatch($order);

        $request->refresh();
        $this->assertTrue($request->prepaid);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertSame($order->id, $request->meta['paid_order_id'] ?? null);
        $this->assertNull($otherReport->refresh()->unlocked_at);
        Queue::assertPushed(GenerateAuditReport::class, 1);
    }

    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }
}
