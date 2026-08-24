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
use Illuminate\Support\Facades\Log;

/**
 * A completed order for a tier product runs the customer's repository at the
 * purchased tier.
 *
 * It creates a NEW request rather than upgrading the earlier run in place:
 * a higher tier adds a deep per-file review, so its stored metrics, groups,
 * and scores are not the earlier run's — and the customer keeps the original
 * report they were shown (spec §4.2).
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
                // The order is captured but nothing can be run: no intent
                // request (uuid match or tier/status fallback) and no prior
                // diagnostic to clone. Silent here means a paid order simply
                // delivers nothing with no trace, so make it loud instead.
                Log::error("HandleAuditTierOrder: paid order {$order->id} for tier product '{$slug}' matched no runnable audit request (no intent, no diagnostic to clone).");
                report(new \RuntimeException("Paid audit tier order #{$order->id} for '{$slug}' produced no runnable audit request."));

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

    /**
     * The dashboard-created request this order was started to pay for.
     *
     * The stored intent uuid can miss even though the customer did start a
     * dashboard checkout: an overlapping checkout overwrites the single
     * intent row per user, the purge job deletes stale awaiting_payment rows,
     * or a guest's row simply ages out mid-checkout. When the uuid lookup
     * misses, fall back to the buyer's most recent awaiting_payment request
     * at the ordered tier -- the dashboard purchase flow only ever leaves one
     * such row per tier, so it is still the request they meant to pay for.
     */
    private function intentRequestFor(Order $order, string $tierValue): ?AuditRequest
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        $request = null;

        if ($intent !== null) {
            $request = AuditRequest::query()
                ->where('uuid', $intent->value)
                ->where('tier', $tierValue)
                ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
                ->first();
        }

        $request ??= AuditRequest::query()
            ->where('user_id', $order->user_id)
            ->where('tier', $tierValue)
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->latest('id')
            ->first();

        if ($request === null) {
            return null;
        }

        // Only delete the intent row when a request was actually consumed --
        // a miss should not erase a marker that a later attempt might still
        // resolve.
        $intent?->delete();

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
