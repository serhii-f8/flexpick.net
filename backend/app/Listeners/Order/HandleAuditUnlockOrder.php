<?php

namespace App\Listeners\Order;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;

class HandleAuditUnlockOrder
{
    public const INTENT_PARAM = 'audit_unlock_intent';

    public const RUN_INTENT_PARAM = 'audit_run_intent';

    public function __construct(
        private AuditReportService $reportService,
        private AuditFunnelRecorder $funnel,
    ) {}

    public function handle(Ordered $event): void
    {
        $order = $event->order;

        $productIds = $order->items()->pluck('one_time_product_id')->filter();
        $hasUnlockProduct = OneTimeProduct::query()
            ->whereIn('id', $productIds)
            ->where('slug', config('audit.unlock_product_slug'))
            ->exists();

        if (! $hasUnlockProduct || $order->user_id === null) {
            return;
        }

        $alreadyProcessed = AuditReport::query()->where('unlock_order_id', $order->id)->exists()
            || AuditRequest::query()->where('meta->paid_order_id', $order->id)->exists();
        if ($alreadyProcessed) {
            return;
        }

        if ($this->handleUnlockIntent($order) || $this->handleRunIntent($order)) {
            return;
        }

        $report = AuditReport::query()
            ->where('user_id', $order->user_id)
            ->whereNull('unlocked_at')
            ->latest()
            ->first();

        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }
    }

    private function handleUnlockIntent(Order $order): bool
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return false;
        }

        $report = AuditReport::query()->where('uuid', $intent->value)->first();
        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }
        $intent->delete();

        return true;
    }

    private function handleRunIntent(Order $order): bool
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::RUN_INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return false;
        }

        $auditRequest = AuditRequest::query()
            ->where('uuid', $intent->value)
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->first();

        if ($auditRequest === null) {
            $intent->delete();

            return false;
        }

        $auditRequest->update([
            'prepaid' => true,
            'funding' => AuditFunding::PURCHASE->value,
            'status' => AuditRequestStatus::QUEUED->value,
            'meta' => array_merge($auditRequest->meta ?? [], ['paid_order_id' => $order->id]),
        ]);
        GenerateAuditReport::dispatch($auditRequest);
        $this->funnel->record(AuditFunnelRecorder::STAGE_RUN_PURCHASED, $auditRequest);
        $intent->delete();

        return true;
    }
}
