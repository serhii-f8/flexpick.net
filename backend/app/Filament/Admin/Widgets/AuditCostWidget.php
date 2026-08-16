<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AuditReport\AuditCostReporter;
use App\Services\AuditReport\TierSpend;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

/**
 * What each tier actually costs to run, from the per-call ledger.
 *
 * Deliberately not date-filtered by the dashboard range: this answers a unit-
 * economics question ("what does a deep review cost us?"), which needs a window
 * long enough to average over, not whatever range someone left on the revenue
 * charts.
 *
 * Every figure is USD at the provider's LIST price. That is the right basis for
 * margin, and it is not an invoice — negotiated rates and taxes are not in it.
 */
class AuditCostWidget extends BaseWidget
{
    /** Directly under the audit operations block. */
    protected static ?int $sort = 11;

    protected function getHeading(): ?string
    {
        return __('AI cost per audit');
    }

    protected function getDescription(): ?string
    {
        return __('List price, last :days days', [
            'days' => (int) config('audit.cost_window_days'),
        ]);
    }

    protected function getStats(): array
    {
        // Tolerate the table being absent so the dashboard still renders on a
        // partial schema, exactly as the operations block does.
        if (! Schema::hasTable('audit_ai_calls')) {
            return [];
        }

        $byTier = app(AuditCostReporter::class)->byTier();

        $stats = [$this->totalStat($byTier)];

        foreach ($byTier as $spend) {
            $stats[] = $this->tierStat($spend);
        }

        return $stats;
    }

    /** @param array<string, TierSpend> $byTier */
    private function totalStat(array $byTier): Stat
    {
        $spend = array_sum(array_map(fn (TierSpend $s): float => $s->spendUsd, $byTier));
        $calls = array_sum(array_map(fn (TierSpend $s): int => $s->calls, $byTier));
        $unsized = array_sum(array_map(fn (TierSpend $s): int => $s->unsizedCalls, $byTier));

        $stat = Stat::make(__('AI spend'), $this->money($spend))
            ->description(trans_choice('{0}no calls yet|{1}:count model call|[2,*]:count model calls', $calls, [
                'count' => $calls,
            ]));

        if ($unsized > 0) {
            // Surfaced rather than absorbed: the total above excludes these, so
            // it is a floor, not the bill.
            return $stat
                ->color('warning')
                ->icon('heroicon-m-exclamation-triangle')
                ->description(trans_choice(
                    '{1}:count call of unknown cost — total is a floor'
                        .'|[2,*]:count calls of unknown cost — total is a floor',
                    $unsized,
                    ['count' => $unsized],
                ))
                ->descriptionColor('warning');
        }

        return $stat;
    }

    private function tierStat(TierSpend $spend): Stat
    {
        $perReport = $spend->costPerReport();

        // No delivered report means no per-report figure to show. Spend without
        // delivery is still worth seeing — that is what a failing tier looks
        // like — so it moves into the description rather than vanishing.
        $stat = Stat::make(
            $spend->tier->label(),
            $perReport === null ? '—' : $this->money($perReport),
        );

        if ($perReport === null) {
            return $stat
                ->color($spend->spendUsd > 0 ? 'danger' : 'gray')
                ->description($spend->spendUsd > 0
                    ? __(':spend spent, nothing delivered', ['spend' => $this->money($spend->spendUsd)])
                    : __('No runs in window'));
        }

        return $stat
            ->color($spend->isComplete() ? $spend->tier->badgeColor() : 'warning')
            ->description(__(':reports delivered · :spend total', [
                'reports' => $spend->reports,
                'spend' => $this->money($spend->spendUsd),
            ]));
    }

    /**
     * Sub-cent precision below a dollar: the diagnostic tier lives at $0.19, and
     * rounding it to "$0" would make the free tier look free to run.
     */
    private function money(float $usd): string
    {
        return $usd > 0 && $usd < 1
            ? '$'.number_format($usd, 3)
            : '$'.number_format($usd, 2);
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
