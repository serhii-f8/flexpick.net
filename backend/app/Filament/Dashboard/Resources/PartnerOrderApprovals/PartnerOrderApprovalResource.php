<?php

namespace App\Filament\Dashboard\Resources\PartnerOrderApprovals;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages\ListPartnerOrderApprovals;
use App\Models\Order;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class PartnerOrderApprovalResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /**
     * Distinct slug: the customer-facing OrderResource already owns the Order
     * model in this panel, and two resources on one model collide on routes.
     */
    protected static ?string $slug = 'partner-order-approvals';

    /**
     * Scoped on partner_tenant_id (who sells it), not tenant_id (who bought
     * it), so Filament's automatic tenant scoping must stay out of the way.
     */
    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Order Approval');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Order Approvals');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('partner_tenant_id', Filament::getTenant()?->getKey() ?? 0)
            ->where('status', OrderStatus::PENDING->value);
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
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
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
                TextColumn::make('user.email')->label(__('Customer'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('base_price_snapshot')
                    ->label(__('Base Price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney($record->base_price_snapshot)),
                TextColumn::make('total_amount')
                    ->label(__('Your Price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(function (Order $record): string {
                        if ($record->base_price_snapshot === null) {
                            return '—';
                        }

                        return self::formatMoney(self::amountDue($record) - (int) $record->base_price_snapshot);
                    }),
                TextColumn::make('quota_snapshot')
                    ->label(__('Quotas'))
                    ->getStateUsing(function (Order $record): string {
                        $quotas = (array) ($record->quota_snapshot ?? []);

                        if ($quotas === []) {
                            return '—';
                        }

                        return collect($quotas)
                            ->map(fn ($value, string $key): string => $key.': '.$value)
                            ->implode(', ');
                    })
                    ->wrap(),
                TextColumn::make('created_at')->label(__('Created At'))->dateTime(config('app.datetime_format'))->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm you have received the cash for this order. This activates the customer immediately.'))
                    ->schema([
                        Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                    ])
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: true)),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                    ])
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: false)),
            ])
            ->defaultSort('created_at', 'asc');
    }

    private static function decide(Order $order, array $data, bool $approve): void
    {
        $service = app(OrderApprovalService::class);
        $note = $data['note'] ?? null;

        try {
            $changed = $approve
                ? $service->approveAsPartner($order, auth()->user(), Filament::getTenant(), $note)
                : $service->rejectAsPartner($order, auth()->user(), Filament::getTenant(), $note);
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title(__('Not allowed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        if (! $changed) {
            Notification::make()->warning()->title(__('This order is no longer pending.'))->send();

            return;
        }

        Notification::make()->success()->title($approve ? __('Order approved') : __('Order rejected'))->send();
    }

    private static function amountDue(Order $order): int
    {
        // One amount-due rule for the whole feature — see the note on
        // OrderApprovalService::amountDue().
        return app(OrderApprovalService::class)->amountDue($order);
    }

    private static function formatMoney(?int $amount): string
    {
        return money((int) $amount, app(CurrencyService::class)->getCurrency()->code);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerOrderApprovals::route('/'),
        ];
    }
}
