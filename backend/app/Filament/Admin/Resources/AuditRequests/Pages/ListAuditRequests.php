<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditRequests extends ListRecords
{
    use ListDefaults;

    protected static string $resource = AuditRequestResource::class;

    /**
     * Tab keys are load-bearing: AuditAdminStatsWidget's tiles link to
     * ?activeTab=<key>, so renaming one silently breaks a drill-down.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),

            'needs-action' => Tab::make(__('Needs action'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    // @phpstan-ignore-next-line method.notFound (needsManualAction is AuditRequest's own scope; Larastan can't see it through the closure's generic Builder<Model> parameter)
                    ->needsManualAction())
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()
                    // @phpstan-ignore-next-line method.notFound (needsManualAction is AuditRequest's own scope; Larastan can't see it through the resource's generic Builder<Model> return type)
                    ->needsManualAction()->count())
                ->badgeColor('warning')
                ->deferBadge(),

            'failed' => Tab::make(__('Failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditRequestStatus::FAILED->value))
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->where('status', AuditRequestStatus::FAILED->value)->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'stuck' => Tab::make(__('Stuck'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    // @phpstan-ignore-next-line method.notFound (stuck is AuditRequest's own scope; Larastan can't see it through the closure's generic Builder<Model> parameter)
                    ->stuck())
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()
                    // @phpstan-ignore-next-line method.notFound (stuck is AuditRequest's own scope; Larastan can't see it through the resource's generic Builder<Model> return type)
                    ->stuck()->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'expert-review' => Tab::make(__('Expert review'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditRequestStatus::EXPERT_REVIEW->value))
                ->badge(fn (): int => AuditRequestResource::getEloquentQuery()->where('status', AuditRequestStatus::EXPERT_REVIEW->value)->count())
                ->badgeColor('warning')
                ->deferBadge(),
        ];
    }
}
