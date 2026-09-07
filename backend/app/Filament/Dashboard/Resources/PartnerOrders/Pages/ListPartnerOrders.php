<?php

namespace App\Filament\Dashboard\Resources\PartnerOrders\Pages;

use App\Constants\OrderStatus;
use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPartnerOrders extends ListRecords
{
    protected static string $resource = PartnerOrderResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make(__('Pending cash'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::PENDING->value)->where('is_local', true)),
            'all' => Tab::make(__('All')),
            'approved' => Tab::make(__('Approved'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::SUCCESS->value)),
            'rejected' => Tab::make(__('Rejected'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::REJECTED->value)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
