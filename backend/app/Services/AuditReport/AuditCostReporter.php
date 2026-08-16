<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;
use Illuminate\Support\Facades\DB;

/**
 * Reads the per-call ledger into per-tier spend.
 *
 * Tokens are summed in SQL per (tier, model) and priced in PHP, because the
 * rate table lives in config and a model can be added to it without a
 * migration. Grouping by model as well as tier is what keeps that correct when
 * the configured model changes mid-window — the old calls stay priced at the
 * model that actually served them.
 */
class AuditCostReporter
{
    /**
     * @return array<string, TierSpend> keyed by tier value, every tier present
     */
    public function byTier(?int $days = null): array
    {
        $since = now()->subDays($days ?? (int) config('audit.cost_window_days'));

        // Query builder rather than Eloquent: these rows are aggregates, not
        // AuditAiCall models, and dressing them as models would invite code
        // downstream to treat a summed row as a single call.
        $tokens = DB::table('audit_ai_calls')
            ->join('audit_requests', 'audit_requests.id', '=', 'audit_ai_calls.audit_request_id')
            ->where('audit_ai_calls.created_at', '>=', $since)
            ->groupBy('audit_requests.tier', 'audit_ai_calls.model')
            ->selectRaw('audit_requests.tier as tier, audit_ai_calls.model as model')
            ->selectRaw('COALESCE(SUM(audit_ai_calls.input_tokens), 0) as in_tokens')
            ->selectRaw('COALESCE(SUM(audit_ai_calls.output_tokens), 0) as out_tokens')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(CASE WHEN audit_ai_calls.input_tokens IS NULL'
                .' OR audit_ai_calls.output_tokens IS NULL THEN 1 ELSE 0 END) as unsized')
            ->get();

        // A re-run deletes its predecessor's report row, so this counts reports
        // that currently exist rather than every delivery ever made. Spend and
        // delivery can also straddle the window edge (a run billed late on one
        // day can deliver on the next), which over a 30-day window is noise.
        //
        // Counted only where the ledger holds a call for that request in the
        // window. Reports that predate the ledger — or seeded demo data — carry
        // no cost, and dividing real spend by a denominator padded with free
        // reports understates cost per report exactly when the figure is most
        // load-bearing: the first weeks after this shipped.
        $reports = DB::table('audit_reports')
            ->join('audit_requests', 'audit_requests.id', '=', 'audit_reports.audit_request_id')
            ->where('audit_reports.created_at', '>=', $since)
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('audit_ai_calls')
                ->whereColumn('audit_ai_calls.audit_request_id', 'audit_requests.id')
                ->where('audit_ai_calls.created_at', '>=', $since))
            ->groupBy('audit_requests.tier')
            ->selectRaw('audit_requests.tier as tier, COUNT(*) as aggregate')
            ->pluck('aggregate', 'tier');

        $spend = [];
        $calls = [];
        $unsized = [];

        foreach ($tokens as $row) {
            $rates = config('audit.model_pricing.'.$row->model);
            $tier = (string) $row->tier;

            $calls[$tier] = ($calls[$tier] ?? 0) + (int) $row->calls;
            $spend[$tier] ??= 0.0;

            if (is_array($rates)) {
                $spend[$tier] += ((int) $row->in_tokens * (float) $rates['input']
                    + (int) $row->out_tokens * (float) $rates['output']) / 1_000_000;
                $unsized[$tier] = ($unsized[$tier] ?? 0) + (int) $row->unsized;

                continue;
            }

            // Unpriced model: every one of its calls is unsized, including the
            // ones that returned a token count.
            $unsized[$tier] = ($unsized[$tier] ?? 0) + (int) $row->calls;
        }

        $byTier = [];

        foreach (AuditTier::cases() as $tier) {
            $byTier[$tier->value] = new TierSpend(
                tier: $tier,
                spendUsd: $spend[$tier->value] ?? 0.0,
                calls: $calls[$tier->value] ?? 0,
                unsizedCalls: $unsized[$tier->value] ?? 0,
                reports: (int) ($reports[$tier->value] ?? 0),
            );
        }

        return $byTier;
    }
}
