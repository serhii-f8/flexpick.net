<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A bar chart rather than a stat list: one Stat per plan produced a grid that
 * went ragged the moment the plan count was not a multiple of the column count.
 * A chart handles one plan or nine identically.
 */
class AuditsByPlanWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 11;

    protected ?string $pollingInterval = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('Audits by plan');
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->countsByPlan()->isEmpty()
            ? __('No audits in the selected period.')
            : __('Audit volume grouped by the submitter\'s active plan.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $byPlan = $this->countsByPlan();

        return [
            'datasets' => [
                [
                    'label' => __('Audits'),
                    'data' => $byPlan->values()->all(),
                ],
            ],
            'labels' => $byPlan->keys()->all(),
        ];
    }

    /**
     * @return Collection<int|string, int<0, max>>
     */
    private function countsByPlan(): Collection
    {
        $startDate = $this->pageFilters['start_date'] ?? null;
        $endDate = $this->pageFilters['end_date'] ?? null;

        return AuditRequest::query()
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay()))
            ->when($endDate, fn ($query) => $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay()))
            ->with(['user.subscriptions' => fn ($query) => $query
                ->where('status', SubscriptionStatus::ACTIVE->value)
                ->where('ends_at', '>', now())
                ->with('plan'), ])
            ->get()
            ->groupBy(function (AuditRequest $audit): string {
                return $audit->user?->subscriptions->first()?->plan?->name ?? __('Free / no plan');
            })
            ->map->count()
            ->sortDesc();
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
