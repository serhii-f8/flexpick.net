<?php

namespace App\Filament\Dashboard\Resources\PartnerOrders;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ListPartnerOrders;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ViewPartnerOrder;
use App\Mapper\OrderStatusMapper;
use App\Models\Order;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every order placed by the customers this partner referred (spec §8): the
 * partner is responsible for them, confirms their cash, and can see their
 * gateway purchases too. Approval itself stays in OrderApprovalService.
 */
class PartnerOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /** Distinct from the customer-facing OrderResource, which owns this model's default slug. */
    protected static ?string $slug = 'partner-orders';

    protected static ?int $navigationSort = 2;

    /** Scoped on who referred the buyer, not on tenant_id (who bought). */
    protected static bool $isScopedToTenant = false;

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Orders');
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    /**
     * Same style as ReferralResource's badge: the count for the tab a
     * partner actually needs to act on (the "Pending cash" default tab),
     * scoped through the same tenant-attribution query as the list itself.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()
            ->where('status', OrderStatus::PENDING->value)
            ->where('is_local', true)
            ->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->getKey() ?? 0;

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($tenantId): void {
                // The second arm is bounded by attribution time: whereHas
                // compiles to a correlated whereExists subquery against
                // `users`, so `orders.created_at` here still refers to the
                // outer query's row (same pattern as
                // AuditCostReporter::totals()'s whereColumn inside
                // whereExists). Without the bound, a buyer's attribution
                // today would retroactively expose every order they ever
                // placed, long before the partner ever referred them.
                $query->where('orders.partner_tenant_id', $tenantId)
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('users.partner_tenant_id', $tenantId)
                        ->whereColumn('users.partner_attributed_at', '<=', 'orders.created_at'));
            })
            ->with(['user', 'currency', 'paymentProvider', 'partnerTenant']);
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            $user,
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

    /**
     * The model policy gates on PERMISSION_VIEW_ORDERS against the buyer's
     * own tenant -- a permission a partner never holds there, so the default
     * policy-backed canView() would 403 a partner viewing their own referred
     * order. Re-derive from canAccess() instead of hardcoding true: this
     * resource's authorization model is scoped by tenant, not by individual
     * order attributes, so canAccess() (tenant is an active partner + holds
     * PERMISSION_MANAGE_PARTNER_ORDERS) is the correct per-call check here --
     * it re-checks on every call rather than assuming getEloquentQuery()'s
     * scoping alone is enough, the same defense-in-depth rationale behind
     * ExpertReviewResource::canView() re-deriving canViewAny().
     */
    public static function canView($record): bool
    {
        return self::canAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label(__('Customer'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('item')
                    ->label(__('Item'))
                    ->getStateUsing(fn (Order $record): string => self::itemName($record)),
                TextColumn::make('payment')
                    ->label(__('Payment'))
                    ->getStateUsing(fn (Order $record): string => $record->is_local ? __('Cash') : (optional($record->paymentProvider)->name ?? '—')),
                TextColumn::make('base_price_snapshot')
                    ->label(__('Base price'))
                    ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null
                        ? self::formatMoney((int) $record->base_price_snapshot)
                        : '—'),
                TextColumn::make('total_amount')
                    ->label(__('Your price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null
                        ? self::formatMoney(self::amountDue($record) - (int) $record->base_price_snapshot)
                        : '—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (Order $record, OrderStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, OrderStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(config('app.datetime_format'))->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm you have received the cash for this order. This activates the customer immediately.'))
                    ->schema([Textarea::make('note')->label(__('Internal note'))->maxLength(1000)])
                    ->visible(fn (Order $record): bool => self::canDecide($record))
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: true)),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('note')->label(__('Internal note'))->maxLength(1000)])
                    ->visible(fn (Order $record): bool => self::canDecide($record))
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: false)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Order'))
                ->schema([
                    TextEntry::make('uuid')->label('ID')->copyable(),
                    TextEntry::make('user.email')->label(__('Customer')),
                    TextEntry::make('type')->label(__('Type'))->badge(),
                    TextEntry::make('payment')->label(__('Payment'))
                        ->getStateUsing(fn (Order $record): string => $record->is_local ? __('Cash') : (optional($record->paymentProvider)->name ?? '—')),
                    TextEntry::make('base_price_snapshot')->label(__('Base price'))
                        ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null ? self::formatMoney((int) $record->base_price_snapshot) : '—'),
                    TextEntry::make('total_amount')->label(__('Your price'))
                        ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                    TextEntry::make('status')->label(__('Status'))->badge()
                        ->color(fn (Order $record, OrderStatusMapper $mapper): string => $mapper->mapColor($record->status))
                        ->formatStateUsing(fn (string $state, OrderStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                    TextEntry::make('created_at')->label(__('Created'))->dateTime(config('app.datetime_format')),
                ])->columns(3),
            Section::make(__('Items'))
                ->schema(fn (Order $record): array => OrderResource::orderItems($record))
                ->visible(fn (Order $record): bool => $record->items()->exists()),
        ]);
    }

    private static function itemName(Order $record): string
    {
        // optional() rather than ?-> throughout: Larastan resolves a relation's
        // magic property (e.g. ->oneTimeProduct, ->plan) via ->, but not via a
        // nullsafe fetch, on every hop of the chain -- not just the last one.
        $oneTimeProductName = optional($record->items()->first())->oneTimeProduct?->name;
        $planProductName = optional(optional($record->subscription)->plan)->product?->name;

        return $oneTimeProductName ?? $planProductName ?? '—';
    }

    private static function ownsCashOrder(Order $record): bool
    {
        return (bool) $record->is_local && (int) $record->partner_tenant_id === (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    private static function canDecide(Order $record): bool
    {
        return self::ownsCashOrder($record) && app(OrderApprovalService::class)->isPendingCashOrder($record);
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
        return app(OrderApprovalService::class)->amountDue($order);
    }

    private static function formatMoney(int $amount): string
    {
        return money($amount, app(CurrencyService::class)->getCurrency()->code);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerOrders::route('/'),
            'view' => ViewPartnerOrder::route('/{record}'),
        ];
    }
}
