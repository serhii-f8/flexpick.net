<?php

namespace App\Listeners\Order;

use App\Constants\TenancyPermissionConstants;
use App\Events\Order\OrderedOffline;
use App\Mail\CashPayments\PartnerNewPendingOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PartnerNewOrder;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\Mail\RenderSafeMailer;
use App\Services\TenantPermissionService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * OrderedOffline fires on every path that puts a local Offline order into
 * PENDING — product checkout, a cash subscription starting, and a renewal
 * order — so one listener covers all three (design decision 8).
 */
class NotifyPartnerOfPendingCashOrder implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
        private TenantPermissionService $permissionService,
        private OrderApprovalService $approvalService,
    ) {}

    public function handle(OrderedOffline $event): void
    {
        /** @var Tenant|null $partnerTenant */
        $partnerTenant = $event->order->partnerTenant;

        if ($partnerTenant === null) {
            return; // A direct sale: an admin approves it, no partner to tell.
        }

        $recipients = $partnerTenant->users->filter(fn (User $user): bool => $this->permissionService->tenantUserHasPermissionTo(
            $partnerTenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
        ));

        $amountDue = $this->approvalService->amountDue($event->order);

        foreach ($recipients as $recipient) {
            /** @var User $recipient */
            $this->mailer->send(new PartnerNewPendingOrder($event->order, $amountDue), $recipient->email);
            $recipient->notify(new PartnerNewOrder($event->order, $partnerTenant, $amountDue));
        }
    }
}
