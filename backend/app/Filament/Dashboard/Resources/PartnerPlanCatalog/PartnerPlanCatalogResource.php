<?php

namespace App\Filament\Dashboard\Resources\PartnerPlanCatalog;

use App\Constants\TenancyPermissionConstants;
use App\Exceptions\PartnerOfferingValidationException;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\Pages\ListPartnerPlanCatalog;
use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\PartnerCatalogService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerPlanCatalogResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    /**
     * Plan is a global catalog model, not tenant-owned data — it has no
     * "tenant" relationship for Filament's automatic tenant-scoping to use.
     * Access is still tenant-gated via canAccess(); the per-tenant data
     * (PartnerPlanOffering) is looked up explicitly against the current
     * tenant in offeringFor() and the configure action.
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_visible', true);
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if ($tenant === null || ! app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            auth()->user(),
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Plan')),
                TextColumn::make('product.name')->label(__('Product')),
                TextColumn::make('partner_price')
                    ->label(__('Your Price'))
                    ->getStateUsing(function (Plan $record): string {
                        $offering = self::offeringFor($record);

                        return $offering === null
                            ? __('Not configured')
                            : money($offering->price, app(CurrencyService::class)->getCurrency()->code);
                    }),
                TextColumn::make('partner_enabled')
                    ->label(__('Enabled'))
                    ->getStateUsing(fn (Plan $record): string => self::offeringFor($record)?->is_enabled ? __('Yes') : __('No')),
            ])
            ->recordActions([
                Action::make('configure')
                    ->label(__('Configure'))
                    ->schema(function (Plan $record): array {
                        $offering = self::offeringFor($record);
                        $allowedKeys = (array) ($record->product->reseller_quota_keys ?? []);

                        return [
                            TextInput::make('price')
                                ->label(__('Your Price (in cents)'))
                                ->numeric()
                                ->default($offering->price ?? null)
                                ->required(),
                            ...array_map(
                                fn (string $key) => TextInput::make("quota_overrides.{$key}")
                                    ->label($key)
                                    ->numeric()
                                    ->default(data_get($offering->quota_overrides ?? [], $key)),
                                $allowedKeys,
                            ),
                            Toggle::make('is_enabled')
                                ->label(__('Resell this plan'))
                                ->default($offering->is_enabled ?? false),
                        ];
                    })
                    ->action(function (array $data, Plan $record, PartnerCatalogService $catalogService): void {
                        try {
                            $catalogService->setPlanOffering(
                                Filament::getTenant(),
                                $record,
                                (int) $data['price'],
                                (array) ($data['quota_overrides'] ?? []),
                                (bool) $data['is_enabled'],
                            );
                        } catch (PartnerOfferingValidationException $e) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Offering saved'))->send();
                    }),
            ]);
    }

    private static function offeringFor(Plan $plan): ?PartnerPlanOffering
    {
        return PartnerPlanOffering::where('tenant_id', Filament::getTenant()->id)
            ->where('plan_id', $plan->id)
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerPlanCatalog::route('/'),
        ];
    }
}
