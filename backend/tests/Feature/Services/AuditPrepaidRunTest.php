<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditRequestService;
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

    public function test_purchase_run_link_sets_run_intent_and_redirects_to_checkout(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'email' => 'prepaid-run@example.com',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);

        $response = $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));

        $user = User::where('email', 'prepaid-run@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($request->uuid, UserParameter::where('user_id', $user->id)
            ->where('name', HandleAuditUnlockOrder::RUN_INTENT_PARAM)->value('value'));
        $response->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));
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

        $report = app(AuditReportService::class)->create($request, $this->payload());

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
    }

    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }
}
