<?php

namespace App\Listeners\Order;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\User;
use App\Models\UserParameter;

/**
 * A completed order for a tier product runs the customer's repository at the
 * purchased tier.
 *
 * It creates a NEW request rather than upgrading the diagnostic in place:
 * the diagnostic ran a reduced scanner set, so its stored metrics, groups, and
 * scores are not the paid tier's — and the customer keeps the original report
 * they were shown (spec §4.2).
 */
class HandleAuditTierOrder
{
    /** Written by the dashboard when a user buys a tier for a named repo. */
    public const INTENT_PARAM = 'audit_tier_intent';

    public function handle(Ordered $event): void
    {
        $order = $event->order;

        foreach ($this->orderedProductSlugs($order) as $slug) {
            $tierValue = config("pricing.tiers.{$slug}.tier");

            if ($tierValue === null) {
                continue;
            }

            // A dashboard purchase already named the repository and the tier,
            // so honour that request rather than cloning an old diagnostic.
            $intended = $this->intentRequestFor($order, $tierValue);

            if ($intended !== null) {
                $intended->update([
                    'prepaid' => true,
                    'funding' => AuditFunding::PURCHASE->value,
                    'status' => AuditRequestStatus::QUEUED->value,
                ]);
                GenerateAuditReport::dispatch($intended);

                continue;
            }

            $source = $this->sourceRequestFor($order);

            if ($source === null) {
                continue;
            }

            $run = AuditRequest::create([
                'name' => $source->name,
                'email' => $source->email,
                'repo_url' => $source->repo_url,
                'message' => $source->message,
                'user_id' => $source->user_id,
                'tier' => $tierValue,
                'source' => $source->source,
                'status' => AuditRequestStatus::QUEUED->value,
                // A purchased run never consumes the free quota or plan quota.
                'free_run' => false,
                'prepaid' => true,
                'funding' => AuditFunding::PURCHASE->value,
                'email_verified_at' => $source->email_verified_at,
                'marketing_consent' => $source->marketing_consent,
                'consented_at' => $source->consented_at,
            ]);

            GenerateAuditReport::dispatch($run);
        }
    }

    /** The dashboard-created request this order was started to pay for. */
    private function intentRequestFor(Order $order, string $tierValue): ?AuditRequest
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return null;
        }

        $request = AuditRequest::query()
            ->where('uuid', $intent->value)
            ->where('tier', $tierValue)
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->first();

        if ($request === null) {
            return null;
        }

        $intent->delete();

        return $request;
    }

    /** @return list<string> */
    private function orderedProductSlugs(Order $order): array
    {
        $productIds = $order->items()->pluck('one_time_product_id')->filter();

        return OneTimeProduct::query()->whereIn('id', $productIds)->pluck('slug')->all();
    }

    /**
     * The buyer's most recent diagnostic run, matched by the order's user —
     * linked by id, or submitted with their email before they registered
     * (AuditRequest::scopeForUser).
     */
    private function sourceRequestFor(Order $order): ?AuditRequest
    {
        $user = User::find($order->user_id);

        if ($user === null) {
            return null;
        }

        return AuditRequest::query()
            ->forUser($user)
            ->where('tier', AuditTier::DIAGNOSTIC->value)
            ->latest('id')
            ->first();
    }
}
