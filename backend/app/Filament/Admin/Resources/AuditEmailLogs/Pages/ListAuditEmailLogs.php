<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Widgets\AuditEmailHealthWidget;
use App\Models\AuditEmailLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            AuditEmailHealthWidget::class,
        ];
    }

    /**
     * The 'failed-24h' key is load-bearing: AuditAdminStatsWidget's email tile
     * links to ?activeTab=failed-24h.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),

            'failed-24h' => Tab::make(__('Failed (24h)'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    // @phpstan-ignore-next-line method.notFound (failedWithin is AuditEmailLog's own scope; Larastan can't see it through the closure's generic Builder<Model> parameter)
                    ->failedWithin())
                ->badge(fn (): int => AuditEmailLog::query()->failedWithin()->count())
                ->badgeColor('danger')
                ->deferBadge(),

            'bounced' => Tab::make(__('Bounced'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditEmailLog::STATUS_BOUNCED)),

            'pending' => Tab::make(__('Pending'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AuditEmailLog::STATUS_PENDING)),
        ];
    }
}
