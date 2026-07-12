<?php

namespace App\Listeners\Order;

use App\Events\Order\Ordered;
use App\Models\AuditReport;
use App\Models\OneTimeProduct;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditReportService;

class HandleAuditUnlockOrder
{
    public const INTENT_PARAM = 'audit_unlock_intent';

    public function __construct(
        private AuditReportService $reportService,
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

        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        $report = null;
        if ($intent !== null) {
            $report = AuditReport::query()->where('uuid', $intent->value)->first();
        }
        $report ??= AuditReport::query()
            ->where('user_id', $order->user_id)
            ->whereNull('unlocked_at')
            ->latest()
            ->first();

        if ($report !== null) {
            $this->reportService->unlock($report, $order);
        }

        $intent?->delete();
    }
}
