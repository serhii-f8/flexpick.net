<?php

namespace App\Livewire\Filament\Dashboard;

use App\Exceptions\PartnerOfferingValidationException;
use App\Filament\Dashboard\Pages\PartnerPricingSettings;
use App\Models\OneTimeProduct;
use App\Services\CurrencyService;
use App\Services\PartnerCatalogService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PartnerProductPricingTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    /**
     * canAccess() is only checked once, at page load, by the parent
     * PartnerPricingSettings page. A Partner Plan can lapse -- or the
     * catalog permission can be revoked -- between rendering this table and
     * interacting with it, so it's re-checked here too (same rationale as
     * OrderApprovalService::assertPartnerMayAct() re-checking partner status
     * live rather than trusting the queue's render-time state).
     */
    public function mount(): void
    {
        abort_unless(PartnerPricingSettings::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => OneTimeProduct::query()
                ->where('is_visible', true)
                ->where('is_active', true)
                ->whereHas('prices', fn (Builder $query): Builder => $query->where('currency_id', app(CurrencyService::class)->getCurrency()->id)))
            ->columns([
                TextColumn::make('name')->label(__('Report')),
                TextColumn::make('platform_price')
                    ->label(__('Platform price'))
                    ->getStateUsing(fn (OneTimeProduct $record): string => $this->money($this->catalog()->productBasePrice($record))),
                TextColumn::make('your_price')
                    ->label(__('Your price'))
                    ->getStateUsing(function (OneTimeProduct $record): string {
                        $offering = $this->catalog()->productOfferingFor(Filament::getTenant(), $record);

                        return $offering === null ? __('Not set') : $this->money((int) $offering->price);
                    }),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(function (OneTimeProduct $record): string {
                        $offering = $this->catalog()->productOfferingFor(Filament::getTenant(), $record);

                        return $offering === null ? '—' : $this->money((int) $offering->price - $this->catalog()->productBasePrice($record));
                    }),
                TextColumn::make('offering_status')
                    ->label(__('Status'))
                    ->badge()
                    ->getStateUsing(function (OneTimeProduct $record): string {
                        $offering = $this->catalog()->productOfferingFor(Filament::getTenant(), $record);

                        return match (true) {
                            $offering === null, ! $offering->is_enabled => __('Disabled'),
                            $this->catalog()->isProductOfferingBelowMinimum($offering) => __('Below minimum'),
                            default => __('Enabled'),
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        __('Enabled') => 'success',
                        __('Below minimum') => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('set-price')
                    ->label(__('Set price'))
                    ->icon('heroicon-m-pencil-square')
                    ->schema(fn (OneTimeProduct $record): array => [
                        TextInput::make('price')
                            ->label(__('Your price'))
                            ->prefix('$')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->required()
                            ->default($this->catalog()->suggestedProductPrice(Filament::getTenant(), $record) / 100)
                            ->helperText(__('Platform price: :price. Your price cannot go below it.', ['price' => $this->money($this->catalog()->productBasePrice($record))])),
                        Toggle::make('is_enabled')
                            ->label(__('Resell this report'))
                            ->default($this->catalog()->productOfferingFor(Filament::getTenant(), $record)?->is_enabled ?? true),
                    ])
                    ->action(function (array $data, OneTimeProduct $record): void {
                        if (! PartnerPricingSettings::canAccess()) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body(__('Your reseller access is no longer active.'))->persistent()->send();

                            return;
                        }

                        try {
                            $this->catalog()->setProductOffering(
                                Filament::getTenant(),
                                $record,
                                (int) round(((float) $data['price']) * 100),
                                [],
                                (bool) $data['is_enabled'],
                            );
                        } catch (PartnerOfferingValidationException $e) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Price saved'))->send();
                    }),
            ]);
    }

    public function render(): View
    {
        return view('livewire.filament.dashboard.partner-pricing-table');
    }

    private function catalog(): PartnerCatalogService
    {
        return app(PartnerCatalogService::class);
    }

    private function money(int $cents): string
    {
        return (string) money($cents, app(CurrencyService::class)->getCurrency()->code);
    }
}
