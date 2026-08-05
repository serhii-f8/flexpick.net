<?php

namespace App\Filament\Admin\Resources\ExpertReviews;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview;
use App\Filament\Admin\Resources\ExpertReviews\Pages\ListExpertReviews;
use App\Models\AuditRequest;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpertReviewResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Expert review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expert reviews');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tier', AuditTier::EXPERT->value)
            ->where('status', AuditRequestStatus::EXPERT_REVIEW->value);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermissionTo('review expert audits') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('repo_url')->label(__('Repository'))->limit(50)->searchable(),
                TextColumn::make('name')->label(__('Customer'))->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('created_at')->label(__('Submitted'))->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('analysis_completed_at')->label(__('Awaiting since'))->since()->sortable(),
            ])
            ->defaultSort('analysis_completed_at', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpertReviews::route('/'),
            'edit' => EditExpertReview::route('/{record}/edit'),
        ];
    }
}
